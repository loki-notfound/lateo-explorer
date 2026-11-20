<?php

declare(strict_types=1);

namespace Lateo\Explorer\Indexer;

use Lateo\Explorer\Config\Limits;
use Lateo\Explorer\Db\ChainStore;
use Lateo\Explorer\Db\TransactionStore;
use Lateo\Explorer\Rpc\RpcClient;
use Lateo\Explorer\Rpc\RpcException;
use Lateo\Explorer\Rpc\RpcMethod;
use Lateo\Explorer\Support\Clock;

/**
 * Stores the transactions of the blocks the header indexer has stored.
 *
 * It only works when the settings switch it on (limits.tx_backfill_enabled) and only after a
 * header run that did not end in an error and was a tip run; IndexerApplication decides that.
 * It asks the own nodes only (the fallback is never asked, whatever the call), one node for
 * the whole of a group of blocks, and pauses tx_backfill_pause_seconds between two calls.
 *
 * One run, from the block after the mark (meta tx_indexed_height) up to the stored top:
 * - a block with num_txes 0 needs no call; it only moves the mark;
 * - for any other block one get_block by height. Its block_header.hash must equal the stored
 *   hash (otherwise the run stops with a note: the header indexer handles reorganisations) and
 *   tx_hashes must hold num_txes distinct hashes of 64 lower-case hex characters;
 * - the hashes of consecutive blocks are asked together with /get_transactions (prune and
 *   decode_as_json) in calls of at most 100 hashes, which is also the daemon's limit on a
 *   restricted port. Every hash asked must come back, once, in the order asked, none missed,
 *   none in the pool, each entry's tx_hash equal to the hash asked and its block_height equal
 *   to the block's (a pool entry or another height means the chain moved: the run stops with
 *   a note);
 * - the miner transaction of each block asked for is read from the same get_block answer (its
 *   json string: version, outputs, type of the ringct signatures) and its miner_tx_hash must be
 *   the stored one; no further call is made for it;
 * - the rows of the whole blocks of a group are stored with the miner transactions of its blocks,
 *   the new mark and the conversion checks of the rows (ConversionRule, from the stored pricing
 *   records) in one
 *   transaction; never a part of a block. A mismatch does not stop the run and is not worded
 *   anywhere: it is a row in conversion_checks and a count in the log line;
 * - the run's time is the part of run_seconds the header run left; a get_block is not started
 *   once it is used up, also not after the pause before it (the /get_transactions call for
 *   what was already fetched is made, so that the work is stored).
 *
 * Wrong data from a node (a count that does not match, an entry that does not parse, an
 * entry for another hash) ends the run with an error and stores nothing of that group.
 */
final class TransactionIndexer
{
    /** Hashes per /get_transactions call: the daemon's limit on a restricted port. */
    public const MAX_HASHES = 100;
    /** Blocks read from the store at a time. */
    private const PAGE = 500;

    private int $calls = 0;
    private ?int $from = null;
    private ?int $to = null;
    private int $blocks = 0;
    private int $transactions = 0;
    /** @var array{checks:int,match:int,mismatch:int,not_recomputed:int} */
    private array $checks = ['checks' => 0, 'match' => 0, 'mismatch' => 0, 'not_recomputed' => 0];
    private string $outcome = TxSummary::COMPLETE;
    private string $note = '';

    public function __construct(
        private readonly RpcClient $client,
        private readonly ChainStore $chain,
        private readonly TransactionStore $store,
        private readonly Limits $limits,
        private readonly Clock $clock,
        /** When the indexer run began (seconds since the epoch): run_seconds counts from it. */
        private readonly float $runStartedAt,
        /** The own node to ask first (the one that gave the run's tip), or null. */
        private readonly ?string $preferredNode = null,
    ) {
    }

    public function run(): TxSummary
    {
        $attemptsBefore = $this->client->attempts();
        $this->calls = 0;
        $this->from = $this->to = null;
        $this->blocks = $this->transactions = 0;
        $this->checks = ['checks' => 0, 'match' => 0, 'mismatch' => 0, 'not_recomputed' => 0];
        $this->outcome = TxSummary::COMPLETE;
        $this->note = '';

        try {
            $this->work();
        } catch (IndexerException $e) {
            $this->outcome = TxSummary::ERROR;
            $this->note = $e->getMessage();
        } catch (RpcException $e) {
            $this->outcome = TxSummary::ERROR;
            $this->note = 'node: ' . $e->getMessage();
        }

        return new TxSummary(
            $this->outcome,
            $this->from,
            $this->to,
            $this->blocks,
            $this->transactions,
            $this->client->attempts() - $attemptsBefore,
            $this->note,
            $this->checks['checks'],
            $this->checks['match'],
            $this->checks['mismatch'],
            $this->checks['not_recomputed'],
        );
    }

    private function work(): void
    {
        $top = $this->chain->top();
        $lowest = $this->chain->lowestHeight();
        if ($top === null || $lowest === null) {
            return;
        }
        $labels = $this->labels();

        while (true) {
            $mark = $this->store->indexedHeight() ?? $lowest - 1;
            $page = $this->store->blocksAbove($mark, $top['height'], self::PAGE);
            if ($page === []) {
                return; // every stored block has its transactions
            }
            if (!$this->handle(self::group($page), $labels)) {
                return;
            }
        }
    }

    /**
     * The first blocks of $page that fit one round: blocks without transactions are free, and
     * blocks with transactions are added while their hashes together stay within MAX_HASHES
     * (a single block with more is a group of its own).
     *
     * @param non-empty-list<array{height:int,hash:string,num_txes:int,miner_tx_hash:string}> $page
     * @return non-empty-list<array{height:int,hash:string,num_txes:int,miner_tx_hash:string}>
     */
    private static function group(array $page): array
    {
        $group = [];
        $total = 0;
        foreach ($page as $block) {
            if ($block['num_txes'] > 0 && $total > 0 && $total + $block['num_txes'] > self::MAX_HASHES) {
                break;
            }
            $group[] = $block;
            $total += $block['num_txes'];
        }
        /** @var non-empty-list<array{height:int,hash:string,num_txes:int,miner_tx_hash:string}> $group */
        return $group;
    }

    /**
     * Fetch, check and store one group. Returns false when the run ends (time used up, or the
     * chain moved); the mark has then moved over what was stored.
     *
     * @param non-empty-list<array{height:int,hash:string,num_txes:int,miner_tx_hash:string}> $group
     * @param non-empty-list<string> $labels
     */
    private function handle(array $group, array $labels): bool
    {
        if (array_sum(array_column($group, 'num_txes')) === 0) {
            // Only blocks without transactions: no call, the mark moves.
            $this->record([], $group[array_key_last($group)]['height'], $group, 0);
            return true;
        }

        $last = null;
        foreach ($labels as $label) {
            try {
                $result = $this->fetchGroup($group, $label);
            } catch (RpcException $e) {
                $last = $e;
                continue;
            }
            [$rows, $done, $moved, $timeUp, $miners] = $result;
            if ($done !== []) {
                $this->record($rows, $done[array_key_last($done)]['height'], $done, count($rows), $miners);
            }
            if ($moved) {
                $this->outcome = TxSummary::STOPPED;
                return false;
            }
            if ($timeUp) {
                $this->outcome = TxSummary::TIME;
                return false;
            }
            return true;
        }
        throw $last ?? new RpcException(\Lateo\Explorer\Rpc\ErrorCategory::Missing, null, 'no node to ask');
    }

    /**
     * Ask one node for the blocks of the group and for their transactions. Nothing is stored
     * here. The blocks it covers are a prefix of the group: every block in it either has no
     * transactions or has been fetched with all of its transactions.
     *
     * @param non-empty-list<array{height:int,hash:string,num_txes:int,miner_tx_hash:string}> $group
     * @return array{0:list<Transaction>,1:list<array{height:int,hash:string,num_txes:int,miner_tx_hash:string}>,2:bool,3:bool,4:list<MinerTransaction>}
     *         the rows, the blocks they cover, whether the chain moved, whether the time ran out,
     *         the miner transactions of the covered blocks that were asked for
     * @throws RpcException
     */
    private function fetchGroup(array $group, string $label): array
    {
        /** @var list<array{hash:string,height:int,idx:int}> $asked */
        $asked = [];
        $done = [];
        $miners = [];
        $moved = false;
        $timeUp = false;
        foreach ($group as $block) {
            if ($block['num_txes'] > 0) {
                $fetched = $this->fetchBlock($block, $label);
                $hashes = is_array($fetched) ? $fetched['hashes'] : $fetched;
                if ($hashes === false) {
                    $timeUp = true;
                    break;
                }
                if ($hashes === null) {
                    $moved = true;
                    break;
                }
                $miners[] = $fetched['miner'];
                foreach ($hashes as $idx => $hash) {
                    $asked[] = ['hash' => $hash, 'height' => $block['height'], 'idx' => $idx];
                }
            }
            $done[] = $block;
        }
        if (count(array_unique(array_column($asked, 'hash'))) !== count($asked)) {
            throw new IndexerException(FailureKind::InvalidData, 'a transaction hash is listed twice');
        }
        $rows = $this->fetchTransactions($asked, $label);
        if ($rows === null) {
            return [[], [], true, false, []]; // nothing of this group is kept
        }
        return [$rows, $done, $moved, $timeUp, $miners];
    }

    /**
     * One get_block; the hashes of its transactions and its miner transaction, null when the
     * node's block at that height is not the stored one, or false when the run's time is over
     * (nothing was asked).
     *
     * @param array{height:int,hash:string,num_txes:int,miner_tx_hash:string} $block
     * @return array{hashes:list<string>,miner:MinerTransaction}|null|false
     * @throws RpcException
     */
    private function fetchBlock(array $block, string $label): array|null|false
    {
        if (!$this->pause(true)) {
            return false;
        }
        $result = $this->client->call(RpcMethod::GetBlock, ['height' => $block['height']], false, $label);
        $header = $result->data['block_header'] ?? null;
        if (!is_array($header) || array_is_list($header) || !is_string($header['hash'] ?? null) || !is_int($header['height'] ?? null)) {
            throw new IndexerException(FailureKind::InvalidData, 'get_block has no usable block_header');
        }
        if ($header['height'] !== $block['height']) {
            throw new IndexerException(FailureKind::InvalidData, 'get_block answered for another height');
        }
        if ($header['hash'] !== $block['hash']) {
            $this->note = 'the block at height ' . $block['height'] . ' is not the stored one; the header indexer handles it';
            return null;
        }
        $hashes = $result->data['tx_hashes'] ?? null;
        if (!is_array($hashes) || !array_is_list($hashes) || count($hashes) !== $block['num_txes']) {
            throw new IndexerException(FailureKind::InvalidData, 'tx_hashes does not hold num_txes hashes');
        }
        foreach ($hashes as $hash) {
            if (!is_string($hash) || preg_match('/\A[0-9a-f]{64}\z/', $hash) !== 1) {
                throw new IndexerException(FailureKind::InvalidData, 'tx_hashes holds a value that is not 64 lower-case hex characters');
            }
        }
        /** @var list<string> $hashes */
        return ['hashes' => $hashes, 'miner' => TransactionParser::parseMiner($result->data, $block['miner_tx_hash'], $block['height'])];
    }

    /**
     * The transactions of $asked, in calls of at most MAX_HASHES. Null when the chain moved.
     *
     * @param list<array{hash:string,height:int,idx:int}> $asked
     * @return list<Transaction>|null
     * @throws RpcException
     */
    private function fetchTransactions(array $asked, string $label): ?array
    {
        $rows = [];
        foreach (array_chunk($asked, self::MAX_HASHES) as $batch) {
            $this->pause(false);
            $result = $this->client->call(
                RpcMethod::GetTransactions,
                [
                    'txs_hashes' => array_column($batch, 'hash'),
                    'decode_as_json' => true,
                    'prune' => true,
                ],
                false,
                $label,
            );
            if (!empty($result->data['missed_tx'])) {
                throw new IndexerException(FailureKind::InvalidData, 'the node missed transactions');
            }
            $txs = $result->data['txs'] ?? null;
            if (!is_array($txs) || !array_is_list($txs)) {
                throw new IndexerException(FailureKind::InvalidData, 'txs is not a list');
            }
            if (count($txs) !== count($batch)) {
                throw new IndexerException(
                    FailureKind::InvalidData,
                    'a node sent ' . count($txs) . ' transactions, not ' . count($batch),
                );
            }
            foreach ($batch as $i => $want) {
                $entry = $txs[$i];
                if (!is_array($entry) || array_is_list($entry) || ($entry['tx_hash'] ?? null) !== $want['hash']) {
                    throw new IndexerException(FailureKind::InvalidData, 'a transaction entry is not the one asked for');
                }
                $inPool = $entry['in_pool'] ?? null;
                if (!is_bool($inPool)) {
                    throw new IndexerException(FailureKind::InvalidData, 'in_pool is missing');
                }
                if ($inPool) {
                    $this->note = 'a transaction of a stored block is in the pool of the node; the chain moved';
                    return null;
                }
                $at = $entry['block_height'] ?? null;
                if (!is_int($at)) {
                    throw new IndexerException(FailureKind::InvalidData, 'block_height is missing');
                }
                if ($at !== $want['height']) {
                    $this->note = 'a transaction is in another block than the stored one; the chain moved';
                    return null;
                }
                $rows[] = TransactionParser::parse($entry, $want['hash'], $want['height'], $want['idx']);
            }
        }
        return $rows;
    }

    /**
     * @param list<Transaction> $rows
     * @param non-empty-list<array{height:int,hash:string,num_txes:int,miner_tx_hash:string}> $blocks
     * @param list<MinerTransaction> $miners
     */
    private function record(array $rows, int $mark, array $blocks, int $transactions, array $miners = []): void
    {
        $counts = $this->store->storeBlocks($rows, $mark, $miners);
        foreach ($counts as $key => $n) {
            $this->checks[$key] += $n;
        }
        $this->from ??= $blocks[0]['height'];
        $this->to = $mark;
        $this->blocks += count(array_filter($blocks, static fn (array $b): bool => $b['num_txes'] > 0));
        $this->transactions += $transactions;
    }

    /**
     * Wait between two calls of this run (never before the first). With $gate, false when the
     * run's time is over, before or after the wait; the call is then not made. The call that
     * stores what was already fetched is not gated.
     */
    private function pause(bool $gate): bool
    {
        if ($gate && $this->timeUp()) {
            return false;
        }
        if ($this->calls > 0) {
            $this->clock->sleep($this->limits->txBackfillPauseSeconds);
            if ($gate && $this->timeUp()) {
                return false;
            }
        }
        $this->calls++;
        return true;
    }

    private function timeUp(): bool
    {
        return $this->clock->now() - $this->runStartedAt >= $this->limits->runSeconds;
    }

    /** @return non-empty-list<string> the own nodes, the preferred one first */
    private function labels(): array
    {
        $labels = $this->client->ownLabels();
        if ($this->preferredNode !== null && in_array($this->preferredNode, $labels, true)) {
            $labels = [$this->preferredNode, ...array_values(array_diff($labels, [$this->preferredNode]))];
        }
        if ($labels === []) {
            throw new IndexerException(FailureKind::Policy, 'no own node to ask');
        }
        return $labels;
    }
}
