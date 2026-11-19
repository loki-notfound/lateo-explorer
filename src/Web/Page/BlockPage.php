<?php

declare(strict_types=1);

namespace Lateo\Explorer\Web\Page;

use Lateo\Explorer\Web\Format;
use Lateo\Explorer\Web\Response;
use Lateo\Explorer\Web\Services;

/**
 * /block/<height> and /block/<hash>: everything stored for a block, its state, and (for a
 * block with transactions) their hashes from one cached live read.
 *
 * States: Canonical (a block in the index; for the last unsettled_depth blocks of the index
 * with a note that it may still change), Orphaned (a hash a rollback removed), and not
 * indexed yet (a height above the index, or a hash the index does not know).
 */
final class BlockPage
{
    /** The first block with a pricing record (hard fork 3, 2023-10-01). */
    public const FIRST_PRICING_HEIGHT = 89300;
    /** The first block of hard fork 11. */
    public const HARD_FORK_11_HEIGHT = 536000;

    /** @param string $key a height (digits) or a block hash (64 lower-case hex) */
    public function respond(Services $s, string $key): Response
    {
        $index = $s->index();
        $byHeight = preg_match('/\A[0-9]+\z/', $key) === 1;
        $block = $byHeight ? $index->blockByHeight((int) $key) : $index->blockByHash($key);
        $path = '/block/' . $key;

        if ($block !== null) {
            return $this->canonical($s, $block, $path);
        }
        if (!$byHeight) {
            $orphan = $index->orphaned($key);
            if ($orphan !== null) {
                return $this->orphaned($s, $key, $orphan, $path);
            }
        }
        $top = $index->top();
        $html = $s->view->render('block-missing', [
            'byHeight' => $byHeight,
            'key' => $key,
            'top' => $top === null ? null : $top['height'],
        ]);
        return $s->layout->page(404, 'Block not indexed yet', 'This block is not in the index.', $path, $html, 'public, max-age=10');
    }

    /** @param array<string,mixed> $block */
    private function canonical(Services $s, array $block, string $path): Response
    {
        $index = $s->index();
        $height = (int) $block['height'];
        $top = $index->top();
        $topHeight = $top === null ? $height : $top['height'];
        $depth = $s->config->limits->unsettledDepth;
        $unsettled = $topHeight - $height < $depth;
        $now = $s->now();

        $txs = null;
        if ((int) $block['num_txes'] > 0) {
            $txs = $s->live()->blockTransactions($height, (string) $block['hash'], (int) $block['num_txes'], !$unsettled);
        }
        $pricing = $index->pricingAt($height);

        $html = $s->view->render('block', [
            'now' => $now,
            'block' => $block,
            'pricing' => $pricing,
            'unsettled' => $unsettled,
            'depth' => $depth,
            'prev' => $height > 0 && $index->hashAt($height - 1) !== null ? $height - 1 : null,
            'next' => $index->hashAt($height + 1) !== null ? $height + 1 : null,
            'txs' => $txs === null ? null : ($txs->ok() ? $txs->value : null),
            'txsMessage' => $txs === null || $txs->ok() ? '' : $txs->state->message(),
            'txsStale' => $txs !== null && $txs->stale,
            'firstPricingHeight' => self::FIRST_PRICING_HEIGHT,
            'hardFork11' => $height === self::HARD_FORK_11_HEIGHT,
        ]);

        if ($unsettled) {
            $cache = 'public, max-age=15';
        } elseif ($now - (int) $block['timestamp'] > Format::AGE_LIMIT_SECONDS) {
            $cache = 'public, max-age=86400';
        } else {
            // The age beside the time is relative, so a settled recent block is cached for an hour only.
            $cache = 'public, max-age=3600';
        }
        return $s->layout->page(
            200,
            'Block ' . Format::int($height),
            'Block ' . Format::int($height) . ' of the Zephyr chain: header, miner reward and pricing record.',
            $path,
            $html,
            $cache,
            [['label' => 'Home', 'href' => '/'], ['label' => 'Block ' . Format::int($height)]],
        );
    }

    /** @param array{height:int,detected_at:int} $orphan */
    private function orphaned(Services $s, string $hash, array $orphan, string $path): Response
    {
        $canonical = $s->index()->hashAt($orphan['height']);
        $html = $s->view->render('block-orphaned', [
            'now' => $s->now(),
            'hash' => $hash,
            'height' => $orphan['height'],
            'detectedAt' => $orphan['detected_at'],
            'canonicalHeight' => $canonical === null ? null : $orphan['height'],
        ]);
        return $s->layout->page(
            200,
            'Orphaned block at height ' . Format::int($orphan['height']),
            'A block that a chain reorganisation replaced.',
            $path,
            $html,
            'public, max-age=60',
            [['label' => 'Home', 'href' => '/'], ['label' => 'Orphaned block']],
        );
    }
}
