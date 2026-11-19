<?php

declare(strict_types=1);

namespace Lateo\Explorer\Tests;

use Lateo\Explorer\Db\RunStore;
use Lateo\Explorer\Indexer\HeaderParser;
use Lateo\Explorer\Tests\Support\FakeChain;
use Lateo\Explorer\Tests\Support\IndexerTestBase;

final class ChainStoreTest extends IndexerTestBase
{
    /** @return list<\Lateo\Explorer\Indexer\Header> */
    private function headers(FakeChain $chain, int $from, int $to): array
    {
        $out = [];
        for ($h = $from; $h <= $to; $h++) {
            $out[] = HeaderParser::parse($chain->at($h));
        }
        return $out;
    }

    public function testInsertAndReadBack(): void
    {
        $chain = FakeChain::synthetic(1, 10);
        $this->store->insertBatch($this->headers($chain, 1, 10));
        $this->assertSame(['height' => 10, 'hash' => $chain->hashAt(10)], $this->store->top());
        $this->assertSame(1, $this->store->lowestHeight());
        $this->assertSame($chain->hashAt(4), $this->store->hashAt(4));
        $this->assertNull($this->store->hashAt(11));
        $this->assertSame([3 => $chain->hashAt(3), 4 => $chain->hashAt(4)], $this->store->hashesBetween(3, 4));
        $this->assertSame(10, $this->count('blocks'));
        $this->assertSame(10, $this->count('pricing_records'));
    }

    public function testBatchIsAtomic(): void
    {
        $chain = FakeChain::synthetic(1, 10);
        $this->store->insertBatch($this->headers($chain, 1, 5));
        // A batch that repeats height 5 fails at its fifth block; the first four are rolled back.
        $this->assertThrows(\PDOException::class, fn () => $this->store->insertBatch(array_merge($this->headers($chain, 6, 10), $this->headers($chain, 5, 5))));
        $this->assertSame(5, $this->count('blocks'));
        $this->assertSame(5, $this->count('pricing_records'));
    }

    public function testRollbackAboveDeletesEveryChainTableAndRecordsIt(): void
    {
        $chain = FakeChain::synthetic(1, 10);
        $this->store->insertBatch($this->headers($chain, 1, 10));
        $this->db->exec('INSERT INTO supply_snapshots (taken_at, height, hash, node, kind, payload) VALUES (1, 10, ?, \'own-a\', \'circulating\', \'{}\')', [$chain->hashAt(10)]);

        $removed = $this->store->rollbackAbove(6, 'own-b', 1_800_000_123);
        $this->assertSame(4, $removed);
        $this->assertSame(6, $this->count('blocks'));
        $this->assertSame(6, $this->count('pricing_records'));
        $this->assertSame(4, $this->count('orphaned_blocks'));
        $this->assertTrue($this->store->isOrphaned($chain->hashAt(7)));
        $this->assertTrue($this->store->isOrphaned($chain->hashAt(10)));
        $this->assertFalse($this->store->isOrphaned($chain->hashAt(6)));
        $row = $this->db->one('SELECT * FROM reorgs');
        $this->assertSame(6, (int) $row['fork_height']);
        $this->assertSame(10, (int) $row['old_tip_height']);
        $this->assertSame($chain->hashAt(10), $row['old_tip_hash']);
        $this->assertSame(4, (int) $row['depth']);
        $this->assertSame('own-b', $row['node']);
        $this->assertSame(1_800_000_123, (int) $row['detected_at']);
        $this->assertSame(1, $this->count('supply_snapshots'), 'snapshots survive a rollback');
    }

    public function testStoringABlockAgainRemovesItFromOrphanedBlocks(): void
    {
        $chain = FakeChain::synthetic(1, 10);
        $this->store->insertBatch($this->headers($chain, 1, 10));
        $this->store->rollbackAbove(6, 'own-a', 1);
        $this->assertSame(4, $this->count('orphaned_blocks'));
        // Blocks 7 to 9 return to the main chain; 10 stays orphaned.
        $this->store->insertBatch($this->headers($chain, 7, 9));
        $this->assertSame(1, $this->count('orphaned_blocks'));
        $this->assertTrue($this->store->isOrphaned($chain->hashAt(10)));
        $this->assertFalse($this->store->isOrphaned($chain->hashAt(8)));
        $this->assertSame(9, $this->count('blocks'));
    }

    public function testTopWorkIsTheCumulativeDifficultyOfTheTopBlock(): void
    {
        $this->assertNull($this->store->topWork());
        $chain = FakeChain::synthetic(1, 10);
        $this->store->insertBatch($this->headers($chain, 1, 10));
        $this->assertSame('10005', $this->store->topWork());
    }

    public function testRollbackAtOrAboveTheTopDoesNothing(): void
    {
        $chain = FakeChain::synthetic(1, 5);
        $this->store->insertBatch($this->headers($chain, 1, 5));
        $this->assertSame(0, $this->store->rollbackAbove(5, 'own-a', 1));
        $this->assertSame(0, $this->store->rollbackAbove(9, 'own-a', 1));
        $this->assertSame(5, $this->count('blocks'));
        $this->assertSame(0, $this->count('reorgs'));
    }

    public function testRunsOlderThanThirtyDaysArePruned(): void
    {
        $runs = new RunStore($this->db);
        $now = 1_800_000_000;
        $runs->record($now - 31 * 86400, $now - 31 * 86400 + 5, 'tip', 1, 2, 2, 'own-a', 'complete', '');
        $runs->record($now - 29 * 86400, $now - 29 * 86400 + 5, 'backfill', null, null, 0, null, 'error', 'node: x');
        $runs->record($now - 10, $now - 5, 'tip', 3, 3, 1, 'own-a', 'complete', '');
        $this->assertSame(1, $runs->prune($now));
        $this->assertSame(2, $this->count('runs'));
        $this->assertSame(0, $runs->prune($now));
    }
}
