<?php

declare(strict_types=1);

namespace Lateo\Explorer\Tests;

use Lateo\Explorer\Chain\Asset;
use Lateo\Explorer\Chain\ConversionRule;
use Lateo\Explorer\Db\ConversionCheckStore;
use Lateo\Explorer\Db\Database;
use Lateo\Explorer\Db\Schema;
use Lateo\Explorer\Db\TransactionStore;
use Lateo\Explorer\Indexer\HeaderParser;
use Lateo\Explorer\Indexer\Transaction;
use Lateo\Explorer\Indexer\TransactionIndexer;
use Lateo\Explorer\Indexer\TransactionParser;
use Lateo\Explorer\Indexer\TxSummary;
use Lateo\Explorer\Tests\Support\FakeChain;
use Lateo\Explorer\Tests\Support\IndexerTestBase;
use Lateo\Explorer\Tests\Support\TxServer;

/**
 * conversion_checks: the recorded conversions and audits recompute to a match from the
 * recorded data through the parser and the store; the table is written with the rows, undone
 * by a rollback and refilled from the other tables.
 */
final class ConversionChecksTest extends IndexerTestBase
{
    private TransactionStore $txStore;

    public function setUp(): void
    {
        parent::setUp();
        $this->txStore = new TransactionStore($this->db);
    }

    /** @return array<string,mixed> */
    private function fixture(string $name): array
    {
        return json_decode(
            (string) file_get_contents(__DIR__ . '/fixtures/rpc/' . $name . '.json'),
            true,
            512,
            JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR,
        );
    }

    /** The recorded header of one block, stored as the header indexer would. */
    private function storeBlock(int $height): void
    {
        $d = $this->fixture('get_block__height-' . $height);
        $this->store->insertBatch([HeaderParser::parse($d['result']['block_header'])]);
    }

    /** The recorded header of the block a conversion refers to (a range of one block). */
    private function storeRecordBlock(int $height): void
    {
        $d = $this->fixture('get_block_headers_range__end_height-' . $height . '__start_height-' . $height);
        $this->assertCount(1, $d['result']['headers']);
        $this->store->insertBatch([HeaderParser::parse($d['result']['headers'][0])]);
    }

    private function txIndexer(): TransactionIndexer
    {
        $config = $this->config(['tx_backfill_enabled' => true]);
        return new TransactionIndexer($this->client($config), $this->store, $this->txStore, $config->limits, $this->clock, $this->clock->now(), 'own-a');
    }

    /** @return array<string,array<string,mixed>> the checks by transaction type and index in the block */
    private function checks(): array
    {
        $out = [];
        foreach ($this->db->all(
            'SELECT t.tx_type, t.height, t.idx, t.pricing_record_height AS prh, t.amount_burnt, t.amount_minted, c.*
             FROM conversion_checks c JOIN transactions t ON t.hash = c.tx_hash ORDER BY t.height, t.idx',
        ) as $row) {
            $out[$row['height'] . ':' . $row['idx']] = $row;
        }
        return $out;
    }

    // --- every recorded conversion and audit ---------------------------------------------------

    public function testTheRecordedConversionsAndAuditsRecomputeToAMatch(): void
    {
        // block, the block of its pricing record, hard fork, type, number of checks
        $cases = [
            [120974, 120972, 3, 'redeem_reserve', 1],
            [295006, 295004, 5, 'mint_reserve', 2],
            [400878, 400876, 6, 'mint_yield', 1],
            [481502, null, 8, 'audit', 2],
        ];
        foreach ($cases as [$height, $record, $hardFork, $type, $count]) {
            $this->setUp();
            if ($record !== null) {
                $this->storeRecordBlock($record);
            }
            $this->storeBlock($height);
            if ($record !== null) {
                $this->txStore->storeBlocks([], $record); // the record's block is not asked for
            }
            $summary = $this->txIndexer()->run();
            $this->assertSame(TxSummary::COMPLETE, $summary->outcome, $height . ': ' . $summary->note);
            $this->assertSame([$count, $count, 0, 0], [$summary->checks, $summary->matches, $summary->mismatches, $summary->notRecomputed], (string) $height);
            $checks = array_values($this->checks());
            $this->assertCount($count, $checks, (string) $height);
            foreach ($checks as $c) {
                $this->assertSame($type, $c['tx_type']);
                $this->assertSame(['match', 'checked', $hardFork, ConversionRule::VERSION, $height], [$c['verdict'], $c['reason'], (int) $c['hard_fork'], (int) $c['rule_version'], (int) $c['height']], (string) $height);
                $this->assertSame((string) $c['amount_minted'], $c['expected'], 'the expected amount is what the chain minted');
            }
            // The plain transfers of the block have no row.
            $this->assertSame($count, $this->count('conversion_checks'));
            $this->assertTrue($this->count('transactions') > $count || $height === 481502, 'the block also has plain transfers');
        }
    }

    public function testTheMintYieldOfBlock400878RecomputesWithTheYieldPrice(): void
    {
        $this->storeRecordBlock(400876);
        $this->storeBlock(400878);
        $this->txStore->storeBlocks([], 400876);
        $this->txIndexer()->run();
        $c = array_values($this->checks())[0];
        $this->assertSame(['yield_price', '1167966620000', '856188852383', '856188852', '855332660000'], [$c['price_field'], $c['price'], $c['r0'], $c['fee'], $c['rate']]);
    }

    public function testTheRedeemOfBlock905998RecomputesFromRecord905996(): void
    {
        $this->storeRecordBlock(905996);
        $this->storeBlock(905998);
        $this->txStore->storeBlocks([], 905996);
        $d = $this->fixture('get_transactions__decode_as_json-true__txs_hashes-64b3f44e2521');
        $entry = $d['txs'][0];
        $t = TransactionParser::parse($entry, $entry['tx_hash'], 905998, 0);
        $counts = $this->txStore->storeBlocks([$t], 905998);
        $this->assertSame(['checks' => 1, 'match' => 1, 'mismatch' => 0, 'not_recomputed' => 0], $counts);
        $c = array_values($this->checks())[0];
        $this->assertSame('redeem_stable', $c['tx_type']);
        $this->assertSame(['match', 'checked', 11, 'stable', '8574815130000'], [$c['verdict'], $c['reason'], (int) $c['hard_fork'], $c['price_field'], $c['price']]);
        $this->assertSame('8566240310000000', $c['expected']);
        $this->assertSame('8566240310000000', (string) $c['amount_minted']);
    }

    // --- the storage --------------------------------------------------------------------------

    private function tx(int $height, int $idx, string $type, int $prh, int $burnt, int $minted, string $from = 'ZSD', string $to = 'ZPH'): Transaction
    {
        return new Transaction(
            TxServer::hashOf($height, $idx), $height, $idx, 3, 6, Asset::fromChain($from), Asset::fromChain($to),
            $type, 2, 4, 1000, $prh, $burnt, $minted,
        );
    }

    /** Blocks 1 to 30 of a synthetic chain, with a complete record in the header of block 20. */
    private function chainWithRecords(): FakeChain
    {
        $chain = FakeChain::synthetic(1, 30);
        $this->store->insertBatch(array_map(static fn (int $h) => HeaderParser::parse($chain->at($h)), range(1, 30)));
        return $chain;
    }

    public function testTheTableIsWithoutRowidWithItsConstraintsAndIndexes(): void
    {
        $sql = (string) $this->db->value("SELECT sql FROM sqlite_master WHERE name = 'conversion_checks'");
        $this->assertStringContains('WITHOUT ROWID', $sql);
        $this->assertSame(
            ['tx_hash', 'height', 'rule_version', 'hard_fork', 'verdict', 'reason', 'price_field', 'price', 'r0', 'fee', 'rate', 'expected', 'remainder'],
            array_map(static fn (array $r): string => (string) $r['name'], $this->db->all("SELECT name FROM pragma_table_info('conversion_checks') ORDER BY cid")),
        );
        $this->assertSame(1, (int) $this->db->value("SELECT count(*) FROM sqlite_master WHERE type = 'index' AND name = 'conversion_checks_height'"));
        $this->assertSame(
            ['verdict', 'hard_fork'],
            array_map(static fn (array $r): string => (string) $r['name'], $this->db->all("SELECT name FROM pragma_index_info('conversion_checks_verdict') ORDER BY seqno")),
        );
        $this->assertSame('tx_hash', $this->db->value("SELECT name FROM pragma_table_info('conversion_checks') WHERE pk = 1"));
    }

    public function testTheTableRefusesWhatItShouldRefuse(): void
    {
        $this->chainWithRecords();
        $this->txStore->storeBlocks([$this->tx(5, 0, 'transfer', 0, 0, 0)], 5);
        $hash = TxServer::hashOf(5, 0);
        $good = [
            'tx_hash' => $hash, 'height' => 5, 'rule_version' => 1, 'hard_fork' => 11, 'verdict' => 'match', 'reason' => 'checked',
            'price_field' => 'stable', 'price' => '1', 'r0' => '1', 'fee' => '0', 'rate' => '1', 'expected' => '1', 'remainder' => '0',
        ];
        $insert = function (array $row): void {
            $this->db->exec(
                'INSERT INTO conversion_checks (' . implode(', ', array_keys($row)) . ') VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')',
                array_values($row),
            );
        };
        $bad = [
            'a verdict that is not one' => ['verdict' => 'ok'],
            'a reason that is not on the list' => ['reason' => 'because'],
            'a match with a reason of not recomputing' => ['reason' => 'pr_missing'],
            'not recomputed with the reason checked' => ['verdict' => 'not_recomputed', 'expected' => null],
            'a price field that is not one' => ['price_field' => 'spot'],
            'a transaction that is not stored' => ['tx_hash' => str_repeat('ee', 32)],
            'a match without an expected amount' => ['expected' => null],
            'a missing height' => ['height' => null],
        ];
        foreach ($bad as $what => $change) {
            $row = $change + $good;
            $this->assertThrows(\PDOException::class, static fn () => $insert($row), $what);
        }
        $insert($good);
        $this->assertThrows(\PDOException::class, static fn () => $insert($good), 'a transaction twice');
        $this->db->exec('DELETE FROM conversion_checks');
        $insert(['verdict' => 'not_recomputed', 'reason' => 'pr_missing', 'price_field' => null, 'price' => null, 'r0' => null, 'fee' => null, 'rate' => null, 'expected' => null, 'remainder' => null] + $good);
        $this->assertSame(1, $this->count('conversion_checks'));
    }

    public function testChecksAreStoredWithTheRowsAndEveryNeedyRowGetsOne(): void
    {
        $this->chainWithRecords();
        $rows = [
            $this->tx(5, 0, 'transfer', 0, 0, 0),
            $this->tx(5, 1, 'redeem_stable', 4, 1000000000000, 0),     // no record at 4 (blocks are synthetic): pr_missing or computed
            $this->tx(6, 0, 'unknown', 0, 0, 0),
            $this->tx(6, 1, 'audit', 0, 7, 7, 'ZPH', 'ZPH'),
            $this->tx(7, 0, 'transfer', 3, 0, 0),
        ];
        $counts = $this->txStore->storeBlocks($rows, 7);
        $this->assertSame(4, $counts['checks']);
        $this->assertSame(4, $this->count('conversion_checks'));
        $this->assertSame($counts['checks'], $counts['match'] + $counts['mismatch'] + $counts['not_recomputed']);
        $byTx = [];
        foreach ($this->db->all('SELECT tx_hash, verdict, reason, hard_fork FROM conversion_checks') as $r) {
            $byTx[$r['tx_hash']] = $r;
        }
        $this->assertFalse(isset($byTx[TxServer::hashOf(5, 0)]), 'a plain transfer has no row');
        $this->assertSame('unknown_type', $byTx[TxServer::hashOf(6, 0)]['reason']);
        $this->assertSame('transfer_with_conversion_fields', $byTx[TxServer::hashOf(7, 0)]['reason']);
        $this->assertSame('type_not_allowed', $byTx[TxServer::hashOf(6, 1)]['reason'], 'the synthetic chain is in hard fork ' . $byTx[TxServer::hashOf(6, 1)]['hard_fork']);
    }

    public function testARecordThatIsNotStoredGivesPrMissingAndAnOutOfWindowAgeIsNotAMismatch(): void
    {
        $this->storeRecordBlock(905996);
        $this->storeBlock(905998);
        $t = $this->fixtureTx905998();
        $moved = new Transaction($t->hash, $t->height, $t->idx, $t->version, $t->rctType, $t->source, $t->destination, $t->type, $t->inputs, $t->outputs, $t->fee, 905987, $t->amountBurnt, $t->amountMinted);
        $this->txStore->storeBlocks([$moved], 905998);
        $this->assertSame('age_out_of_window', $this->db->value('SELECT reason FROM conversion_checks'));
        $this->db->exec('DELETE FROM conversion_checks');
        $this->db->exec('DELETE FROM transactions');
        $this->db->exec("DELETE FROM meta WHERE key = 'tx_indexed_height'");
        $this->db->exec('DELETE FROM pricing_records WHERE height = 905996');
        $this->txStore->storeBlocks([$t], 905998);
        $this->assertSame(['not_recomputed', 'pr_missing'], array_values(array_map('strval', array_values((array) $this->db->one('SELECT verdict, reason FROM conversion_checks')))));
    }

    private function fixtureTx905998(): Transaction
    {
        $entry = $this->fixture('get_transactions__decode_as_json-true__txs_hashes-64b3f44e2521')['txs'][0];
        return TransactionParser::parse($entry, $entry['tx_hash'], 905998, 0);
    }

    public function testAnIncompleteRecordAndAMismatchAreStoredAndDoNotStopAnything(): void
    {
        $this->storeRecordBlock(905996);
        $this->storeBlock(905998);
        $t = $this->fixtureTx905998();
        $wrong = new Transaction($t->hash, $t->height, $t->idx, $t->version, $t->rctType, $t->source, $t->destination, $t->type, $t->inputs, $t->outputs, $t->fee, $t->pricingRecordHeight, $t->amountBurnt, $t->amountMinted + 1);
        $counts = $this->txStore->storeBlocks([$wrong], 905998);
        $this->assertSame(['checks' => 1, 'match' => 0, 'mismatch' => 1, 'not_recomputed' => 0], $counts);
        $row = $this->db->one('SELECT * FROM conversion_checks');
        $this->assertSame(['mismatch', 'checked', '8566240310000000'], [$row['verdict'], $row['reason'], $row['expected']]);
        $this->assertSame(905998, $this->txStore->indexedHeight(), 'the mark moved: a mismatch stops nothing');
        // Zeroing the yield price makes the record incomplete for hard fork 11.
        $this->db->exec('UPDATE pricing_records SET yield_price = 0 WHERE height = 905996');
        $this->db->transaction(fn () => (new ConversionCheckStore($this->db))->clear());
        $this->db->transaction(fn () => (new ConversionCheckStore($this->db))->checkAndStore([[
            'hash' => $t->hash, 'height' => 905998, 'type' => $t->type, 'burnt' => $t->amountBurnt, 'minted' => $t->amountMinted, 'prh' => 905996,
        ]]));
        $this->assertSame(['not_recomputed', 'pr_incomplete'], [$this->db->value('SELECT verdict FROM conversion_checks'), $this->db->value('SELECT reason FROM conversion_checks')]);
    }

    public function testARollbackDeletesTheChecksAboveTheForkBeforeTheTransactions(): void
    {
        $this->storeRecordBlock(905996);
        $this->storeBlock(905998);
        $this->txStore->storeBlocks([$this->fixtureTx905998()], 905998);
        $this->assertSame(1, $this->count('conversion_checks'));
        // The checks of blocks below the fork stay.
        $this->assertSame(0, $this->store->rollbackAbove(905998, 'own-a', 1));
        $this->assertSame(1, $this->count('conversion_checks'));
        $this->assertSame(1, $this->store->rollbackAbove(905997, 'own-a', 1));
        $this->assertSame(0, $this->count('conversion_checks'));
        $this->assertSame(0, $this->count('transactions'));
        $this->assertSame(905996, $this->store->top()['height']);
        // Done again on the new branch, the check comes back with the row.
        $this->storeBlock(905998);
        $this->txStore->storeBlocks([$this->fixtureTx905998()], 905998);
        $this->assertSame(1, $this->count('conversion_checks'));
    }

    public function testARollbackKeepsTheChecksBelowTheFork(): void
    {
        $this->chainWithRecords();
        $this->txStore->storeBlocks([$this->tx(5, 0, 'unknown', 0, 0, 0), $this->tx(9, 0, 'unknown', 0, 0, 0), $this->tx(9, 1, 'unknown', 0, 0, 0)], 10);
        $this->assertSame(3, $this->count('conversion_checks'));
        $this->store->rollbackAbove(8, 'own-a', 1);
        $this->assertSame(1, $this->count('conversion_checks'));
        $this->assertSame(1, $this->count('transactions'));
    }

    public function testTheTableIsDerivedAndCanBeEmptiedAndRefilled(): void
    {
        $this->storeRecordBlock(905996);
        $this->storeBlock(905998);
        $this->txStore->storeBlocks([$this->fixtureTx905998()], 905998);
        $before = $this->db->all('SELECT * FROM conversion_checks');
        $store = new ConversionCheckStore($this->db);
        $this->db->transaction(static fn () => $store->clear());
        $this->assertSame(0, $this->count('conversion_checks'));
        $t = $this->fixtureTx905998();
        $this->db->transaction(static fn () => $store->checkAndStore([[
            'hash' => $t->hash, 'height' => 905998, 'type' => $t->type, 'burnt' => $t->amountBurnt, 'minted' => $t->amountMinted, 'prh' => 905996,
        ]]));
        $this->assertSame($before, $this->db->all('SELECT * FROM conversion_checks'));
    }

    public function testAConversionWithoutAStoredBlockIsALogicError(): void
    {
        $this->assertThrows(\LogicException::class, fn () => (new ConversionCheckStore($this->db))->checkAndStore([[
            'hash' => str_repeat('a', 64), 'height' => 5, 'type' => 'redeem_stable', 'burnt' => 1, 'minted' => 1, 'prh' => 4,
        ]]));
    }

    public function testAVersion1DatabaseGetsTheTableByMigration(): void
    {
        $db = Database::memory();
        Schema::migrate($db, [1 => Schema::migrations()[1]]);
        $this->assertSame(0, (int) $db->value("SELECT count(*) FROM sqlite_master WHERE name = 'conversion_checks'"));
        Schema::migrate($db);
        $this->assertSame(1, (int) $db->value("SELECT count(*) FROM sqlite_master WHERE name = 'conversion_checks'"));
        $this->assertTrue(Schema::VERSION === 2, 'the table is part of version 2');
    }

    public function testTheLogFieldsHoldTheCountsOfTheChecks(): void
    {
        $summary = new TxSummary(TxSummary::COMPLETE, 5, 9, 2, 7, 3, '', 6, 4, 1, 1);
        $this->assertSame('tx=complete txfrom=5 txto=9 txblocks=2 txs=7 txcalls=3 checks=6 match=4 mismatch=1 notrecomputed=1', $summary->logFields());
        $this->assertSame('tx=off', TxSummary::off()->logFields());
        $this->assertSame('tx=skipped', TxSummary::skipped()->logFields());
    }
}
