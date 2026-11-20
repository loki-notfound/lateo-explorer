<?php

declare(strict_types=1);

namespace Lateo\Explorer\Tests;

use Lateo\Explorer\Chain\Asset;
use Lateo\Explorer\Cli\RecomputeApplication;
use Lateo\Explorer\Cli\ShapesApplication;
use Lateo\Explorer\Cli\VectorsApplication;
use Lateo\Explorer\Db\ChainStore;
use Lateo\Explorer\Db\Database;
use Lateo\Explorer\Db\Schema;
use Lateo\Explorer\Db\TransactionStore;
use Lateo\Explorer\Indexer\HeaderParser;
use Lateo\Explorer\Indexer\Transaction;
use Lateo\Explorer\Indexer\TransactionIndexer;
use Lateo\Explorer\Indexer\TransactionParser;
use Lateo\Explorer\Tests\Support\FakeChain;
use Lateo\Explorer\Tests\Support\IndexerTestBase;
use Lateo\Explorer\Tests\Support\SkipTest;
use Lateo\Explorer\Tests\Support\TxServer;

/**
 * bin/recompute.php, bin/vectors.php and bin/shapes.php (their classes, and the scripts as processes), on a
 * database built from the recorded blocks: four recorded blocks with a conversion or an audit
 * and the redeem of block 905998.
 */
final class CommandsTest extends IndexerTestBase
{
    /** The nine fields of a pricing record in the order of the vector format. */
    private const NINE = ['spot', 'moving_average', 'stable', 'stable_ma', 'reserve', 'reserve_ma', 'reserve_ratio', 'reserve_ratio_ma', 'yield_price'];

    /** @return array<string,mixed> */
    private function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/fixtures/rpc/' . $name . '.json'), true, 512, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
    }

    /** Fill $db with the recorded blocks and their transactions, the checks made as the indexer makes them. */
    private function load(Database $db): void
    {
        $chain = new ChainStore($db);
        $txs = new TransactionStore($db);
        $config = $this->config(['tx_backfill_enabled' => true]);
        foreach ([[120974, 120972], [295006, 295004], [400878, 400876], [481502, null]] as [$height, $record]) {
            if ($record !== null) {
                $range = $this->fixture('get_block_headers_range__end_height-' . $record . '__start_height-' . $record);
                $chain->insertBatch([HeaderParser::parse($range['result']['headers'][0])]);
            }
            $chain->insertBatch([HeaderParser::parse($this->fixture('get_block__height-' . $height)['result']['block_header'])]);
            $txs->storeBlocks([], $record ?? $height - 1);
            $summary = (new TransactionIndexer($this->client($config), $chain, $txs, $config->limits, $this->clock, $this->clock->now(), 'own-a'))->run();
            $this->assertSame('complete', $summary->outcome, $summary->note);
        }
        $range = $this->fixture('get_block_headers_range__end_height-905996__start_height-905996');
        $chain->insertBatch([HeaderParser::parse($range['result']['headers'][0])]);
        $chain->insertBatch([HeaderParser::parse($this->fixture('get_block__height-905998')['result']['block_header'])]);
        $entry = $this->fixture('get_transactions__decode_as_json-true__txs_hashes-64b3f44e2521')['txs'][0];
        $txs->storeBlocks([TransactionParser::parse($entry, $entry['tx_hash'], 905998, 0)], 905998);
    }

    /** @return array{0:int,1:string,2:string} exit code, standard output, standard error */
    private function recompute(Database $db, ?string $lock = null): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $lock ??= $this->scratch() . '/indexer.lock';
        $code = (new RecomputeApplication($this->config(), static fn () => $db, $out, $err, $lock))->run();
        rewind($out);
        rewind($err);
        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    /** @return array{0:int,1:string,2:string} */
    private function vectors(Database $db, bool $all): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $code = (new VectorsApplication(static fn () => $db, $this->clock, $out, $err))->run($all);
        rewind($out);
        rewind($err);
        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    /** @return array<string,mixed> */
    private function decode(string $json): array
    {
        return json_decode($json, true, 512, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
    }

    // --- recompute ----------------------------------------------------------------------------

    public function testRecomputeRefillsTheTableAndSummarisesWithExitCodeZero(): void
    {
        $this->load($this->db);
        $before = $this->db->all('SELECT * FROM conversion_checks ORDER BY tx_hash');
        $this->assertCount(7, $before);
        // Damage the table: a stale row, a missing row, a changed row.
        $this->db->exec("UPDATE conversion_checks SET verdict = 'mismatch', expected = '1' WHERE height = 120974");
        $this->db->exec('DELETE FROM conversion_checks WHERE height = 400878');
        $this->db->exec('UPDATE conversion_checks SET rule_version = 0');

        [$code, $out, $err] = $this->recompute($this->db);
        $this->assertSame([0, ''], [$code, $err], $out);
        $this->assertSame($before, $this->db->all('SELECT * FROM conversion_checks ORDER BY tx_hash'), 'refilled from the other tables');
        $this->assertStringContains('recompute: rule version 1, 7 checks', $out);
        $this->assertStringContains('redeem_reserve  hf 3-4: match=1 mismatch=0 nonzero_remainders=0', $out);
        $this->assertStringContains('mint_reserve    hf 5: match=2 mismatch=0 nonzero_remainders=0', $out);
        $this->assertStringContains('mint_yield      hf >=6: match=1 mismatch=0 nonzero_remainders=0', $out);
        $this->assertStringContains('audit           audit hf 8: match=2 mismatch=0 nonzero_remainders=0', $out);
        $this->assertStringContains('redeem_stable   hf >=6: match=1 mismatch=0 nonzero_remainders=0', $out);
        $this->assertStringContains('total: checks=7 match=7 mismatch=0 not_recomputed=0 nonzero_remainders=0', $out);
        $this->assertSame(0, preg_match('/[0-9a-f]{64}/', $out), 'no transaction hash is printed');
    }

    public function testRecomputeExitsWithTwoOnAMismatchAndPrintsNoHash(): void
    {
        $this->load($this->db);
        $this->db->exec('UPDATE transactions SET amount_minted = amount_minted + 1 WHERE height = 905998');
        [$code, $out, $err] = $this->recompute($this->db);
        $this->assertSame([2, ''], [$code, $err]);
        $this->assertStringContains('redeem_stable   hf >=6: match=0 mismatch=1', $out);
        $this->assertStringContains('total: checks=7 match=6 mismatch=1 not_recomputed=0', $out);
        $this->assertSame(0, preg_match('/[0-9a-f]{64}/', $out));
        $this->assertSame(1, $this->count("conversion_checks WHERE verdict = 'mismatch'"), 'the row is in the table for the reviewing session');
    }

    public function testRecomputeCountsEveryReasonAndTheNonZeroRemainders(): void
    {
        $this->load($this->db);
        // A reserve redeem whose record is out of the window, and a stable redeem with a remainder.
        $this->db->exec("UPDATE transactions SET pricing_record_height = 120900 WHERE height = 120974 AND tx_type = 'redeem_reserve'");
        $minted = gmp_strval(gmp_div_q(gmp_mul(123456789, '8566240310000'), '1000000000000'));
        $this->db->exec('UPDATE transactions SET amount_burnt = 123456789, amount_minted = ? WHERE height = 905998', [(int) $minted]);
        [$code, $out] = $this->recompute($this->db);
        $this->assertSame(0, $code, 'a conversion that cannot be recomputed is not a mismatch');
        $this->assertStringContains('redeem_reserve  hf 3-4: match=0 mismatch=0 age_out_of_window=1 nonzero_remainders=0', $out);
        $this->assertStringContains('redeem_stable   hf >=6: match=1 mismatch=0 nonzero_remainders=1', $out);
        $this->assertStringContains('total: checks=7 match=6 mismatch=0 not_recomputed=1 nonzero_remainders=1', $out);
    }

    public function testRecomputeFillsInBatches(): void
    {
        $chain = FakeChain::synthetic(1, 30);
        $this->store->insertBatch(array_map(static fn (int $h) => HeaderParser::parse($chain->at($h)), range(1, 30)));
        $rows = [];
        foreach ([5, 6, 7] as $height) {
            for ($i = 0; $i < 400; $i++) {
                $rows[] = new Transaction(TxServer::hashOf($height, $i), $height, $i, 3, 6, Asset::fromChain('ZPH'), Asset::fromChain('ZPH'), 'unknown', 1, 2, 1, 0, 0, 0, 0);
            }
        }
        (new TransactionStore($this->db))->storeBlocks($rows, 7);
        $this->assertSame(1200, $this->count('conversion_checks'));
        $this->db->exec('DELETE FROM conversion_checks WHERE height = 6');
        [$code, $out] = $this->recompute($this->db);
        $this->assertSame(0, $code);
        $this->assertSame(1200, $this->count('conversion_checks'), 'three batches of at most ' . RecomputeApplication::BATCH);
        $this->assertSame(1200, (int) $this->db->value("SELECT count(DISTINCT tx_hash) FROM conversion_checks WHERE reason = 'unknown_type'"));
        $this->assertStringContains('total: checks=1200 match=0 mismatch=0 not_recomputed=1200', $out);
        $this->assertStringContains('unknown         hf >=6: match=0 mismatch=0 unknown_type=1200', $out);
    }

    public function testRecomputeTakesTheIndexersLockAndChangesNothingWhenItIsHeld(): void
    {
        $this->load($this->db);
        $lock = $this->scratch() . '/indexer.lock';
        $holder = fopen($lock, 'c');
        $this->assertTrue($holder !== false && flock($holder, LOCK_EX | LOCK_NB), 'the indexer holds the lock');
        $before = $this->db->all('SELECT * FROM conversion_checks ORDER BY tx_hash');
        [$code, $out, $err] = $this->recompute($this->db, $lock);
        $this->assertSame([1, ''], [$code, $out]);
        $this->assertStringContains('the indexer holds the lock', $err);
        $this->assertSame($before, $this->db->all('SELECT * FROM conversion_checks ORDER BY tx_hash'));
        flock($holder, LOCK_UN);
        fclose($holder);
        [$code] = $this->recompute($this->db, $lock);
        $this->assertSame(0, $code, 'it runs once the lock is free');
    }

    public function testRecomputeReportsAnErrorWithoutWordingAFinding(): void
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $code = (new RecomputeApplication($this->config(), static function (): Database {
            throw new \RuntimeException('database cannot be opened');
        }, $out, $err, $this->scratch() . '/x.lock'))->run();
        rewind($err);
        $this->assertSame(1, $code);
        $this->assertSame("recompute: stopped: database cannot be opened\n", stream_get_contents($err));
    }

    public function testTheRegimesOfTheSummary(): void
    {
        $this->assertSame('hf <3', RecomputeApplication::regime('mint_stable', 2));
        $this->assertSame('hf 3-4', RecomputeApplication::regime('mint_stable', 3));
        $this->assertSame('hf 3-4', RecomputeApplication::regime('mint_stable', 4));
        $this->assertSame('hf 5', RecomputeApplication::regime('mint_stable', 5));
        $this->assertSame('hf >=6', RecomputeApplication::regime('mint_stable', 6));
        $this->assertSame('hf >=6', RecomputeApplication::regime('redeem_yield', 11));
        $this->assertSame('audit hf 8', RecomputeApplication::regime('audit', 8));
        $this->assertSame('audit hf 10', RecomputeApplication::regime('audit', 10));
        $this->assertSame('audit other', RecomputeApplication::regime('audit', 11));
    }

    // --- vectors ------------------------------------------------------------------------------

    public function testTheSampleIsValidJsonWithTheFormatFieldsAndDecimalStrings(): void
    {
        $this->load($this->db);
        [$code, $json, $err] = $this->vectors($this->db, false);
        $this->assertSame([0, ''], [$code, $err]);
        $doc = $this->decode($json);
        $this->assertSame(
            ['format', 'format_version', 'status', 'daemon', 'generator', 'constants', 'vectors'],
            array_keys($doc),
        );
        $this->assertSame(['zephyr-conversion-vectors', 1, 'proposal'], [$doc['format'], $doc['format_version'], $doc['status']]);
        $this->assertCount(26, $doc['vectors']);
        $this->assertSame(['name' => 'zephyr', 'version' => '2.3.0', 'commit' => '3adbb517a'], $doc['daemon']);
        $this->assertSame(['name' => 'lateo-explorer', 'version' => 'rule-1', 'date' => '2027-01-15'], $doc['generator']);
        $this->assertSame(['coin' => '1000000000000', 'rounding_modulus' => '10000'], $doc['constants']);
        // The first of each kind: five real transactions of the seven, then the synthetic negatives.
        $real = array_slice($doc['vectors'], 0, 5);
        $this->assertSame(
            ['mainnet-120974-', 'mainnet-295006-', 'mainnet-400878-', 'mainnet-481502-', 'mainnet-905998-'],
            array_map(static fn (array $v): string => preg_replace('/[0-9a-f]{12}\z/', '', $v['id']), $real),
        );
        $this->assertSame(
            ['REDEEM_RESERVE', 'MINT_RESERVE', 'MINT_YIELD', 'AUDIT_ZEPH', 'REDEEM_STABLE'],
            array_column($real, 'tx_type'),
        );
        foreach ($real as $v) {
            $this->assertSame(['id', 'source', 'hard_fork', 'block_height', 'pricing_record_height', 'tx_type', 'source_asset', 'dest_asset', 'amount_burnt', 'amount_minted'], array_slice(array_keys($v), 0, 10));
            $this->assertSame(['network' => 'mainnet', 'tx_hash' => $v['source']['tx_hash'], 'synthetic' => false], $v['source']);
            $this->assertSame(1, preg_match('/\A[0-9a-f]{64}\z/', $v['source']['tx_hash']));
            $this->assertSame('mainnet-' . $v['block_height'] . '-' . substr($v['source']['tx_hash'], 0, 12), $v['id']);
            foreach (['amount_burnt', 'amount_minted'] as $k) {
                $this->assertSame(1, preg_match('/\A(0|[1-9][0-9]*)\z/', $v[$k]), $k . ' is a decimal string');
            }
            $e = $v['expected'];
            $this->assertSame(['pr_complete', 'age', 'age_valid', 'price_field', 'price', 'rate_before_fee', 'fee', 'rate', 'minted', 'remainder', 'valid'], array_keys($e));
            foreach (['rate_before_fee', 'fee', 'rate', 'minted', 'remainder'] as $k) {
                $this->assertSame(1, preg_match('/\A(0|[1-9][0-9]*)\z/', $e[$k]), $k . ' is a decimal string');
            }
            $this->assertTrue($e['valid'] && $e['pr_complete'] && $e['age_valid']);
            $this->assertSame($v['amount_minted'], $e['minted']);
            if ($v['tx_type'] !== 'AUDIT_ZEPH') {
                $this->assertSame(self::NINE, array_keys($v['pricing_record']));
                foreach ($v['pricing_record'] as $field => $value) {
                    $this->assertSame(1, preg_match('/\A(0|[1-9][0-9]*)\z/', $value), $field . ' is a decimal string');
                }
                $this->assertSame($v['block_height'] - $v['pricing_record_height'], $e['age']);
            }
        }
        // The redeem of block 905998 in full.
        $v = $real[4];
        $this->assertSame(['ZSD', 'ZPH', 11, 905998, 905996, '1000000000000000', '8566240310000000'], [$v['source_asset'], $v['dest_asset'], $v['hard_fork'], $v['block_height'], $v['pricing_record_height'], $v['amount_burnt'], $v['amount_minted']]);
        $this->assertSame('8574815130000', $v['pricing_record']['stable']);
        $this->assertSame('1995233720000', $v['pricing_record']['yield_price']);
        $this->assertSame(
            ['pr_complete' => true, 'age' => 2, 'age_valid' => true, 'price_field' => 'stable', 'price' => '8574815130000', 'rate_before_fee' => '8574815130000', 'fee' => '8574815130', 'rate' => '8566240310000', 'minted' => '8566240310000000', 'remainder' => '0', 'valid' => true],
            $v['expected'],
        );
        // The audit has no record and no price.
        $a = $real[3];
        $this->assertSame(['ZEPH', 'ZPH', 0, false], [$a['source_asset'], $a['dest_asset'], $a['pricing_record_height'], array_key_exists('pricing_record', $a)]);
        $this->assertSame([null, null, null, '1000000000000', '0', '1000000000000'], [$a['expected']['age'], $a['expected']['price_field'], $a['expected']['price'], $a['expected']['rate_before_fee'], $a['expected']['fee'], $a['expected']['rate']]);
        $this->assertSame($a['amount_burnt'], $a['expected']['minted']);
    }

    public function testAllWritesEveryCheckedConversionAndAudit(): void
    {
        $this->load($this->db);
        [$code, $json] = $this->vectors($this->db, true);
        $this->assertSame(0, $code);
        $doc = $this->decode($json);
        $this->assertCount(7, $doc['vectors']);
        $heights = array_column($doc['vectors'], 'block_height');
        $sorted = $heights;
        sort($sorted);
        $this->assertSame($sorted, $heights, 'in the order of height');
        $this->assertSame([120974, 295006, 295006, 400878, 481502, 481502, 905998], $heights);
        $this->assertSame(7, count(array_unique(array_column($doc['vectors'], 'id'))));
    }

    public function testTheOutputIsTheSameEveryTimeAndOnlyCheckedRowsAreWritten(): void
    {
        $this->load($this->db);
        [, $a] = $this->vectors($this->db, false);
        [, $b] = $this->vectors($this->db, false);
        $this->assertSame($a, $b);
        [, $c] = $this->vectors($this->db, true);
        [, $d] = $this->vectors($this->db, true);
        $this->assertSame($c, $d);
        $this->assertSame("\n", substr($a, -1));
        // A transaction that is not recomputed is not written; a mismatch is, with valid false.
        $this->db->exec('UPDATE transactions SET pricing_record_height = 905980 WHERE height = 905998');
        $this->db->exec('UPDATE transactions SET amount_minted = amount_minted + 1 WHERE height = 400878');
        $this->recompute($this->db);
        $doc = $this->decode($this->vectors($this->db, true)[1]);
        $this->assertSame([120974, 295006, 295006, 400878, 481502, 481502], array_column($doc['vectors'], 'block_height'));
        $mismatch = $doc['vectors'][3];
        $this->assertFalse($mismatch['expected']['valid']);
        $this->assertSame('MINT_YIELD', $mismatch['tx_type']);
        $this->assertTrue($mismatch['expected']['minted'] !== $mismatch['amount_minted']);
    }

    public function testAnEmptyDatabaseGivesAnEmptyListAndNoTableAnError(): void
    {
        [$code, $json] = $this->vectors($this->db, true);
        $this->assertSame(0, $code);
        $doc = $this->decode($json);
        $this->assertSame([], $doc['vectors']);
        $bare = Database::memory();
        [$code, $out, $err] = $this->vectors($bare, false);
        $this->assertSame([1, ''], [$code, $out]);
        $this->assertStringContains('no conversion checks', $err);
    }

    public function testTheSampleTakesTheLowestHeightThenPositionForEachKind(): void
    {
        $this->load($this->db);
        // Two more audits and two more mint_reserve in later blocks of other hard forks do not displace the first ones:
        // here the first mint_reserve of hf 5 is block 295006 position of the lower index.
        $doc = $this->decode($this->vectors($this->db, false)[1]);
        $mint = array_values(array_filter($doc['vectors'], static fn (array $v): bool => $v['tx_type'] === 'MINT_RESERVE' && !$v['source']['synthetic']));
        $this->assertCount(1, $mint);
        $first = $this->db->value("SELECT hash FROM transactions WHERE tx_type = 'mint_reserve' ORDER BY height, idx LIMIT 1");
        $this->assertSame($first, $mint[0]['source']['tx_hash']);
    }

    public function testTheSampleEndsWithTheSyntheticNegativesInTheirOrder(): void
    {
        $this->load($this->db);
        $doc = $this->decode($this->vectors($this->db, false)[1]);
        $vectors = $doc['vectors'];
        $this->assertSame([1, 'proposal'], [$doc['format_version'], $doc['status']]);
        $this->assertCount(26, $vectors);
        foreach (array_slice($vectors, 0, 5) as $v) {
            $this->assertSame(['network', 'tx_hash', 'synthetic'], array_keys($v['source']), 'a real entry has no mutation field');
            $this->assertFalse($v['source']['synthetic']);
        }
        $synthetic = array_slice($vectors, 5);
        $got = array_map(static fn (array $v): array => [$v['id'], $v['source']['mutation']], $synthetic);
        $hashes = [];
        foreach (array_slice($vectors, 0, 5) as $v) {
            $hashes[$v['block_height']] = $v['source']['tx_hash'];
        }
        $prefix = static fn (int $h): string => 'synthetic-' . $h . '-' . substr($hashes[$h], 0, 12) . '-';
        $expected = [];
        foreach ([[120974, 'reserve_ma'], [295006, 'reserve_ma'], [400878, 'yield_price'], [905998, 'stable']] as [$h, $field]) {
            foreach (['amount_minted plus 1', 'amount_minted minus 1', 'age 0', 'age 11', $field . ' zero'] as $i => $mutation) {
                $expected[] = [$prefix($h) . ($i + 1), $mutation];
            }
        }
        $expected[] = [$prefix(481502) . '1', 'amount_minted plus 1'];
        $this->assertSame($expected, $got);
        $this->assertSame(26 - 5, count(array_unique(array_column($synthetic, 'id'))));
        foreach ($synthetic as $v) {
            $this->assertTrue($v['source']['synthetic']);
            $this->assertSame(['network', 'tx_hash', 'synthetic', 'mutation'], array_keys($v['source']));
            $this->assertSame(1, preg_match('/\A[0-9a-f]{64}\z/', $v['source']['tx_hash']));
            $this->assertSame(['pr_complete', 'age', 'age_valid', 'price_field', 'price', 'rate_before_fee', 'fee', 'rate', 'minted', 'remainder', 'valid'], array_keys($v['expected']));
            $this->assertFalse($v['expected']['valid'], $v['id']);
        }
        $this->assertSame(
            0,
            count(array_filter($synthetic, static fn (array $v): bool => $v['source']['mutation'] === 'hard fork 5 rule on a hard fork 6 block')),
            'the fee of a stable or reserve conversion is the same in hard fork 5 and 6, so that mutation does not change the result and is left out',
        );
        // --all writes real entries only.
        $all = $this->decode($this->vectors($this->db, true)[1])['vectors'];
        $this->assertCount(7, $all);
        foreach ($all as $v) {
            $this->assertFalse($v['source']['synthetic']);
            $this->assertFalse(array_key_exists('mutation', $v['source']));
        }
    }

    public function testASyntheticVectorIsTheRuleOnAChangedCopyOfTheRealEntry(): void
    {
        $this->load($this->db);
        $vectors = $this->decode($this->vectors($this->db, false)[1])['vectors'];
        $real = $vectors[4]; // the redeem of block 905998
        $by = [];
        foreach (array_slice($vectors, 5) as $v) {
            if ($v['block_height'] === 905998) {
                $by[$v['source']['mutation']] = $v;
            }
        }
        $this->assertSame(['amount_minted plus 1', 'amount_minted minus 1', 'age 0', 'age 11', 'stable zero'], array_keys($by));
        $notRecomputed = static fn (bool $complete, int $age, bool $ageValid): array => [
            'pr_complete' => $complete, 'age' => $age, 'age_valid' => $ageValid,
            'price_field' => null, 'price' => null, 'rate_before_fee' => null, 'fee' => null, 'rate' => null,
            'minted' => null, 'remainder' => null, 'valid' => false,
        ];

        $plus = $by['amount_minted plus 1'];
        $this->assertSame('8566240310000001', $plus['amount_minted']);
        $this->assertSame($real['expected']['minted'], $plus['expected']['minted'], 'what the rule gives does not follow the changed amount');
        $this->assertSame([false, 2, true, true], [$plus['expected']['valid'], $plus['expected']['age'], $plus['expected']['age_valid'], $plus['expected']['pr_complete']]);
        $this->assertSame($real['pricing_record'], $plus['pricing_record']);
        $this->assertSame('8566240309999999', $by['amount_minted minus 1']['amount_minted']);

        $zero = $by['age 0'];
        $this->assertSame([905998, 0], [$zero['pricing_record_height'], $zero['expected']['age']]);
        $this->assertSame($notRecomputed(true, 0, false), $zero['expected']);
        $eleven = $by['age 11'];
        $this->assertSame([905987, 11], [$eleven['pricing_record_height'], $eleven['expected']['age']]);
        $this->assertSame($notRecomputed(true, 11, false), $eleven['expected']);

        $stable = $by['stable zero'];
        $this->assertSame('0', $stable['pricing_record']['stable']);
        $this->assertSame(array_keys($real['pricing_record']), array_keys($stable['pricing_record']), 'the fields keep their order');
        $this->assertSame($notRecomputed(false, 2, true), $stable['expected']);

        // The audit: only the amount changes; the rule still gives the amount of the chain.
        $audit = array_values(array_filter(array_slice($vectors, 5), static fn (array $v): bool => $v['tx_type'] === 'AUDIT_ZEPH'));
        $this->assertCount(1, $audit);
        $this->assertSame(['amount_minted plus 1', false, null, true], [$audit[0]['source']['mutation'], $audit[0]['expected']['valid'], $audit[0]['expected']['age'], $audit[0]['expected']['age_valid']]);
        $this->assertSame($vectors[3]['expected']['minted'], $audit[0]['expected']['minted']);
        $this->assertFalse(array_key_exists('pricing_record', $audit[0]));
    }

    public function testTheNegativesAreTheSameEveryTime(): void
    {
        $this->load($this->db);
        $this->assertSame($this->vectors($this->db, false), $this->vectors($this->db, false));
    }

    // --- shapes -------------------------------------------------------------------------------

    /** @return array{0:int,1:string,2:string} */
    private function shapes(Database $db): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $code = (new ShapesApplication(static fn () => $db, $out, $err))->run();
        rewind($out);
        rewind($err);
        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    public function testShapesPrintsCountsPerVersionAndTypeAndNeverAHash(): void
    {
        $this->load($this->db);
        // Two blocks of the load have a miner row from the indexer; give the 905998 block one by hand and
        // mark one transaction as stored by version 2 code and one with an untagged output.
        $this->db->exec('UPDATE transactions SET n_untagged_outputs = NULL WHERE height = 905998');
        $this->db->exec('UPDATE transactions SET n_untagged_outputs = 2 WHERE height = 481502 AND idx = 0');
        $this->db->exec('UPDATE miner_txs SET n_untagged_outputs = 1 WHERE height = 295006');
        $before = [$this->db->all('SELECT * FROM transactions ORDER BY hash'), $this->db->all('SELECT * FROM miner_txs ORDER BY height')];

        [$code, $out, $err] = $this->shapes($this->db);
        $this->assertSame([0, ''], [$code, $err]);
        $this->assertSame(0, preg_match('/[0-9a-f]{64}/', $out), 'no hash is printed');
        $total = $this->count('transactions');
        $this->assertStringContains("shapes: schema version 3, transactions stored up to height 905998\n", $out);
        $this->assertStringContains("transactions by version and rct_type:\n", $out);
        $perType = $this->db->all('SELECT version, rct_type, count(*) AS n, min(height) AS l, max(height) AS h FROM transactions GROUP BY version, rct_type ORDER BY version, rct_type');
        $this->assertTrue(count($perType) >= 1);
        foreach ($perType as $r) {
            $this->assertStringContains(sprintf("  version=%d rct_type=%d: count=%d lowest=%d highest=%d\n", $r['version'], $r['rct_type'], $r['n'], $r['l'], $r['h']), $out);
        }
        $this->assertSame($total, array_sum(array_column($perType, 'n')));
        $this->assertStringContains("miner transactions by version and rct_type:\n  version=3 rct_type=0: count=4 lowest=120974 highest=481502\n", $out);
        $this->assertStringContains("transactions with outputs of another variant than tagged_key: count=1 lowest=481502 highest=481502\n", $out);
        $this->assertStringContains("miner transactions with outputs of another variant than tagged_key: count=1 lowest=295006 highest=295006\n", $out);
        $this->assertStringContains("transactions with n_untagged_outputs NULL (stored before schema 3): count=1 lowest=905998 highest=905998\n", $out);
        $this->assertStringContains("miner transactions with n_untagged_outputs NULL: count=0\n", $out);
        $this->assertSame($before, [$this->db->all('SELECT * FROM transactions ORDER BY hash'), $this->db->all('SELECT * FROM miner_txs ORDER BY height')], 'read-only');
    }

    public function testShapesOnAnEmptyDatabaseAndOnAnOlderOne(): void
    {
        [$code, $out, $err] = $this->shapes($this->db);
        $this->assertSame([0, ''], [$code, $err]);
        $this->assertStringContains("transactions by version and rct_type:\n  none\n", $out);
        $this->assertStringContains("transactions stored up to height -\n", $out);
        $this->assertStringContains("miner transactions with outputs of another variant than tagged_key: count=0\n", $out);

        $old = Database::memory();
        $migrations = Schema::migrations();
        Schema::migrate($old, [1 => $migrations[1], 2 => $migrations[2]]);
        [$code, $out, $err] = $this->shapes($old);
        $this->assertSame([1, ''], [$code, $out]);
        $this->assertStringContains('schema version 2, not 3', $err);

        $bare = Database::memory();
        [$code, $out, $err] = $this->shapes($bare);
        $this->assertSame([1, ''], [$code, $out]);
        $this->assertStringContains('schema version 0', $err);

        $err = fopen('php://memory', 'w+');
        $code = (new ShapesApplication(static function (): Database {
            throw new \RuntimeException('database cannot be opened');
        }, fopen('php://memory', 'w+'), $err))->run();
        rewind($err);
        $this->assertSame(1, $code);
        $this->assertSame("shapes: stopped: database cannot be opened\n", stream_get_contents($err));
    }

    // --- the scripts as processes -------------------------------------------------------------

    /** @return array{0:int,1:string,2:string} */
    private function process(string $script, string ...$args): array
    {
        if (str_contains(self::$scratchBase, '://') || !function_exists('proc_open')) {
            throw new SkipTest('real processes and files are not available here');
        }
        $process = proc_open([PHP_BINARY, __DIR__ . '/../bin/' . $script, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new SkipTest('a process cannot be started');
        }
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $out, $err];
    }

    public function testTheScriptsWorkOnAFileDatabaseWithTheSettingsFile(): void
    {
        if (str_contains(self::$scratchBase, '://')) {
            throw new SkipTest('the scratch base is a stream wrapper, not a real directory');
        }
        $dir = $this->scratch();
        $file = $dir . '/explorer.sqlite';
        $writer = Database::openWritable($file);
        Schema::migrate($writer);
        $this->load($writer);
        unset($writer);
        gc_collect_cycles();
        $settings = $dir . '/settings.php';
        file_put_contents($settings, '<?php return ' . var_export([
            'nodes' => [
                ['label' => 'own-a', 'kind' => 'own', 'url' => 'http://' . self::A . ':17767'],
                ['label' => 'fallback', 'kind' => 'fallback', 'url' => 'https://' . self::F],
            ],
            'database' => $file,
            'cache_dir' => $dir . '/cache',
            'lock_file' => $dir . '/indexer.lock',
        ], true) . ';');

        [$code, $out, $err] = $this->process('vectors.php', '--settings=' . $settings, '--all');
        $this->assertSame([0, ''], [$code, $err]);
        $this->assertCount(7, $this->decode($out)['vectors']);
        [$code, $out] = $this->process('vectors.php', '--settings=' . $settings);
        $this->assertSame(0, $code);
        $this->assertCount(5 + 21, $this->decode($out)['vectors'], 'five real and the synthetic negatives');
        [$code, , $err] = $this->process('vectors.php', '--settings=' . $settings, '--all', '--sample');
        $this->assertSame(1, $code);
        $this->assertStringContains('usage:', $err);
        [$code] = $this->process('vectors.php');
        $this->assertSame(1, $code);

        [$code, $out, $err] = $this->process('shapes.php', '--settings=' . $settings);
        $this->assertSame([0, ''], [$code, $err]);
        $this->assertStringContains('shapes: schema version 3, transactions stored up to height 905998', $out);
        $this->assertSame(0, preg_match('/[0-9a-f]{64}/', $out));
        [$code, , $err] = $this->process('shapes.php', '--settings=' . $settings, '--all');
        $this->assertSame(1, $code);
        $this->assertStringContains('usage:', $err);
        [$code] = $this->process('shapes.php');
        $this->assertSame(1, $code);

        [$code, $out, $err] = $this->process('recompute.php', '--settings=' . $settings);
        $this->assertSame([0, ''], [$code, $err], $out);
        $this->assertStringContains('total: checks=7 match=7 mismatch=0', $out);
        [$code, , $err] = $this->process('recompute.php');
        $this->assertSame(1, $code);
        $this->assertStringContains('usage:', $err);

        $db = Database::openWritable($file);
        $db->exec('UPDATE transactions SET amount_minted = amount_minted + 1 WHERE height = 905998');
        unset($db);
        gc_collect_cycles();
        [$code, $out] = $this->process('recompute.php', '--settings=' . $settings);
        $this->assertSame(2, $code, 'a mismatch gives exit code 2');
        $this->assertStringContains('mismatch=1', $out);
    }
}
