<?php

declare(strict_types=1);

namespace Lateo\Explorer\Tests;

use Lateo\Explorer\Config\Config;
use Lateo\Explorer\Db\TransactionStore;
use Lateo\Explorer\Indexer\HeaderParser;
use Lateo\Explorer\Indexer\TransactionIndexer;
use Lateo\Explorer\Indexer\TxSummary;
use Lateo\Explorer\Rpc\ErrorCategory;
use Lateo\Explorer\Rpc\TransportFailure;
use Lateo\Explorer\Rpc\TransportResponse;
use Lateo\Explorer\Tests\Support\FakeChain;
use Lateo\Explorer\Tests\Support\FixtureTransport;
use Lateo\Explorer\Tests\Support\IndexerTestBase;
use Lateo\Explorer\Tests\Support\RecordedCall;
use Lateo\Explorer\Tests\Support\TxServer;

final class TransactionIndexerTest extends IndexerTestBase
{
    private TransactionStore $txStore;

    public function setUp(): void
    {
        parent::setUp();
        $this->txStore = new TransactionStore($this->db);
    }

    /** @param array<string,mixed> $limits */
    private function txConfig(array $limits = []): Config
    {
        return $this->config($limits + ['tx_backfill_enabled' => true]);
    }

    private function txIndexer(Config $config, ?float $startedAt = null, ?string $preferred = 'own-a'): TransactionIndexer
    {
        return new TransactionIndexer(
            $this->client($config),
            $this->store,
            $this->txStore,
            $config->limits,
            $this->clock,
            $startedAt ?? $this->clock->now(),
            $preferred,
        );
    }

    /** Store the headers of $chain (with the header indexer) and make the three hosts serve it with $server. */
    private function prepare(FakeChain $chain, ?Config $config = null): TxServer
    {
        $server = new TxServer($chain);
        foreach ([self::A, self::B, self::F] as $host) {
            $this->transport->serve($host, static fn (RecordedCall $c): TransportResponse => $server->respond($c));
        }
        $start = 0;
        while (!$chain->has($start)) {
            $start++;
        }
        $this->indexer($config ?? $this->txConfig(), $start)->run();
        $this->transport->calls = [];
        return $server;
    }

    private function mark(): ?int
    {
        return $this->txStore->indexedHeight();
    }

    // --- recorded blocks ----------------------------------------------------------------------

    /** Store the recorded header of $height, the way the header indexer would. */
    private function storeRecordedBlock(int $height): void
    {
        $d = json_decode(
            (string) file_get_contents(__DIR__ . '/fixtures/rpc/get_block__height-' . $height . '.json'),
            true,
            512,
            JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR,
        );
        $this->store->insertBatch([HeaderParser::parse($d['result']['block_header'])]);
    }

    public function testTheRecordedBlocksAreStoredFromTheRecordedAnswers(): void
    {
        $expected = [
            40010 => [1, ['transfer' => 1]],
            120974 => [19, ['transfer' => 18, 'redeem_reserve' => 1]],
            295006 => [22, ['transfer' => 20, 'mint_reserve' => 2]],
            400878 => [20, ['transfer' => 19, 'mint_yield' => 1]],
            481502 => [2, ['audit' => 2]],
        ];
        foreach ($expected as $height => [$count, $types]) {
            $this->setUp();
            $this->storeRecordedBlock($height);
            $summary = $this->txIndexer($this->txConfig())->run();
            $this->assertSame(TxSummary::COMPLETE, $summary->outcome, $height . ': ' . $summary->note);
            $this->assertSame(2, $summary->calls, 'one get_block and one /get_transactions');
            $this->assertSame($count, $summary->transactions);
            $this->assertSame(1, $summary->blocks);
            $this->assertSame([$height, $height], [$summary->fromHeight, $summary->toHeight]);
            $this->assertSame($height, $this->mark());
            $this->assertSame($count, $this->count('transactions'));
            $got = [];
            foreach ($this->db->all('SELECT tx_type, count(*) AS n FROM transactions GROUP BY tx_type ORDER BY tx_type') as $row) {
                $got[$row['tx_type']] = (int) $row['n'];
            }
            ksort($types);
            $this->assertSame($types, $got, (string) $height);
            $this->assertSame($count, (int) $this->db->value('SELECT count(DISTINCT idx) FROM transactions WHERE height = ?', [$height]));
            $this->assertSame(0, (int) $this->db->value('SELECT min(idx) FROM transactions'));
            foreach ($this->transport->calls as $call) {
                $this->assertTrue(in_array($call->host, [self::A, self::B], true), 'only own nodes');
            }
            $get = array_values(array_filter($this->transport->calls, static fn (RecordedCall $c): bool => $c->path === '/get_transactions'));
            $this->assertCount(1, $get);
            $this->assertTrue($get[0]->params['prune'] === true && $get[0]->params['decode_as_json'] === true);
            $this->assertCount($count, $get[0]->params['txs_hashes']);
        }
    }

    public function testTheRowsOfTheRecordedConversionAreExact(): void
    {
        $this->storeRecordedBlock(400878);
        $this->txIndexer($this->txConfig())->run();
        $row = $this->db->one("SELECT * FROM transactions WHERE tx_type = 'mint_yield'");
        $this->assertSame('2778cd5023a559249a3113bcff55b23cad41d4c64b090513ebfb917c6b96f161', $row['hash']);
        $this->assertSame(400878, $row['height']);
        $this->assertSame(19, $row['idx']);
        $this->assertSame(3, $row['version']);
        $this->assertSame(6, $row['rct_type']);
        $this->assertSame(['ZSD', 'old', 'ZYS', 'old'], [$row['source_asset'], $row['source_gen'], $row['dest_asset'], $row['dest_gen']]);
        $this->assertSame([1, 4, 3638031123, 400876, 3200000000000, 2737064512000], [
            $row['n_inputs'], $row['n_outputs'], $row['fee'], $row['pricing_record_height'], $row['amount_burnt'], $row['amount_minted'],
        ]);
    }

    // --- the shapes: untagged outputs and the miner transaction -------------------------------

    public function testTheMinerTransactionsAndTheShapesOfTheRecordedBlocksAreStored(): void
    {
        // height => [miner tx version, outputs]
        $expected = [40010 => [2, 2], 120974 => [3, 3], 295006 => [3, 2], 400878 => [3, 2], 481502 => [3, 1]];
        foreach ($expected as $height => [$version, $outputs]) {
            $this->setUp();
            $this->storeRecordedBlock($height);
            $this->txIndexer($this->txConfig())->run();
            $row = $this->db->one('SELECT * FROM miner_txs');
            $this->assertSame(1, $this->count('miner_txs'));
            $this->assertSame($this->db->value('SELECT miner_tx_hash FROM blocks WHERE height = ?', [$height]), $row['hash']);
            $this->assertSame([$height, $version, 0, $outputs, 0], [$row['height'], $row['version'], $row['rct_type'], $row['n_outputs'], $row['n_untagged_outputs']], (string) $height);
            $this->assertSame(0, (int) $this->db->value('SELECT count(*) FROM transactions WHERE n_untagged_outputs IS NULL OR n_untagged_outputs <> 0'));
        }
    }

    public function testOnlyBlocksWithTransactionsGetAMinerRowAndNoCallIsAdded(): void
    {
        $chain = FakeChain::synthetic(0, 30);
        $server = $this->prepare($chain);
        $summary = $this->txIndexer($this->txConfig())->run();
        $this->assertSame(TxSummary::COMPLETE, $summary->outcome, $summary->note);
        // The calls are those of a run without the miner rows: one get_block per block with
        // transactions and /get_transactions in calls of at most a hundred hashes.
        $this->assertSame(21, $summary->calls);
        $this->assertSame(20, $server->served['get_block']);
        $this->assertSame(1, $server->served['/get_transactions']);
        $this->assertSame(20, $this->count('miner_txs'));
        $this->assertSame(0, (int) $this->db->value('SELECT count(*) FROM miner_txs WHERE height % 3 = 0'), 'blocks without transactions have no row');
        $this->assertSame(
            (int) $this->db->value('SELECT count(*) FROM blocks WHERE num_txes > 0'),
            (int) $this->db->value('SELECT count(*) FROM miner_txs m JOIN blocks b ON b.height = m.height AND b.miner_tx_hash = m.hash'),
        );
        $this->assertSame([3, 0, 2, 0], array_map('intval', array_values($this->db->one('SELECT version, rct_type, n_outputs, n_untagged_outputs FROM miner_txs WHERE height = 1') ?? [])));
        $this->assertSame(30, $this->count('transactions'));
    }

    public function testUntaggedOutputsOfTransactionsAndOfMinerTransactionsAreCounted(): void
    {
        $chain = FakeChain::synthetic(1, 4);
        $server = $this->prepare($chain);
        $plain = ['amount' => 0, 'target' => ['to_key' => ['key' => str_repeat('ef', 32)]]];
        $server->entryFor = static fn (string $hash, int $height, int $idx): array => TxServer::entry(
            $hash,
            $height,
            TxServer::asJson($height === 2 ? ['vout' => [TxServer::output('ZPH'), $plain, $plain]] : []),
        );
        $server->mutateBlock = static function (int $height, array $result): array {
            if ($height === 4) {
                $miner = ['vout' => [TxServer::output('ZPH'), ['amount' => 1, 'target' => ['to_script' => ['script' => []]]]]];
                $result['json'] = TxServer::blockJson($height, $miner);
            }
            return $result;
        };
        $summary = $this->txIndexer($this->txConfig())->run();
        $this->assertSame(TxSummary::COMPLETE, $summary->outcome, $summary->note);
        $this->assertSame(['2:3' => 2], $this->untaggedByHeight('transactions', 2), 'block 2 has two transactions');
        $this->assertSame(0, (int) $this->db->value('SELECT sum(n_untagged_outputs) FROM transactions WHERE height <> 2'));
        $this->assertSame(1, (int) $this->db->value('SELECT n_untagged_outputs FROM miner_txs WHERE height = 4'));
        $this->assertSame(2, (int) $this->db->value('SELECT n_outputs FROM miner_txs WHERE height = 4'));
        $this->assertSame(0, (int) $this->db->value('SELECT sum(n_untagged_outputs) FROM miner_txs WHERE height <> 4'));
    }

    /** @return array<string,int> "<n_untagged_outputs>:<n_outputs>" => rows, for the rows of one height */
    private function untaggedByHeight(string $table, int $height): array
    {
        $out = [];
        foreach ($this->db->all('SELECT n_outputs, n_untagged_outputs FROM ' . $table . ' WHERE height = ?', [$height]) as $r) {
            $key = $r['n_untagged_outputs'] . ':' . $r['n_outputs'];
            $out[$key] = ($out[$key] ?? 0) + 1;
        }
        return $out;
    }

    public function testAMalformedMinerTransactionEndsTheRunAndStoresNothingOfTheGroup(): void
    {
        $chain = FakeChain::synthetic(1, 8);
        $server = $this->prepare($chain);
        $cases = [
            'no json' => static function (array $r): array { unset($r['json']); return $r; },
            'json that does not parse' => static function (array $r): array { $r['json'] = '{'; return $r; },
            'no miner_tx' => static function (array $r): array { $r['json'] = '{"tx_hashes": []}'; return $r; },
            'no version' => static function (array $r): array {
                $j = json_decode($r['json'], true);
                unset($j['miner_tx']['version']);
                $r['json'] = json_encode($j);
                return $r;
            },
            'no vout' => static function (array $r): array {
                $j = json_decode($r['json'], true);
                unset($j['miner_tx']['vout']);
                $r['json'] = json_encode($j);
                return $r;
            },
            'no type' => static function (array $r): array {
                $j = json_decode($r['json'], true);
                $j['miner_tx']['rct_signatures'] = [];
                $r['json'] = json_encode($j);
                return $r;
            },
            'another miner_tx_hash' => static function (array $r): array { $r['miner_tx_hash'] = str_repeat('1', 64); return $r; },
        ];
        foreach ($cases as $what => $change) {
            $server->mutateBlock = static fn (int $height, array $result): array => $height === 5 ? $change($result) : $result;
            $summary = $this->txIndexer($this->txConfig())->run();
            $this->assertSame(TxSummary::ERROR, $summary->outcome, $what);
            $this->assertStringContains('invalid-data', $summary->note, $what);
            // The blocks of the group before the bad one are not stored either: it is one group.
            $this->assertSame(0, $this->count('transactions'), $what);
            $this->assertSame(0, $this->count('miner_txs'), $what);
            $this->assertNull($this->mark(), $what);
        }
        // Without the fault the same run stores everything.
        $server->mutateBlock = null;
        $this->assertSame(TxSummary::COMPLETE, $this->txIndexer($this->txConfig())->run()->outcome);
        $this->assertSame((int) $this->db->value('SELECT count(*) FROM blocks WHERE num_txes > 0'), $this->count('miner_txs'));
    }

    // --- synthetic chains ---------------------------------------------------------------------

    public function testOneRunStoresEveryBlockAndAdvancesTheMarkOverEmptyBlocks(): void
    {
        $chain = FakeChain::synthetic(0, 30);
        $server = $this->prepare($chain);
        $summary = $this->txIndexer($this->txConfig())->run();

        $this->assertSame(TxSummary::COMPLETE, $summary->outcome, $summary->note);
        $this->assertSame(30, $this->count('transactions'));
        $this->assertSame(30, $this->mark());
        $this->assertSame([0, 30], [$summary->fromHeight, $summary->toHeight]);
        $this->assertSame(20, $summary->blocks, 'blocks with transactions; the ten others only moved the mark');
        $this->assertSame(30, $summary->transactions);
        $this->assertSame(21, $summary->calls, 'twenty get_block and one /get_transactions of 30 hashes');
        $this->assertSame(20, $server->served['get_block']);
        $this->assertSame([30], $server->batches);
        $this->assertSame(0, (int) $this->db->value('SELECT count(*) FROM transactions WHERE height % 3 = 0'));
        $this->assertSame([0, 1], array_map('intval', array_column($this->db->all('SELECT idx FROM transactions WHERE height = 5 ORDER BY idx'), 'idx')));
        $this->assertSame(TxServer::hashOf(5, 1), $this->db->value('SELECT hash FROM transactions WHERE height = 5 AND idx = 1'));

        // A second run is caught up: no call at all.
        $this->transport->calls = [];
        $again = $this->txIndexer($this->txConfig())->run();
        $this->assertSame(TxSummary::COMPLETE, $again->outcome);
        $this->assertSame([null, null, 0, 0, 0], [$again->fromHeight, $again->toHeight, $again->blocks, $again->transactions, $again->calls]);
        $this->assertCount(0, $this->transport->calls);
    }

    public function testBlocksWithoutTransactionsNeedNoCallAndOnlyMoveTheMark(): void
    {
        $chain = FakeChain::synthetic(0, 12);
        foreach (range(0, 12) as $h) {
            $header = $chain->at($h);
            $header['num_txes'] = 0;
            $chain->set($h, $header);
        }
        $server = $this->prepare($chain);
        $summary = $this->txIndexer($this->txConfig())->run();
        $this->assertSame(TxSummary::COMPLETE, $summary->outcome);
        $this->assertSame(12, $this->mark());
        $this->assertSame(0, $summary->calls);
        $this->assertSame([], $server->served);
        $this->assertCount(0, $this->transport->calls);
        $this->assertSame([], $this->clock->sleeps, 'no call, no pause');
        $this->assertSame(0, $this->count('transactions'));
    }

    public function testTheHashesOfSeveralBlocksShareACallUpToAHundred(): void
    {
        $chain = FakeChain::synthetic(0, 5);
        foreach (range(1, 5) as $h) {
            $header = $chain->at($h);
            $header['num_txes'] = 30;
            $chain->set($h, $header);
        }
        $server = $this->prepare($chain);
        $summary = $this->txIndexer($this->txConfig())->run();
        $this->assertSame(TxSummary::COMPLETE, $summary->outcome, $summary->note);
        $this->assertSame([90, 60], $server->batches, 'three blocks of 30 in the first call, two in the second');
        $this->assertSame(150, $this->count('transactions'));
        $this->assertSame(5, $this->mark());
        $this->assertSame(5, $server->served['get_block'] ?? 0);
        $this->assertSame(7, $summary->calls);
        foreach ($server->batches as $n) {
            $this->assertTrue($n <= 100);
        }
        // Blocks keep their own order: block 4 starts the second call.
        $this->assertSame(0, (int) $this->db->value('SELECT idx FROM transactions WHERE hash = ?', [TxServer::hashOf(4, 0)]));
    }

    public function testABlockOfMoreThanAHundredIsAskedInSeveralCallsAndStoredWhole(): void
    {
        $chain = FakeChain::synthetic(0, 2);
        $header = $chain->at(1);
        $header['num_txes'] = 150;
        $chain->set(1, $header);
        $server = $this->prepare($chain);
        $summary = $this->txIndexer($this->txConfig())->run();
        $this->assertSame(TxSummary::COMPLETE, $summary->outcome, $summary->note);
        $this->assertSame([100, 50, 2], $server->batches, 'block 1 in two calls, block 2 in the next group');
        $this->assertSame(152, $this->count('transactions'));
        $this->assertSame(2, $this->mark());
        $this->assertSame(150, (int) $this->db->value('SELECT count(*) FROM transactions WHERE height = 1'));
    }

    public function testAnErrorInTheSecondCallOfABlockStoresNothingOfIt(): void
    {
        $chain = FakeChain::synthetic(0, 2);
        $header = $chain->at(1);
        $header['num_txes'] = 150;
        $chain->set(1, $header);
        $server = $this->prepare($chain);
        $n = 0;
        $server->mutateTransactions = static function (array $asked, array $body) use (&$n): array {
            if (++$n === 2) {
                array_pop($body['txs']); // the second call comes back one short
            }
            return $body;
        };
        $summary = $this->txIndexer($this->txConfig())->run();
        $this->assertSame(TxSummary::ERROR, $summary->outcome);
        $this->assertStringContains('invalid-data', $summary->note);
        $this->assertStringContains('sent 49 transactions, not 50', $summary->note);
        $this->assertSame(0, $this->count('transactions'), 'not even the first hundred');
        $this->assertNull($this->mark());
        // The next run, with a correct node, stores the block whole.
        $server->mutateTransactions = null;
        $again = $this->txIndexer($this->txConfig())->run();
        $this->assertSame(TxSummary::COMPLETE, $again->outcome, $again->note);
        $this->assertSame(152, $this->count('transactions'));
    }

    public function testABlockHashThatDiffersStopsTheTransactionWorkWithANote(): void
    {
        $chain = FakeChain::synthetic(0, 10);
        $server = $this->prepare($chain);
        $server->mutateBlock = static function (int $height, array $result): array {
            if ($height === 7) {
                $result['block_header']['hash'] = str_repeat('0', 64);
            }
            return $result;
        };
        $summary = $this->txIndexer($this->txConfig())->run();
        $this->assertSame(TxSummary::STOPPED, $summary->outcome);
        $this->assertStringContains('height 7', $summary->note);
        $this->assertStringContains('header indexer', $summary->note);
        $this->assertSame(6, $this->mark(), 'what was complete before the block is kept');
        $this->assertSame(0, (int) $this->db->value('SELECT count(*) FROM transactions WHERE height >= 7'));
        $this->assertSame(6, $this->count('transactions'), 'blocks 1 to 6 hold 1+2+0+1+2+0');
        $this->assertSame(0, $this->count('reorgs'), 'the transaction indexer decides nothing about the chain');
        $this->assertSame(10, ($this->store->top() ?? ['height' => -1])['height'], 'the blocks are untouched');
    }

    public function testAHashOfTheFirstBlockThatDiffersStoresNothingAndKeepsNoMark(): void
    {
        $chain = FakeChain::synthetic(1, 10);
        $server = $this->prepare($chain);
        $server->mutateBlock = static function (int $height, array $result): array {
            $result['block_header']['hash'] = str_repeat('0', 64);
            return $result;
        };
        $summary = $this->txIndexer($this->txConfig())->run();
        $this->assertSame(TxSummary::STOPPED, $summary->outcome);
        $this->assertSame(0, $this->count('transactions'));
        $this->assertNull($this->mark());
        $this->assertSame(1, $summary->calls);
    }

    public function testAnAnswerForAnotherHeightOrWithoutAHeaderIsInvalid(): void
    {
        $chain = FakeChain::synthetic(1, 4);
        $server = $this->prepare($chain);
        $server->mutateBlock = static function (int $height, array $result): array {
            $result['block_header']['height'] = $height + 1;
            return $result;
        };
        $summary = $this->txIndexer($this->txConfig())->run();
        $this->assertSame(TxSummary::ERROR, $summary->outcome);
        $this->assertStringContains('another height', $summary->note);
        $server->mutateBlock = static function (int $height, array $result): array {
            unset($result['block_header']);
            return $result;
        };
        $this->assertSame(TxSummary::ERROR, $this->txIndexer($this->txConfig())->run()->outcome);
        $this->assertSame(0, $this->count('transactions'));
    }

    public function testTheListOfHashesOfABlockMustFitItsHeader(): void
    {
        $chain = FakeChain::synthetic(1, 4);
        $server = $this->prepare($chain);
        $cases = [
            'one too few' => static function (array $r): array { array_pop($r['tx_hashes']); return $r; },
            'one too many' => static function (array $r): array { $r['tx_hashes'][] = str_repeat('1', 64); return $r; },
            'an upper-case hash' => static function (array $r): array { $r['tx_hashes'][0] = strtoupper($r['tx_hashes'][0]); return $r; },
            'a short hash' => static function (array $r): array { $r['tx_hashes'][0] = substr($r['tx_hashes'][0], 2); return $r; },
            'a number' => static function (array $r): array { $r['tx_hashes'][0] = 5; return $r; },
            'not a list' => static function (array $r): array { $r['tx_hashes'] = ['a' => $r['tx_hashes'][0]]; return $r; },
            'missing' => static function (array $r): array { unset($r['tx_hashes']); return $r; },
        ];
        foreach ($cases as $what => $change) {
            $server->mutateBlock = static fn (int $height, array $result): array => $height === 1 ? $change($result) : $result;
            $summary = $this->txIndexer($this->txConfig())->run();
            $this->assertSame(TxSummary::ERROR, $summary->outcome, $what);
            $this->assertStringContains('invalid-data', $summary->note, $what);
            $this->assertSame(0, $this->count('transactions'), $what);
            $this->assertNull($this->mark(), $what);
        }
    }

    public function testADuplicateHashInTheGroupIsInvalid(): void
    {
        $chain = FakeChain::synthetic(1, 2);
        $server = $this->prepare($chain);
        $server->mutateBlock = static function (int $height, array $result): array {
            if ($height === 2) {
                $result['tx_hashes'] = [$result['tx_hashes'][0], $result['tx_hashes'][0]];
            }
            return $result;
        };
        $summary = $this->txIndexer($this->txConfig())->run();
        $this->assertSame(TxSummary::ERROR, $summary->outcome);
        $this->assertStringContains('listed twice', $summary->note);
        $this->assertSame(0, $this->count('transactions'));
    }

    /** @return array<string,\Closure(list<string>, array<string,mixed>): array<string,mixed>> */
    private function badAnswers(): array
    {
        return [
            'a missed transaction (one entry short)' => static function (array $asked, array $body): array {
                array_pop($body['txs']);
                return $body;
            },
            'an extra transaction' => static function (array $asked, array $body): array {
                $body['txs'][] = $body['txs'][0];
                return $body;
            },
            'a transaction of another hash' => static function (array $asked, array $body): array {
                $body['txs'][0]['tx_hash'] = str_repeat('9', 64);
                return $body;
            },
            'the same transaction twice, the other missing' => static function (array $asked, array $body): array {
                $body['txs'][1] = $body['txs'][0];
                return $body;
            },
            'the entries in another order' => static function (array $asked, array $body): array {
                $body['txs'] = array_reverse($body['txs']);
                return $body;
            },
            'no txs' => static function (array $asked, array $body): array {
                unset($body['txs']);
                return $body;
            },
            'in_pool missing' => static function (array $asked, array $body): array {
                unset($body['txs'][0]['in_pool']);
                return $body;
            },
            'block_height missing' => static function (array $asked, array $body): array {
                unset($body['txs'][0]['block_height']);
                return $body;
            },
            'block_height as a string' => static function (array $asked, array $body): array {
                $body['txs'][0]['block_height'] = '18446744073709551615';
                return $body;
            },
            'an entry that is not an object' => static function (array $asked, array $body): array {
                $body['txs'][0] = 'x';
                return $body;
            },
            'an as_json that does not parse' => static function (array $asked, array $body): array {
                $body['txs'][0]['as_json'] = '{';
                return $body;
            },
        ];
    }

    public function testAMissedExtraOrWrongTransactionStopsTheRunAndStoresNothing(): void
    {
        $chain = FakeChain::synthetic(1, 8);
        $server = $this->prepare($chain);
        foreach ($this->badAnswers() as $what => $change) {
            $server->mutateTransactions = $change;
            $summary = $this->txIndexer($this->txConfig())->run();
            $this->assertSame(TxSummary::ERROR, $summary->outcome, $what);
            $this->assertStringContains('invalid-data', $summary->note, $what);
            $this->assertSame(0, $this->count('transactions'), $what);
            $this->assertNull($this->mark(), $what);
        }
    }

    public function testATransactionInThePoolOrInAnotherBlockMeansTheChainMoved(): void
    {
        $chain = FakeChain::synthetic(1, 8);
        $server = $this->prepare($chain);
        $server->mutateTransactions = static function (array $asked, array $body): array {
            $body['txs'][1]['in_pool'] = true;
            return $body;
        };
        $summary = $this->txIndexer($this->txConfig())->run();
        $this->assertSame(TxSummary::STOPPED, $summary->outcome);
        $this->assertStringContains('pool', $summary->note);
        $this->assertSame(0, $this->count('transactions'));
        $this->assertNull($this->mark());

        $server->mutateTransactions = static function (array $asked, array $body): array {
            $body['txs'][1]['block_height'] = 99;
            return $body;
        };
        $summary = $this->txIndexer($this->txConfig())->run();
        $this->assertSame(TxSummary::STOPPED, $summary->outcome);
        $this->assertStringContains('another block', $summary->note);
        $this->assertSame(0, $this->count('transactions'));
    }

    public function testTheNodeNamingMissedTransactionsIsAnErrorOfTheNodeAndNeverTheFallback(): void
    {
        $chain = FakeChain::synthetic(1, 8);
        $server = $this->prepare($chain);
        $server->mutateTransactions = static function (array $asked, array $body): array {
            $body['missed_tx'] = [$asked[0]];
            array_shift($body['txs']);
            return $body;
        };
        $summary = $this->txIndexer($this->txConfig())->run();
        $this->assertSame(TxSummary::ERROR, $summary->outcome);
        $this->assertStringContains('node:', $summary->note);
        $this->assertSame(0, $this->count('transactions'));
        $this->assertNull($this->mark());
        $this->assertFalse(in_array(self::F, $this->transport->hosts(), true), 'the fallback was not asked');
    }

    // --- the amounts ---------------------------------------------------------------------------

    public function testAnAmountThatDoesNotFitStopsTheRunWithInvalidData(): void
    {
        $chain = FakeChain::synthetic(1, 4);
        $server = $this->prepare($chain);
        $server->entryFor = static fn (string $hash, int $height, int $idx): array => TxServer::entry(
            $hash,
            $height,
            TxServer::asJson(['amount_minted' => '#9223372036854775808']),
        );
        $summary = $this->txIndexer($this->txConfig())->run();
        $this->assertSame(TxSummary::ERROR, $summary->outcome);
        $this->assertStringContains('invalid-data: amount_minted does not fit', $summary->note);
        $this->assertStringNotContains('9223372036854775808', $summary->note);
        $this->assertSame(0, $this->count('transactions'));
    }

    public function testExactAmountsAbove2To53AreStored(): void
    {
        $chain = FakeChain::synthetic(1, 1);
        $server = $this->prepare($chain);
        $server->entryFor = static fn (string $hash, int $height, int $idx): array => TxServer::entry(
            $hash,
            $height,
            TxServer::asJson(['amount_burnt' => 9007199254740993, 'amount_minted' => 9223372036854775807]),
        );
        $this->txIndexer($this->txConfig())->run();
        $row = $this->db->one('SELECT amount_burnt, amount_minted FROM transactions');
        $this->assertSame([9007199254740993, 9223372036854775807], [$row['amount_burnt'], $row['amount_minted']]);
    }

    // --- the pause and the time ---------------------------------------------------------------

    public function testTheIndexerPausesBetweenCallsButNotBeforeTheFirst(): void
    {
        $chain = FakeChain::synthetic(1, 4); // blocks 1 (1 tx), 2 (2), 3 (0), 4 (1)
        $this->prepare($chain);
        $this->clock->sleeps = [];
        $summary = $this->txIndexer($this->txConfig(['tx_backfill_pause_seconds' => 0.5]))->run();
        $this->assertSame(4, $summary->calls, 'three get_block and one /get_transactions');
        $this->assertSame([0.5, 0.5, 0.5], $this->clock->sleeps);
    }

    public function testTheRunStopsAfterItsTimeAndTheNextRunGoesOn(): void
    {
        $chain = FakeChain::synthetic(0, 6);
        foreach (range(1, 6) as $h) {
            $header = $chain->at($h);
            $header['num_txes'] = 60; // every block is a group of its own
            $chain->set($h, $header);
        }
        $this->prepare($chain);
        $this->clock->sleeps = [];
        $config = $this->txConfig(['run_seconds' => 3, 'tx_backfill_pause_seconds' => 2.0]);
        $summary = $this->txIndexer($config)->run();
        $this->assertSame(TxSummary::TIME, $summary->outcome);
        $this->assertSame(1, $summary->blocks, 'block 1 from 0 to 2 seconds; the pause before block 2 ends at 4, past the 3 seconds');
        $this->assertSame(1, $this->mark());
        $this->assertSame(2, $summary->calls);
        $this->assertSame(60, $this->count('transactions'));

        $next = $this->txIndexer($this->txConfig(['run_seconds' => 3600]))->run();
        $this->assertSame(TxSummary::COMPLETE, $next->outcome, $next->note);
        $this->assertSame(6, $this->mark());
        $this->assertSame(360, $this->count('transactions'));
    }

    public function testTheTimeCountsFromTheStartOfTheWholeRun(): void
    {
        $chain = FakeChain::synthetic(0, 4);
        foreach (range(1, 4) as $h) {
            $header = $chain->at($h);
            $header['num_txes'] = 60;
            $chain->set($h, $header);
        }
        $this->prepare($chain);
        $config = $this->txConfig(['run_seconds' => 45]);

        // The header run used 46 seconds of the 45 before: nothing is started.
        $summary = $this->txIndexer($config, $this->clock->now() - 46.0)->run();
        $this->assertSame(TxSummary::TIME, $summary->outcome);
        $this->assertSame(0, $summary->calls);
        $this->assertSame(0, $this->mark(), 'block 0 has no transactions and needs no call, so the mark moved over it');
        $this->assertSame(0, $this->count('transactions'));

        // It used 44.5 seconds: one group is started and finished (its second call pauses 2
        // seconds), and then the time is over.
        $summary = $this->txIndexer($config, $this->clock->now() - 44.5)->run();
        $this->assertSame(TxSummary::TIME, $summary->outcome);
        $this->assertSame(2, $summary->calls);
        $this->assertSame(1, $this->mark());
        $this->assertSame(60, $this->count('transactions'));
    }

    public function testNoNewBlockIsFetchedWhenTheTimeRunsOutInsideAGroup(): void
    {
        $chain = FakeChain::synthetic(1, 6);
        foreach (range(1, 6) as $h) {
            $header = $chain->at($h);
            $header['num_txes'] = 10; // one group of six blocks
            $chain->set($h, $header);
        }
        $server = $this->prepare($chain);
        $config = $this->txConfig(['run_seconds' => 5, 'tx_backfill_pause_seconds' => 2.0]);
        // get_block of block 1 at 0, of block 2 at 2, of block 3 at 4, then 6 seconds are over.
        $summary = $this->txIndexer($config)->run();
        $this->assertSame(TxSummary::TIME, $summary->outcome);
        $this->assertSame(3, $server->served['get_block']);
        $this->assertSame([30], $server->batches, 'what was fetched is asked for and stored');
        $this->assertSame(30, $this->count('transactions'));
        $this->assertSame(3, $this->mark(), 'the mark covers the three whole blocks only');
        $this->assertSame(4, $summary->calls);
    }

    // --- nodes --------------------------------------------------------------------------------

    public function testOnlyOwnNodesAreAskedAndTheFallbackNever(): void
    {
        $chain = FakeChain::synthetic(1, 8);
        $this->prepare($chain);
        $this->txIndexer($this->txConfig())->run();
        $hosts = array_unique($this->transport->hosts());
        $this->assertSame([self::A], array_values($hosts), 'one node for the whole group');
        $this->assertFalse(in_array(self::F, $this->transport->hosts(), true), 'the fallback was not asked');
    }

    public function testWhenTheFirstOwnNodeFailsTheGroupIsAskedAgainOnTheOtherOne(): void
    {
        $chain = FakeChain::synthetic(1, 8);
        $server = $this->prepare($chain);
        $this->transport->serve(self::A, static function (RecordedCall $c): TransportResponse {
            throw new TransportFailure(ErrorCategory::Connect);
        });
        $summary = $this->txIndexer($this->txConfig())->run();
        $this->assertSame(TxSummary::COMPLETE, $summary->outcome, $summary->note);
        $this->assertSame(9, $this->count('transactions'));
        $this->assertFalse(in_array(self::F, $this->transport->hosts(), true), 'the fallback was not asked');
        // The calls of one group are all to one node.
        $b = array_values(array_filter($this->transport->calls, static fn (RecordedCall $c): bool => $c->host === self::B));
        $this->assertTrue(count($b) >= 7);
        $this->assertSame(1, $server->batches === [] ? 0 : count($server->batches));
    }

    public function testWhenBothOwnNodesFailTheFallbackIsNotAskedAndTheRunEndsWithAnError(): void
    {
        $chain = FakeChain::synthetic(1, 8);
        $this->prepare($chain);
        $this->down(self::A);
        $this->down(self::B);
        $summary = $this->txIndexer($this->txConfig())->run();
        $this->assertSame(TxSummary::ERROR, $summary->outcome);
        $this->assertStringContains('node:', $summary->note);
        $this->assertFalse(in_array(self::F, $this->transport->hosts(), true), 'the fallback was not asked');
        foreach (['example.invalid', 'lateo.dev', 'http', ':17767'] as $forbidden) {
            $this->assertStringNotContains($forbidden, $summary->note);
        }
        $this->assertSame(0, $this->count('transactions'));
    }

    public function testAFallbackAsPreferredNodeIsNotAsked(): void
    {
        $chain = FakeChain::synthetic(1, 4);
        $this->prepare($chain);
        $summary = $this->txIndexer($this->txConfig(), null, 'fallback')->run();
        $this->assertSame(TxSummary::COMPLETE, $summary->outcome, $summary->note);
        $this->assertFalse(in_array(self::F, $this->transport->hosts(), true), 'the fallback was not asked');
        $this->assertSame([self::A], array_values(array_unique($this->transport->hosts())));
    }

    public function testNothingIsAskedOfAnEmptyIndex(): void
    {
        $summary = $this->txIndexer($this->txConfig())->run();
        $this->assertSame(TxSummary::COMPLETE, $summary->outcome);
        $this->assertCount(0, $this->transport->calls);
        $this->assertNull($this->mark());
    }

    public function testOnlyReadCallsAreMade(): void
    {
        $chain = FakeChain::synthetic(1, 8);
        $this->prepare($chain);
        $this->txIndexer($this->txConfig())->run();
        $methods = array_unique(array_map(static fn (RecordedCall $c): string => $c->method, $this->transport->calls));
        sort($methods);
        $this->assertSame(['/get_transactions', 'get_block'], $methods);
    }

    public function testTheFixtureNameOfALongListOfHashesIsShort(): void
    {
        $hashes = [];
        for ($i = 0; $i < 100; $i++) {
            $hashes[] = TxServer::hashOf(1, $i);
        }
        $name = FixtureTransport::fixtureName('/get_transactions', ['txs_hashes' => $hashes, 'decode_as_json' => true, 'prune' => true]);
        $this->assertTrue(strlen($name) < 120, $name);
        $this->assertStringContains('+99more-', $name);
        $this->assertSame(
            'get_transactions__decode_as_json-true__prune-true__txs_hashes-' . substr($hashes[0], 0, 12) . '+' . substr($hashes[1], 0, 12),
            FixtureTransport::fixtureName('/get_transactions', ['txs_hashes' => array_slice($hashes, 0, 2), 'decode_as_json' => true, 'prune' => true]),
            'a short list keeps every hash in the name, as before',
        );
    }
}
