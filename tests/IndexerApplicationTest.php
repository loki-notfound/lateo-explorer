<?php

declare(strict_types=1);

namespace Lateo\Explorer\Tests;

use Lateo\Explorer\Indexer\IndexerApplication;
use Lateo\Explorer\Tests\Support\FakeChain;
use Lateo\Explorer\Tests\Support\IndexerTestBase;
use Lateo\Explorer\Tests\Support\RecordedCall;
use Lateo\Explorer\Tests\Support\TxServer;
use Lateo\Explorer\Db\TransactionStore;
use Lateo\Explorer\Rpc\TransportResponse;

final class IndexerApplicationTest extends IndexerTestBase
{
    /** @var resource */
    private $out;
    /** @var resource */
    private $err;
    private string $lock = '';
    private string $cache = '';

    public function setUp(): void
    {
        parent::setUp();
        $this->out = fopen('php://memory', 'w+');
        $this->err = fopen('php://memory', 'w+');
        $dir = $this->scratch();
        $this->lock = $dir . '/indexer.lock';
        $this->cache = $dir . '/cache';
    }

    /** @var array<string,mixed> */
    private array $limits = [];

    private function app(int $start = 0, bool $snapshotFallback = false): IndexerApplication
    {
        return new IndexerApplication(
            $this->config($this->limits),
            $this->transport,
            $this->clock,
            fn () => $this->db,
            $this->out,
            $this->err,
            $this->lock,
            $this->cache,
            $start,
            $snapshotFallback,
        );
    }

    private function read($stream): string
    {
        rewind($stream);
        return (string) stream_get_contents($stream);
    }

    public function testOneRunWritesOneLogLineAndTheRunsRow(): void
    {
        $this->serveWithSupplyOnOwn(FakeChain::synthetic(0, 30));
        $code = $this->app()->run();
        $this->assertSame(0, $code, $this->read($this->err));
        $this->assertSame('', $this->read($this->err));
        $out = $this->read($this->out);
        $this->assertSame(1, preg_match(
            '/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ mode=tip from=0 to=30 headers=31 node=own-a outcome=complete reorgs=0 requests=3 snapshot=stored tx=off\n\z/',
            $out,
        ), 'log line: ' . $out);
        $this->assertSame(31, $this->count('blocks'));
        $this->assertSame(3, $this->count('supply_snapshots'));
        $run = $this->db->one('SELECT * FROM runs');
        $this->assertSame('tip', $run['mode']);
        $this->assertSame(0, (int) $run['from_height']);
        $this->assertSame(30, (int) $run['to_height']);
        $this->assertSame(31, (int) $run['headers']);
        $this->assertSame('own-a', $run['node']);
        $this->assertSame('complete', $run['outcome']);
        $this->assertSame('', $run['note']);
        $this->assertSame(3, (int) $this->db->value('SELECT value FROM meta WHERE key = \'schema_version\''));
    }

    public function testTheLogLineAndErrorsNeverContainAnAddress(): void
    {
        $this->down(self::A);
        $this->down(self::B);
        $this->down(self::F);
        $code = $this->app()->run();
        $this->assertSame(1, $code);
        $all = $this->read($this->out) . $this->read($this->err);
        $this->assertStringContains('outcome=error', $all);
        $this->assertStringContains('snapshot=skipped', $all);
        foreach (['example.invalid', 'lateo.dev', 'http', ':17767'] as $forbidden) {
            $this->assertStringNotContains($forbidden, $all);
        }
        $this->assertSame('error', $this->db->value('SELECT outcome FROM runs'));
    }

    public function testASecondRunAtTheSameTimeExitsZeroWithoutOutput(): void
    {
        $this->serveWithSupplyOnOwn(FakeChain::synthetic(0, 5));
        $other = fopen($this->lock, 'c');
        $this->assertTrue(flock($other, LOCK_EX | LOCK_NB), 'the test holds the lock');

        $this->assertSame(0, $this->app()->run());
        $this->assertSame('', $this->read($this->out));
        $this->assertSame('', $this->read($this->err));
        $this->assertCount(0, $this->transport->calls, 'no node was contacted');
        $this->assertSame(0, $this->count('runs'));

        flock($other, LOCK_UN);
        fclose($other);
        $this->assertSame(0, $this->app()->run());
        $this->assertSame(1, $this->count('runs'), 'with the lock free the run goes ahead');
    }

    public function testTheLockIsReleasedAfterARun(): void
    {
        $this->serveWithSupplyOnOwn(FakeChain::synthetic(0, 5));
        $this->assertSame(0, $this->app()->run());
        $h = fopen($this->lock, 'c');
        $this->assertTrue(flock($h, LOCK_EX | LOCK_NB));
        fclose($h);
    }

    public function testOldRunsArePrunedAndTheCacheIsSwept(): void
    {
        $this->serveWithSupplyOnOwn(FakeChain::synthetic(0, 5));
        $this->app()->run();
        $now = (int) $this->clock->now();
        $this->db->exec('INSERT INTO runs (started_at, finished_at, mode, headers, outcome, note) VALUES (?, ?, \'tip\', 0, \'complete\', \'\')', [$now - 40 * 86400, $now - 40 * 86400]);
        $this->assertSame(2, $this->count('runs'));
        $this->app()->run();
        $this->assertSame(2, $this->count('runs'), 'the old row went, the new run was added');
        $this->assertSame(0, (int) $this->db->value('SELECT count(*) FROM runs WHERE started_at < ?', [$now - 30 * 86400]));
    }

    public function testAnErrorOfTheDatabaseIsReportedOnStderrWithExitOne(): void
    {
        $app = new IndexerApplication(
            $this->config(),
            $this->transport,
            $this->clock,
            static function (): never {
                throw new \RuntimeException('database cannot be opened');
            },
            $this->out,
            $this->err,
            $this->lock,
            $this->cache,
        );
        $this->assertSame(1, $app->run());
        $this->assertSame('', $this->read($this->out));
        $this->assertSame("indexer: stopped: database cannot be opened\n", $this->read($this->err));
    }

    public function testNoDataBelowTheStartHeightIsIndexed(): void
    {
        $this->serveWithSupplyOnOwn(FakeChain::synthetic(0, 30));
        $this->app(25)->run();
        $this->assertSame(6, $this->count('blocks'));
        $this->assertSame(25, $this->store->lowestHeight());
    }

    public function testTwoBlocksOfEqualWorkEndWaitingWithExitZeroAndNoErrorOutput(): void
    {
        $a = FakeChain::synthetic(0, 20, 'a');
        $this->serveWithSupplyOnOwn($a);
        $this->assertSame(0, $this->app()->run());
        $out = $this->read($this->out);
        $before = $this->chainTables();
        $runsBefore = $this->count('runs');

        // Both own nodes are at the same height on another block of the same work.
        $this->serveWithSupplyOnOwn(FakeChain::forked($a, 19, 'b', 20, 0));
        $this->assertSame(0, $this->app()->run());
        $this->assertSame('', $this->read($this->err), 'nothing goes to the error stream');
        $all = $this->read($this->out);
        $line = substr($all, strlen($out));
        $this->assertStringContains('outcome=waiting', $line);
        $this->assertStringContains('snapshot=stored', $line, 'the snapshot is taken as after a complete run');
        $this->assertSame($before, $this->chainTables(), 'the chain tables are unchanged');
        $this->assertSame($runsBefore + 1, $this->count('runs'));
        $run = $this->db->one('SELECT * FROM runs ORDER BY id DESC LIMIT 1');
        $this->assertSame('waiting', $run['outcome']);
        $this->assertSame('node own-a: two blocks of equal work at height 20; waiting for the next block', $run['note']);
    }

    // --- the transaction indexer ---------------------------------------------------------------

    /** Serve the chain, its transactions and the supply answers on the given hosts (default: both own nodes). */
    private function serveTx(FakeChain $chain, ?array $hosts = null): TxServer
    {
        $server = new TxServer($chain);
        foreach ($hosts ?? [self::A, self::B] as $host) {
            $this->transport->serve($host, static function (RecordedCall $c) use ($server): TransportResponse {
                if (in_array($c->method, ['get_reserve_info', 'get_circulating_supply', 'get_audited_supply'], true)) {
                    return new TransportResponse(200, (string) file_get_contents(__DIR__ . '/fixtures/rpc/' . $c->method . '.json'));
                }
                return $server->respond($c);
            });
        }
        return $server;
    }

    public function testTheLogLineCountsTheChecksOfTheRun(): void
    {
        $this->limits = ['tx_backfill_enabled' => true];
        $server = $this->serveTx(FakeChain::synthetic(0, 30));
        // The first transaction of every block carries a burnt amount: a transfer with conversion fields.
        $server->entryFor = static fn (string $hash, int $height, int $idx): array => TxServer::entry(
            $hash,
            $height,
            $idx === 0 ? TxServer::asJson(['amount_burnt' => 5]) : TxServer::asJson(),
        );
        $this->assertSame(0, $this->app()->run(), $this->read($this->err));
        $out = $this->read($this->out);
        $this->assertStringContains(' txs=30 txcalls=21 checks=20 match=0 mismatch=0 notrecomputed=20' . "\n", $out);
        $this->assertSame(20, $this->count('conversion_checks'));
        $this->assertSame(20, (int) $this->db->value("SELECT count(*) FROM conversion_checks WHERE reason = 'transfer_with_conversion_fields'"));
        // The next run has nothing new to check.
        $this->app()->run();
        $lines = explode("\n", trim($this->read($this->out)));
        $this->assertStringContains(' checks=0 match=0 mismatch=0 notrecomputed=0', $lines[1]);
    }

    public function testTheTransactionIndexerIsOffByDefaultAndAsksNothing(): void
    {
        $server = $this->serveTx(FakeChain::synthetic(0, 30));
        $this->assertSame(0, $this->app()->run(), $this->read($this->err));
        $this->assertStringContains(' tx=off' . "\n", $this->read($this->out));
        $this->assertSame([], $server->served, 'neither get_block nor /get_transactions was called');
        $this->assertSame(0, $this->count('transactions'));
        $this->assertSame(0, (int) $this->db->value("SELECT count(*) FROM meta WHERE key = 'tx_indexed_height'"));
        $this->assertSame(31, $this->count('blocks'), 'the headers were indexed as before');
        foreach ($this->transport->calls as $call) {
            $this->assertFalse(in_array($call->method, ['get_block', '/get_transactions'], true));
        }
    }

    public function testSwitchedOnTheRunStoresTheTransactionsAndLogsThem(): void
    {
        $this->limits = ['tx_backfill_enabled' => true];
        $server = $this->serveTx(FakeChain::synthetic(0, 30));
        $this->assertSame(0, $this->app()->run(), $this->read($this->err));
        $out = $this->read($this->out);
        $this->assertSame(1, preg_match(
            '/\A\S+ mode=tip from=0 to=30 headers=31 node=own-a outcome=complete reorgs=0 requests=3 snapshot=stored tx=complete txfrom=0 txto=30 txblocks=20 txs=30 txcalls=21 checks=0 match=0 mismatch=0 notrecomputed=0\n\z/',
            $out,
        ), 'log line: ' . $out);
        $this->assertSame(30, $this->count('transactions'));
        $this->assertSame('30', $this->db->value("SELECT value FROM meta WHERE key = 'tx_indexed_height'"));
        $this->assertSame(20, $server->served['get_block']);
        $this->assertSame('', $this->read($this->err));
        $this->assertFalse(in_array(self::F, $this->transport->hosts(), true), 'the fallback was not asked');

        // The next run has nothing to do and says so.
        $this->app()->run();
        $lines = explode("\n", trim($this->read($this->out)));
        $this->assertStringContains(' tx=complete txfrom=- txto=- txblocks=0 txs=0 txcalls=0 checks=0 match=0 mismatch=0 notrecomputed=0', $lines[1]);
    }

    public function testABackfillRunSkipsTheTransactionWork(): void
    {
        $this->limits = ['tx_backfill_enabled' => true, 'run_seconds' => 3600];
        $server = $this->serveTx(FakeChain::synthetic(0, 300));
        $this->assertSame(0, $this->app()->run(), $this->read($this->err));
        $out = $this->read($this->out);
        $this->assertStringContains('mode=backfill', $out);
        $this->assertStringContains(' tx=skipped' . "\n", $out);
        $this->assertSame([], $server->served, 'no get_block, no /get_transactions');
        $this->assertSame(0, $this->count('transactions'));
        $this->assertNull((new TransactionStore($this->db))->indexedHeight());

        // The same database, now close to the tip: the transaction work starts.
        $this->limits = ['tx_backfill_enabled' => true];
        $server = $this->serveTx(FakeChain::synthetic(0, 305));
        $this->assertSame(0, $this->app()->run(), $this->read($this->err));
        $lines = explode("\n", trim($this->read($this->out)));
        $this->assertStringContains('mode=tip', $lines[1]);
        $this->assertStringContains(' tx=time txfrom=0 ', $lines[1], 'about 200 blocks with transactions, two seconds each: the 45 seconds of the run are over first');
        $this->assertTrue(($server->served['get_block'] ?? 0) > 0);
    }

    public function testAHeaderRunThatEndsInAnErrorSkipsTheTransactionWork(): void
    {
        $this->limits = ['tx_backfill_enabled' => true];
        $chain = FakeChain::synthetic(0, 30);
        $chain->mutateBody(static fn (string $body): string => str_replace('"height":5,', '"height":6,', $body));
        $server = $this->serveTx($chain);
        $this->assertSame(1, $this->app()->run());
        $this->assertStringContains(' tx=skipped' . "\n", $this->read($this->out));
        $this->assertSame([], $server->served);
        $this->assertSame(0, $this->count('transactions'));
    }

    public function testAnErrorOfTheTransactionIndexerIsReportedWithExitOneAndNoAddress(): void
    {
        $this->limits = ['tx_backfill_enabled' => true];
        $chain = FakeChain::synthetic(0, 12);
        $server = $this->serveTx($chain);
        $server->mutateTransactions = static function (array $asked, array $body): array {
            array_pop($body['txs']);
            return $body;
        };
        // The fallback would answer everything correctly, if it were asked.
        $good = new TxServer($chain);
        $this->transport->serve(self::F, static fn (RecordedCall $c): TransportResponse => $good->respond($c));
        $this->assertSame(1, $this->app()->run());
        $this->assertStringContains(' tx=error ', $this->read($this->out));
        $err = $this->read($this->err);
        $this->assertStringContains('indexer: transactions: invalid-data: a node sent', $err);
        foreach (['example.invalid', 'lateo.dev', 'http', ':17767'] as $forbidden) {
            $this->assertStringNotContains($forbidden, $err);
            $this->assertStringNotContains($forbidden, $this->read($this->out));
        }
        $this->assertSame(0, $this->count('transactions'));
        $this->assertSame([], $good->served, 'the fallback was never asked for a block or a transaction');
    }

    public function testTheFallbackAnswersHeadersOnlyAndIsNeverAskedForTransactions(): void
    {
        $this->limits = ['tx_backfill_enabled' => true];
        $chain = FakeChain::synthetic(0, 12);
        $this->down(self::A);
        $this->down(self::B);
        $fallback = new TxServer($chain);
        $this->transport->serve(self::F, static fn (RecordedCall $c): TransportResponse => $fallback->respond($c));
        $code = $this->app()->run();
        $this->assertSame(1, $code, 'the headers came from the fallback, the transactions could not come from anywhere');
        $this->assertSame(13, $this->count('blocks'));
        $this->assertSame([], $fallback->served);
        $this->assertSame(0, $this->count('transactions'));
        $this->assertStringContains(' tx=error ', $this->read($this->out));
    }

    /** @return list<list<array<string,mixed>>> */
    private function chainTables(): array
    {
        return [
            $this->db->all('SELECT * FROM blocks ORDER BY height'),
            $this->db->all('SELECT * FROM pricing_records ORDER BY height'),
            $this->db->all('SELECT * FROM orphaned_blocks ORDER BY hash'),
            $this->db->all('SELECT * FROM reorgs ORDER BY id'),
        ];
    }
}
