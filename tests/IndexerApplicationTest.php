<?php

declare(strict_types=1);

namespace Lateo\Explorer\Tests;

use Lateo\Explorer\Indexer\IndexerApplication;
use Lateo\Explorer\Tests\Support\FakeChain;
use Lateo\Explorer\Tests\Support\IndexerTestBase;

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

    private function app(int $start = 0, bool $snapshotFallback = false): IndexerApplication
    {
        return new IndexerApplication(
            $this->config(),
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
            '/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ mode=tip from=0 to=30 headers=31 node=own-a outcome=complete reorgs=0 requests=3 snapshot=stored\n\z/',
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
        $this->assertSame(1, (int) $this->db->value('SELECT value FROM meta WHERE key = \'schema_version\''));
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
