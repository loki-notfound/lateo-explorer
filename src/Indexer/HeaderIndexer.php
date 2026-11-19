<?php

declare(strict_types=1);

namespace Lateo\Explorer\Indexer;

use Lateo\Explorer\Config\Limits;
use Lateo\Explorer\Db\ChainStore;
use Lateo\Explorer\Rpc\ErrorCategory;
use Lateo\Explorer\Rpc\RpcClient;
use Lateo\Explorer\Rpc\RpcException;
use Lateo\Explorer\Rpc\RpcMethod;
use Lateo\Explorer\Rpc\RpcResult;
use Lateo\Explorer\Support\Clock;

/**
 * Keeps blocks and pricing records up to date from the nodes' block headers.
 *
 * One run:
 * - starts after the highest stored block (or at $startHeight when the database is empty);
 * - asks every own node for its tip and takes the answer with the most cumulative difficulty
 *   (the fallback only when no own node answers); the ranges are asked first from the node
 *   that gave the tip;
 * - fetches get_block_headers_range in batches of header_batch, never past the tip, and
 *   validates each batch completely before it stores anything: the count, consecutive
 *   heights, every prev_hash equal to the hash before it, the first one equal to the stored
 *   hash, and the type and format of every stored field;
 * - while more than BACKFILL_BEHIND blocks behind (backfill mode) asks the own nodes only and
 *   pauses backfill_pause_seconds between calls; closer to the tip (tip mode) the fallback may
 *   answer too;
 * - when a node disagrees with the stored chain (a broken link, or another hash at the tip)
 *   it rolls back only if that node's tip has more cumulative difficulty than the stored top,
 *   as the daemon follows the chain with the most work; a node on a branch with less work ends
 *   the run with an error and changes nothing. A rollback walks back on that node to the
 *   highest block both sides know (the fork point), at most reorg_max_depth deep, and undoes
 *   the chain above it in one transaction (ChainStore::rollbackAbove);
 * - stops between batches when run_seconds is used up.
 *
 * $forcedMode is for a bounded sample run from the public node only (the production indexer
 * never sets it): it fixes the mode whatever the distance to the tip.
 *
 * Data of different nodes is only ever combined through the hash links: a batch is stored
 * only when it is consecutive in itself and its first block links to the stored top.
 */
final class HeaderIndexer
{
    /** More than this many blocks behind the tip is backfill mode. */
    public const BACKFILL_BEHIND = 100;
    /** Headers per call while looking for the fork point. */
    public const FORK_WINDOW = 50;
    /** Reorganisations one run handles before it gives up. */
    public const MAX_REORGS_PER_RUN = 3;

    private float $startedAt = 0.0;
    private ?RunMode $mode = null;
    private ?int $from = null;
    private ?int $to = null;
    private int $headers = 0;
    private ?string $node = null;
    private ?Header $tip = null;
    private ?string $tipNode = null;
    private int $reorgs = 0;
    private string $outcome = RunSummary::COMPLETE;

    public function __construct(
        private readonly RpcClient $client,
        private readonly ChainStore $store,
        private readonly Limits $limits,
        private readonly Clock $clock,
        /** Where an empty database starts (a test run); heights before it are not indexed. */
        private readonly int $startHeight = 0,
        private readonly ?RunMode $forcedMode = null,
    ) {
        if ($startHeight < 0) {
            throw new \InvalidArgumentException('start height is negative');
        }
    }

    /** Run once. Errors of the nodes and of the data end the run with outcome "error". */
    public function run(): RunSummary
    {
        $this->startedAt = $this->clock->now();
        $this->mode = null;
        $this->from = $this->to = $this->node = $this->tip = $this->tipNode = null;
        $this->headers = $this->reorgs = 0;
        $this->outcome = RunSummary::COMPLETE;
        $attemptsBefore = $this->client->attempts();
        $note = '';

        try {
            $this->work();
        } catch (IndexerException $e) {
            $this->outcome = RunSummary::ERROR;
            $note = $e->getMessage();
        } catch (RpcException $e) {
            $this->outcome = RunSummary::ERROR;
            $note = 'node: ' . $e->getMessage();
        }

        return new RunSummary(
            $this->mode ?? RunMode::Tip,
            (int) floor($this->startedAt),
            (int) floor($this->clock->now()),
            $this->from,
            $this->to,
            $this->headers,
            $this->node,
            $this->outcome,
            $note,
            $this->reorgs,
            $this->client->attempts() - $attemptsBefore,
        );
    }

    private function work(): void
    {
        $tip = $this->fetchTip();
        $this->tip = $tip;

        while (true) {
            $top = $this->store->top();
            $next = $top === null ? $this->startHeight : $top['height'] + 1;
            $behind = $tip->height - $next + 1;
            $mode = $this->forcedMode ?? ($behind > self::BACKFILL_BEHIND ? RunMode::Backfill : RunMode::Tip);
            $this->mode ??= $mode;

            if ($behind <= 0) {
                // Caught up. A node on another chain shows up as a different hash at its tip.
                if ($top !== null && $this->store->hashAt($tip->height) !== null
                    && $this->store->hashAt($tip->height) !== $tip->hash) {
                    $this->handleReorg($this->tipNode ?? '', $tip->height, true, $tip);
                    continue;
                }
                return;
            }
            if ($this->clock->now() - $this->startedAt >= $this->limits->runSeconds) {
                $this->outcome = RunSummary::TIME;
                return;
            }

            $allowFallback = $mode === RunMode::Tip;
            $end = min($next + $this->limits->headerBatch - 1, $tip->height);
            $result = $this->fetchRange($next, $end, $allowFallback);
            $this->node = $result->node;
            $batch = $this->parseRange($result->data, $next, $end);

            if ($top !== null && $batch[0]->prevHash !== $top['hash']) {
                $nodeTip = $this->tipOf($result->node, $allowFallback);
                $this->handleReorg($result->node, $top['height'], $allowFallback, $nodeTip);
                continue;
            }

            $this->store->insertBatch($batch);
            $this->from ??= $next;
            $this->to = $end;
            $this->headers += count($batch);

            if ($mode === RunMode::Backfill && $end < $tip->height) {
                $this->clock->sleep($this->limits->backfillPauseSeconds);
            }
        }
    }

    /**
     * The tip: every own node is asked by its label and the answer with the most cumulative
     * difficulty wins (the first in settings order on a tie). Only when no own node answers
     * is the fallback asked. A node that cannot be reached is skipped; an answer that does
     * not parse ends the run.
     */
    private function fetchTip(): Header
    {
        $best = null;
        $last = null;
        foreach ($this->client->ownLabels() as $label) {
            try {
                $header = $this->tipFrom($label, false);
            } catch (RpcException $e) {
                $last = $e;
                continue;
            }
            if ($best === null || self::moreWork($header->cumulativeDifficulty, $best->cumulativeDifficulty)) {
                $best = $header;
                $this->tipNode = $label;
            }
        }
        if ($best === null) {
            $fallback = $this->client->fallbackLabel();
            if ($fallback === null) {
                throw $last ?? new RpcException(ErrorCategory::Missing, null, 'no node to ask');
            }
            $best = $this->tipFrom($fallback, true);
            $this->tipNode = $fallback;
        }
        $this->node = $this->tipNode;
        return $best;
    }

    /** @throws RpcException */
    private function tipFrom(string $label, bool $allowFallback): Header
    {
        $result = $this->client->call(RpcMethod::GetLastBlockHeader, [], $allowFallback, $label);
        return HeaderParser::parse($result->data['block_header'] ?? null);
    }

    /** The tip of the node $label: the one of this run when it is the node that gave the tip. */
    private function tipOf(string $label, bool $allowFallback): Header
    {
        if ($label === $this->tipNode && $this->tip !== null) {
            return $this->tip;
        }
        return $this->tipFrom($label, $allowFallback);
    }

    /**
     * A range from the node that gave the tip; when that node fails, in the usual order (own
     * nodes, and the fallback only where $allowFallback).
     */
    private function fetchRange(int $start, int $end, bool $allowFallback): RpcResult
    {
        $params = ['start_height' => $start, 'end_height' => $end];
        if ($this->tipNode !== null && ($allowFallback || $this->tipNode !== $this->client->fallbackLabel())) {
            try {
                return $this->client->call(RpcMethod::GetBlockHeadersRange, $params, $allowFallback, $this->tipNode);
            } catch (RpcException) {
                // Fall through to the usual order.
            }
        }
        return $this->client->call(RpcMethod::GetBlockHeadersRange, $params, $allowFallback);
    }

    /** Whether the decimal text $a is greater than $b. */
    private static function moreWork(string $a, string $b): bool
    {
        return gmp_cmp($a, $b) > 0;
    }

    /**
     * Fetch headers of one range and validate them as a batch.
     *
     * @param array<string,mixed> $data
     * @return non-empty-list<Header>
     */
    private function parseRange(array $data, int $start, int $end): array
    {
        $raw = $data['headers'] ?? null;
        if (!is_array($raw) || !array_is_list($raw)) {
            throw new IndexerException(FailureKind::InvalidData, 'headers is not a list');
        }
        $expected = $end - $start + 1;
        if (count($raw) !== $expected) {
            throw new IndexerException(FailureKind::InvalidData, 'a node sent ' . count($raw) . ' headers, not ' . $expected);
        }
        $headers = [];
        foreach ($raw as $i => $item) {
            $h = HeaderParser::parse($item);
            if ($h->height !== $start + $i) {
                throw new IndexerException(FailureKind::InvalidData, 'heights are not consecutive');
            }
            if ($i > 0 && $h->prevHash !== $headers[$i - 1]->hash) {
                throw new IndexerException(FailureKind::InvalidData, 'a header does not link to the one before it');
            }
            $headers[] = $h;
        }
        /** @var non-empty-list<Header> $headers */
        return $headers;
    }

    /**
     * Undo the stored chain above the fork point found on $label (the node that disagrees),
     * but only when that node's tip ($nodeTip) has more cumulative difficulty than the stored
     * top. Otherwise nothing changes and the run ends with an error.
     *
     * @param int $upper the highest stored height that may still be on the common chain
     */
    private function handleReorg(string $label, int $upper, bool $allowFallback, Header $nodeTip): void
    {
        $top = $this->store->top();
        $work = $this->store->topWork();
        if ($top === null || $work === null) {
            return;
        }
        if (!self::moreWork($nodeTip->cumulativeDifficulty, $work)) {
            throw new IndexerException(
                FailureKind::LessWork,
                'node ' . $label . ' is on a branch with less work than the stored chain; nothing was changed',
            );
        }
        if (++$this->reorgs > self::MAX_REORGS_PER_RUN) {
            throw new IndexerException(FailureKind::Unstable, 'the chain changed again and again during one run');
        }
        $fork = $this->findFork($label, min($upper, $top['height']), $top['height'], $allowFallback);
        $this->store->rollbackAbove($fork, $label, (int) floor($this->clock->now()));
    }

    /**
     * The highest height at or below $upper where the node's hash equals the stored one,
     * looked for downwards in windows of FORK_WINDOW headers on that node, not deeper than
     * reorg_max_depth below the stored top.
     */
    private function findFork(string $label, int $upper, int $storedTop, bool $allowFallback): int
    {
        $lowest = $this->store->lowestHeight() ?? 0;
        $floor = max($lowest, $storedTop - $this->limits->reorgMaxDepth);
        $window = min(self::FORK_WINDOW, $this->limits->headerBatch);

        for ($hi = $upper; $hi >= $floor; $hi = $lo - 1) {
            $lo = max($floor, $hi - $window + 1);
            $result = $this->client->call(
                RpcMethod::GetBlockHeadersRange,
                ['start_height' => $lo, 'end_height' => $hi],
                $allowFallback,
                $label,
            );
            $this->node = $result->node;
            $batch = $this->parseRange($result->data, $lo, $hi);
            $stored = $this->store->hashesBetween($lo, $hi);
            for ($i = count($batch) - 1; $i >= 0; $i--) {
                $h = $batch[$i];
                if (isset($stored[$h->height]) && $stored[$h->height] === $h->hash) {
                    return $h->height;
                }
            }
        }
        throw new IndexerException(
            FailureKind::ReorgTooDeep,
            'no common block within ' . $this->limits->reorgMaxDepth . ' blocks of the stored top',
        );
    }
}
