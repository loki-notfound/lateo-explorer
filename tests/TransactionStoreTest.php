<?php

declare(strict_types=1);

namespace Lateo\Explorer\Tests;

use Lateo\Explorer\Chain\Asset;
use Lateo\Explorer\Config\Config;
use Lateo\Explorer\Db\ChainStore;
use Lateo\Explorer\Db\Database;
use Lateo\Explorer\Db\Schema;
use Lateo\Explorer\Db\TransactionStore;
use Lateo\Explorer\Indexer\HeaderParser;
use Lateo\Explorer\Indexer\MinerTransaction;
use Lateo\Explorer\Indexer\Transaction;
use Lateo\Explorer\Indexer\TransactionIndexer;
use Lateo\Explorer\Tests\Support\FakeChain;
use Lateo\Explorer\Tests\Support\IndexerTestBase;
use Lateo\Explorer\Tests\Support\TxServer;

/** Schema versions 2 and 3, the transaction store, and what a rollback does to transactions. */
final class TransactionStoreTest extends IndexerTestBase
{
    private TransactionStore $txStore;

    public function setUp(): void
    {
        parent::setUp();
        $this->txStore = new TransactionStore($this->db);
    }

    /** @return list<\Lateo\Explorer\Indexer\Header> */
    private function headers(FakeChain $chain, int $from, int $to): array
    {
        $out = [];
        for ($h = $from; $h <= $to; $h++) {
            $out[] = HeaderParser::parse($chain->at($h));
        }
        return $out;
    }

    private function tx(int $height, int $idx, string $type = 'transfer'): Transaction
    {
        return new Transaction(
            TxServer::hashOf($height, $idx),
            $height,
            $idx,
            3,
            6,
            Asset::fromChain('ZPH'),
            Asset::fromChain('ZPH'),
            $type,
            2,
            4,
            1000,
            0,
            0,
            0,
            0,
        );
    }

    // --- the migration ------------------------------------------------------------------------

    public function testAVersion1DatabaseMigratesInPlaceAndKeepsItsData(): void
    {
        $db = Database::memory();
        $this->assertSame(1, Schema::migrate($db, [1 => Schema::migrations()[1]]));
        $chain = FakeChain::synthetic(1, 10);
        $store = new ChainStore($db);
        $store->insertBatch($this->headers($chain, 1, 10));
        $db->exec("INSERT INTO supply_snapshots (taken_at, height, hash, node, kind, payload) VALUES (1, 10, ?, 'own-a', 'circulating', '{}')", [$chain->hashAt(10)]);
        $db->exec("INSERT INTO runs (started_at, finished_at, mode, headers, outcome, note) VALUES (1, 2, 'tip', 10, 'complete', '')");
        $before = [
            $db->all('SELECT * FROM blocks ORDER BY height'),
            $db->all('SELECT * FROM pricing_records ORDER BY height'),
            $db->all('SELECT * FROM supply_snapshots'),
            $db->all('SELECT * FROM runs'),
        ];
        $this->assertSame(0, (int) $db->value("SELECT count(*) FROM sqlite_master WHERE name = 'transactions'"));

        $this->assertSame(Schema::VERSION, Schema::migrate($db));
        $this->assertSame(Schema::VERSION, Schema::currentVersion($db));
        $after = [
            $db->all('SELECT * FROM blocks ORDER BY height'),
            $db->all('SELECT * FROM pricing_records ORDER BY height'),
            $db->all('SELECT * FROM supply_snapshots'),
            $db->all('SELECT * FROM runs'),
        ];
        $this->assertSame($before, $after, 'nothing of version 1 changed');
        $this->assertSame(0, (int) $db->value('SELECT count(*) FROM transactions'));
        $this->assertNull((new TransactionStore($db))->indexedHeight(), 'no mark yet');
        // The database works with version 2 code.
        (new TransactionStore($db))->storeBlocks([$this->tx(2, 0)], 5);
        $this->assertSame(5, (new TransactionStore($db))->indexedHeight());
        $this->assertSame(Schema::VERSION, Schema::migrate($db), 'a second migration finds nothing to do');
    }

    public function testVersion1AndVersion2CodeRefuseAVersion3Database(): void
    {
        $e = $this->assertThrows(\RuntimeException::class, fn () => Schema::migrate($this->db, [1 => Schema::migrations()[1]]));
        $this->assertStringContains('newer', $e->getMessage());
        $migrations = Schema::migrations();
        $e = $this->assertThrows(\RuntimeException::class, fn () => Schema::migrate($this->db, [1 => $migrations[1], 2 => $migrations[2]]));
        $this->assertStringContains('newer', $e->getMessage());
    }

    public function testAVersion2DatabaseMigratesToVersion3AndKeepsItsRowsWithNullInTheNewColumn(): void
    {
        $db = Database::memory();
        $migrations = Schema::migrations();
        $this->assertSame(2, Schema::migrate($db, [1 => $migrations[1], 2 => $migrations[2]]));
        $chain = FakeChain::synthetic(1, 10);
        (new ChainStore($db))->insertBatch($this->headers($chain, 1, 10));
        $hash = TxServer::hashOf(2, 0);
        $db->exec(
            "INSERT INTO transactions (hash, height, idx, version, rct_type, source_asset, source_gen, dest_asset, dest_gen,
                tx_type, n_inputs, n_outputs, fee, pricing_record_height, amount_burnt, amount_minted)
             VALUES (?, 2, 0, 3, 6, 'ZPH', 'new', 'ZPH', 'new', 'transfer', 2, 4, 1000, 0, 0, 0)",
            [$hash],
        );
        $before = $db->all('SELECT * FROM transactions');

        $this->assertSame(3, Schema::migrate($db));
        $this->assertSame(3, Schema::currentVersion($db));
        $after = $db->all('SELECT * FROM transactions');
        $this->assertSame(1, count($after));
        $this->assertNull($after[0]['n_untagged_outputs'], 'a row of version 2 code has NULL');
        unset($after[0]['n_untagged_outputs']);
        $this->assertSame($before, $after, 'nothing else of the row changed');
        $this->assertSame(0, (int) $db->value('SELECT count(*) FROM miner_txs'));
        // Version 3 code writes the column.
        (new TransactionStore($db))->storeBlocks([$this->tx(3, 0)], 5);
        $this->assertSame(0, (int) $db->value('SELECT n_untagged_outputs FROM transactions WHERE height = 3'));
    }

    public function testTheMinerTableAndTheNewColumnRefuseWhatTheyShouldRefuse(): void
    {
        $sql = (string) $this->db->value("SELECT sql FROM sqlite_master WHERE name = 'miner_txs'");
        $this->assertStringContains('WITHOUT ROWID', $sql);
        $this->assertSame(
            ['height', 'hash', 'version', 'rct_type', 'n_outputs', 'n_untagged_outputs'],
            array_map(static fn (array $r): string => (string) $r['name'], $this->db->all("SELECT name FROM pragma_table_info('miner_txs') ORDER BY cid")),
        );
        $chain = FakeChain::synthetic(1, 5);
        $this->store->insertBatch($this->headers($chain, 1, 5));
        $insert = 'INSERT INTO miner_txs (height, hash, version, rct_type, n_outputs, n_untagged_outputs) VALUES (?, ?, ?, ?, ?, ?)';
        $good = [1, str_repeat('a', 64), 3, 0, 2, 0];
        $this->db->exec($insert, $good);
        foreach ([
            [1, str_repeat('b', 64), 3, 0, 2, 0], // height twice
            [99, str_repeat('b', 64), 3, 0, 2, 0], // a block that is not stored
            [2, str_repeat('A', 64), 3, 0, 2, 0], // upper case
            [2, str_repeat('b', 63), 3, 0, 2, 0], // short
            [2, str_repeat('b', 64), -1, 0, 2, 0],
            [2, str_repeat('b', 64), 3, -1, 2, 0],
            [2, str_repeat('b', 64), 3, 0, -1, 0],
            [2, str_repeat('b', 64), 3, 0, 2, -1],
            [2, null, 3, 0, 2, 0],
            [2, str_repeat('b', 64), 3, 0, 2, null],
        ] as $row) {
            $this->assertThrows(\PDOException::class, fn () => $this->db->exec($insert, $row), json_encode($row));
        }
        $this->assertSame(1, $this->count('miner_txs'));
        $this->db->exec(
            "INSERT INTO transactions (hash, height, idx, version, rct_type, source_asset, source_gen, dest_asset, dest_gen,
                tx_type, n_inputs, n_outputs, fee, pricing_record_height, amount_burnt, amount_minted, n_untagged_outputs)
             VALUES (?, 2, 0, 3, 6, 'ZPH', 'new', 'ZPH', 'new', 'transfer', 2, 4, 1000, 0, 0, 0, NULL)",
            [TxServer::hashOf(2, 0)],
        );
        $this->assertSame(1, $this->count('transactions'), 'NULL is allowed in the column');
        $this->assertThrows(\PDOException::class, fn () => $this->db->exec('UPDATE transactions SET n_untagged_outputs = -1'));
    }

    public function testMinerRowsAndTheMarkAreStoredTogetherOrNotAtAll(): void
    {
        $chain = FakeChain::synthetic(1, 10);
        $this->store->insertBatch($this->headers($chain, 1, 10));
        $miner = static fn (int $h): MinerTransaction => new MinerTransaction($h, $chain->at($h)['miner_tx_hash'], 3, 0, 2, 1);
        $this->txStore->storeBlocks([$this->tx(2, 0)], 3, [$miner(2), $miner(3)]);
        $this->assertSame(3, $this->txStore->indexedHeight());
        $this->assertSame([[2, 2, 1], [3, 2, 1]], array_map(
            static fn (array $r): array => [(int) $r['height'], (int) $r['n_outputs'], (int) $r['n_untagged_outputs']],
            $this->db->all('SELECT * FROM miner_txs ORDER BY height'),
        ));
        // A second row for a height already stored: nothing of the call is kept, the mark stays.
        $this->assertThrows(\PDOException::class, fn () => $this->txStore->storeBlocks([$this->tx(5, 0)], 6, [$miner(5), $miner(3)]));
        $this->assertSame(3, $this->txStore->indexedHeight());
        $this->assertSame(2, $this->count('miner_txs'));
        $this->assertSame(1, $this->count('transactions'));
        $this->assertThrows(\LogicException::class, fn () => $this->txStore->storeBlocks([], 4, [$miner(5)]));
        $this->assertSame(2, $this->count('miner_txs'));
    }

    public function testTheTransactionsTableIsWithoutRowidAndHasTheIndexes(): void
    {
        $sql = (string) $this->db->value("SELECT sql FROM sqlite_master WHERE name = 'transactions'");
        $this->assertStringContains('WITHOUT ROWID', $sql);
        $this->assertStringNotContains('unlock_time', $sql);
        $cols = array_map(static fn (array $r): string => (string) $r['name'], $this->db->all("SELECT name FROM pragma_table_info('transactions') ORDER BY cid"));
        $this->assertSame([
            'hash', 'height', 'idx', 'version', 'rct_type', 'source_asset', 'source_gen', 'dest_asset', 'dest_gen',
            'tx_type', 'n_inputs', 'n_outputs', 'fee', 'pricing_record_height', 'amount_burnt', 'amount_minted',
            'n_untagged_outputs',
        ], $cols);
        $this->assertSame(0, (int) $this->db->value("SELECT count(*) FROM sqlite_master WHERE type = 'index' AND name = 'transactions_height'"));
        // The unique (height, idx) index serves the rollback and every query by height.
        $plan = implode(' ', array_map(
            static fn (array $r): string => (string) $r['detail'],
            $this->db->all('EXPLAIN QUERY PLAN DELETE FROM transactions WHERE height > 5'),
        ));
        $this->assertStringContains('INDEX', $plan, $plan);
        $this->assertStringContains('height>?', $plan, $plan);
        $this->assertSame('hash', $this->db->value("SELECT name FROM pragma_table_info('transactions') WHERE pk = 1"));
    }

    public function testTheTableRefusesWhatItShouldRefuse(): void
    {
        $chain = FakeChain::synthetic(1, 3);
        $this->store->insertBatch($this->headers($chain, 1, 3));
        $good = [
            'hash' => str_repeat('ab', 32), 'height' => 2, 'idx' => 0, 'version' => 3, 'rct_type' => 6,
            'source_asset' => 'ZPH', 'source_gen' => 'old', 'dest_asset' => 'ZPH', 'dest_gen' => 'old', 'tx_type' => 'transfer',
            'n_inputs' => 1, 'n_outputs' => 2, 'fee' => 5, 'pricing_record_height' => 0, 'amount_burnt' => 0, 'amount_minted' => 0,
        ];
        $insert = function (array $row): void {
            $this->db->exec(
                'INSERT INTO transactions (' . implode(', ', array_keys($row)) . ') VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')',
                array_values($row),
            );
        };
        $insert($good);
        $this->assertSame(1, $this->count('transactions'));

        $bad = [
            'an upper-case hash' => ['hash' => str_repeat('AB', 32), 'idx' => 1],
            'a short hash' => ['hash' => str_repeat('ab', 31), 'idx' => 1],
            'a hash with a non-hex character' => ['hash' => str_repeat('ab', 31) . 'xy', 'idx' => 1],
            'a hash twice' => ['idx' => 1],
            'the same place twice' => ['hash' => str_repeat('cd', 32)],
            'a block that is not stored' => ['hash' => str_repeat('cd', 32), 'height' => 99, 'idx' => 1],
            'a negative idx' => ['hash' => str_repeat('cd', 32), 'idx' => -1],
            'a ticker that is not canonical' => ['hash' => str_repeat('cd', 32), 'idx' => 1, 'source_asset' => 'ZEPH'],
            'a free-text asset' => ['hash' => str_repeat('cd', 32), 'idx' => 1, 'dest_asset' => '<b>'],
            'a generation that is not one' => ['hash' => str_repeat('cd', 32), 'idx' => 1, 'source_gen' => 'both'],
            'an unknown asset with a known generation' => ['hash' => str_repeat('cd', 32), 'idx' => 1, 'dest_asset' => 'unknown'],
            'a known asset with the unknown generation' => ['hash' => str_repeat('cd', 32), 'idx' => 1, 'source_gen' => 'unknown'],
            'a type that is not on the list' => ['hash' => str_repeat('cd', 32), 'idx' => 1, 'tx_type' => 'swap'],
            'a negative fee' => ['hash' => str_repeat('cd', 32), 'idx' => 1, 'fee' => -1],
            'a negative amount' => ['hash' => str_repeat('cd', 32), 'idx' => 1, 'amount_burnt' => -1],
            'a missing column' => ['hash' => str_repeat('cd', 32), 'idx' => 1, 'fee' => null],
        ];
        foreach ($bad as $what => $change) {
            $row = $change + $good;
            $this->assertThrows(\PDOException::class, static fn () => $insert($row), $what);
        }
        $this->assertSame(1, $this->count('transactions'));
        // An unknown asset with the unknown generation, and every type of the list, is fine.
        $insert(['hash' => str_repeat('cd', 32), 'idx' => 1, 'source_asset' => 'unknown', 'source_gen' => 'unknown', 'dest_asset' => 'unknown', 'dest_gen' => 'unknown', 'tx_type' => 'unknown'] + $good);
        $this->assertSame(2, $this->count('transactions'));
    }

    // --- storing ------------------------------------------------------------------------------

    public function testRowsAndTheMarkAreStoredTogetherOrNotAtAll(): void
    {
        $chain = FakeChain::synthetic(1, 10);
        $this->store->insertBatch($this->headers($chain, 1, 10));
        $this->txStore->storeBlocks([$this->tx(2, 0), $this->tx(2, 1), $this->tx(4, 0)], 4);
        $this->assertSame(3, $this->count('transactions'));
        $this->assertSame(4, $this->txStore->indexedHeight());
        $row = $this->db->one('SELECT * FROM transactions WHERE height = 2 AND idx = 1');
        $this->assertSame(TxServer::hashOf(2, 1), $row['hash']);
        $this->assertSame('ZPH', $row['source_asset']);
        $this->assertSame('new', $row['source_gen']);
        $this->assertSame('integer', $this->db->value('SELECT typeof(fee) FROM transactions LIMIT 1'));

        // The second row of the group repeats a stored hash: nothing of the group, not the mark.
        $this->assertThrows(\PDOException::class, fn () => $this->txStore->storeBlocks([$this->tx(6, 0), $this->tx(2, 0)], 8));
        $this->assertSame(3, $this->count('transactions'));
        $this->assertSame(4, $this->txStore->indexedHeight());
        // A row above the mark is refused (the mark says "everything up to here").
        $this->assertThrows(\LogicException::class, fn () => $this->txStore->storeBlocks([$this->tx(6, 0)], 5));
        $this->assertSame(3, $this->count('transactions'));
        // The mark can move without rows (blocks without transactions).
        $this->txStore->storeBlocks([], 9);
        $this->assertSame(9, $this->txStore->indexedHeight());
    }

    public function testBlocksAboveGivesTheStoredBlocksInOrderUpToTheTop(): void
    {
        $chain = FakeChain::synthetic(5, 20);
        $this->store->insertBatch($this->headers($chain, 5, 20));
        $rows = $this->txStore->blocksAbove(null, 100, 500);
        $this->assertSame(16, count($rows));
        $this->assertSame([5, 6, 7], array_slice(array_column($rows, 'height'), 0, 3));
        $this->assertSame($chain->hashAt(5), $rows[0]['hash']);
        $this->assertSame(5 % 3, $rows[0]['num_txes']);
        $rows = $this->txStore->blocksAbove(9, 12, 500);
        $this->assertSame([10, 11, 12], array_column($rows, 'height'));
        $this->assertSame(2, count($this->txStore->blocksAbove(null, 100, 2)));
    }

    // --- rollback -----------------------------------------------------------------------------

    private function fillTransactions(): FakeChain
    {
        $chain = FakeChain::synthetic(1, 10);
        $this->store->insertBatch($this->headers($chain, 1, 10));
        $rows = [];
        for ($h = 1; $h <= 10; $h++) {
            for ($i = 0; $i < $h % 3; $i++) {
                $rows[] = $this->tx($h, $i);
            }
        }
        $this->txStore->storeBlocks($rows, 10);
        return $chain;
    }

    public function testARollbackDeletesTheTransactionsAboveTheForkAndLowersTheMark(): void
    {
        $this->fillTransactions();
        $total = $this->count('transactions');
        $above = (int) $this->db->value('SELECT count(*) FROM transactions WHERE height > 6');
        $this->assertTrue($above > 0);

        $removed = $this->store->rollbackAbove(6, 'own-a', 1_800_000_000);
        $this->assertSame(4, $removed);
        $this->assertSame($total - $above, $this->count('transactions'));
        $this->assertSame(0, (int) $this->db->value('SELECT count(*) FROM transactions WHERE height > 6'));
        $this->assertSame(6, $this->txStore->indexedHeight(), 'the mark is the fork height');
        $this->assertSame(6, $this->count('blocks'));
        $this->assertSame((int) $this->db->value('SELECT count(*) FROM transactions WHERE height <= 6'), $this->count('transactions'), 'what is below stays');
    }

    public function testARollbackDeletesTheMinerRowsAboveTheForkInTheSameTransaction(): void
    {
        $chain = $this->fillTransactions();
        $rows = [];
        foreach (range(1, 10) as $h) {
            $rows[] = new MinerTransaction($h, $chain->at($h)['miner_tx_hash'], 3, 0, 2, $h % 2);
        }
        $this->txStore->storeBlocks([], 10, $rows);
        $this->assertSame(10, $this->count('miner_txs'));

        $this->store->rollbackAbove(6, 'own-a', 1_800_000_000);
        $this->assertSame(6, $this->count('miner_txs'));
        $this->assertSame(0, (int) $this->db->value('SELECT count(*) FROM miner_txs WHERE height > 6'));
        $this->assertSame(6, (int) $this->db->value('SELECT max(height) FROM miner_txs'));
        $this->assertSame(6, $this->count('blocks'), 'the blocks went in the same transaction');
        $this->assertSame(6, $this->txStore->indexedHeight());
    }

    public function testARollbackKeepsAMarkBelowTheFork(): void
    {
        $this->fillTransactions();
        $this->txStore->storeBlocks([], 4);
        $this->db->exec("UPDATE meta SET value = '4' WHERE key = 'tx_indexed_height'");
        $this->store->rollbackAbove(6, 'own-a', 1);
        $this->assertSame(4, $this->txStore->indexedHeight(), 'a mark below the fork is not raised');
        $this->assertSame(0, (int) $this->db->value('SELECT count(*) FROM transactions WHERE height > 6'));
    }

    public function testARollbackWithoutAMarkOrTransactionsStillWorks(): void
    {
        $chain = FakeChain::synthetic(1, 10);
        $this->store->insertBatch($this->headers($chain, 1, 10));
        $this->assertSame(4, $this->store->rollbackAbove(6, 'own-a', 1));
        $this->assertNull($this->txStore->indexedHeight(), 'no mark is made by a rollback');
        $this->assertSame(0, $this->count('transactions'));
        $this->assertSame(0, (int) $this->db->value("SELECT count(*) FROM meta WHERE key = 'tx_indexed_height'"));
    }

    public function testARollbackAtOrAboveTheTopChangesNothing(): void
    {
        $this->fillTransactions();
        $this->assertSame(0, $this->store->rollbackAbove(10, 'own-a', 1));
        $this->assertSame(10, $this->txStore->indexedHeight());
        $this->assertSame(0, $this->store->rollbackAbove(12, 'own-a', 1));
        $this->assertSame(10, $this->txStore->indexedHeight());
        $this->assertSame(0, $this->count('reorgs'));
    }

    public function testAReorgSeenByTheHeaderIndexerRemovesTheTransactionsAndTheyAreFetchedAgain(): void
    {
        $config = $this->config(['tx_backfill_enabled' => true]);
        $a = FakeChain::synthetic(0, 20, 'a');
        $server = new TxServer($a);
        $this->serveTx($server);
        $this->indexer($config)->run();
        $first = $this->txIndexer($config)->run();
        $this->assertSame('complete', $first->outcome, $first->note);
        $this->assertSame(20, $this->txStore->indexedHeight());
        $before = $this->count('transactions');
        $this->assertTrue($before > 0);

        // The own nodes now follow another branch from block 15 on.
        $b = FakeChain::forked($a, 14, 'b', 22);
        $serverB = new TxServer($b);
        $this->serveTx($serverB);
        $summary = $this->indexer($config)->run();
        $this->assertSame(1, $summary->reorgs);
        $this->assertSame(14, $this->txStore->indexedHeight(), 'the mark went down to the fork height');
        $this->assertSame(0, (int) $this->db->value('SELECT count(*) FROM transactions WHERE height > 14'));
        $kept = $this->count('transactions');
        $this->assertTrue($kept < $before && $kept > 0);

        $second = $this->txIndexer($config)->run();
        $this->assertSame('complete', $second->outcome, $second->note);
        $this->assertSame(22, $this->txStore->indexedHeight());
        // Every row above the fork belongs to the new branch's blocks.
        $this->assertSame(0, (int) $this->db->value('SELECT count(*) FROM transactions t LEFT JOIN blocks b ON b.height = t.height WHERE b.hash IS NULL'));
        $expected = 0;
        for ($h = 15; $h <= 22; $h++) {
            $expected += (int) $b->at($h)['num_txes'];
        }
        $this->assertSame($expected, (int) $this->db->value('SELECT count(*) FROM transactions WHERE height > 14'));
        $this->assertSame(TxServer::hashOf(16, 0), (string) $this->db->value('SELECT hash FROM transactions WHERE height = 16 AND idx = 0'));
    }

    private function serveTx(TxServer $server): void
    {
        foreach ([self::A, self::B, self::F] as $host) {
            $this->transport->serve($host, static fn ($c) => $server->respond($c));
        }
    }

    private function txIndexer(Config $config): TransactionIndexer
    {
        return new TransactionIndexer(
            $this->client($config),
            $this->store,
            $this->txStore,
            $config->limits,
            $this->clock,
            $this->clock->now(),
            'own-a',
        );
    }
}
