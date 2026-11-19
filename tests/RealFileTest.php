<?php

declare(strict_types=1);

namespace Lateo\Explorer\Tests;

use Lateo\Explorer\Cache\CacheStatus;
use Lateo\Explorer\Cache\FileCache;
use Lateo\Explorer\Db\Database;
use Lateo\Explorer\Db\Schema;
use Lateo\Explorer\Indexer\IndexerApplication;
use Lateo\Explorer\Rpc\CallLimiter;
use Lateo\Explorer\Rpc\CurlTransport;
use Lateo\Explorer\Rpc\ErrorCategory;
use Lateo\Explorer\Rpc\RequestLimits;
use Lateo\Explorer\Rpc\RpcException;
use Lateo\Explorer\Rpc\TransportFailure;
use Lateo\Explorer\Tests\Support\ChildProcess;
use Lateo\Explorer\Tests\Support\FakeClock;
use Lateo\Explorer\Tests\Support\IndexerTestBase;
use Lateo\Explorer\Tests\Support\SkipTest;

/**
 * The parts that only a real file system and real processes can show: WAL on a file, the
 * read-only open next to a writer, flock between processes (cache keys, the indexer's lock
 * file, the limiter's count) and the curl transport against a local listener.
 *
 * These tests skip, with a reason, when the scratch base is a stream wrapper (as under the
 * private host runner) or proc_open is not available. Every file, child process and server
 * they start is removed or stopped afterwards, also when a test fails.
 */
final class RealFileTest extends IndexerTestBase
{
    /** @var list<ChildProcess> */
    private array $children = [];

    public function setUp(): void
    {
        parent::setUp();
        if (str_contains(self::$scratchBase, '://')) {
            throw new SkipTest('the scratch base is a stream wrapper, not a real directory');
        }
        if (!function_exists('proc_open') || !function_exists('proc_terminate')) {
            throw new SkipTest('proc_open is not available');
        }
    }

    public function tearDown(): void
    {
        foreach ($this->children as $child) {
            $child->stop();
        }
        $this->children = [];
        parent::tearDown();
    }

    private function child(string $program): ChildProcess
    {
        $child = ChildProcess::program($program);
        $this->children[] = $child;
        return $child;
    }

    /**
     * A child that flocks every file in $files exclusively and says READY; it lets go and
     * exits when the file $release appears (or after 20 s, so that it can never linger).
     *
     * @param list<string> $files
     */
    private function lockHolder(array $files, string $release): ChildProcess
    {
        $program = '<?php
$handles = [];
foreach (' . var_export($files, true) . ' as $file) {
    $h = fopen($file, "c");
    if ($h === false || !flock($h, LOCK_EX | LOCK_NB)) {
        echo "FAILED\n";
        exit(1);
    }
    $handles[] = $h;
}
echo "READY\n";
$deadline = microtime(true) + 20;
while (!file_exists(' . var_export($release, true) . ') && microtime(true) < $deadline) {
    usleep(20000);
}
';
        $child = $this->child($program);
        $this->assertSame('READY', $child->readLine(), 'the child holds the lock: ' . $child->errors());
        return $child;
    }

    /** Let the lock holder go and wait until it has exited (and so released its locks). */
    private function release(ChildProcess $child, string $release): void
    {
        touch($release);
        $this->assertTrue($child->waitForExit(), 'the child ended');
    }

    // --- the database file --------------------------------------------------------------------

    public function testAFileDatabaseRunsInWalModeAndMigrates(): void
    {
        $file = $this->scratch() . '/explorer.sqlite';
        $db = Database::openWritable($file);
        $this->assertSame('wal', strtolower((string) $db->value('PRAGMA journal_mode')));
        Schema::migrate($db);
        $this->assertSame('1', (string) $db->value("SELECT value FROM meta WHERE key = 'schema_version'"));
        $this->assertTrue(file_exists($file));
        Schema::migrate($db); // a second migration finds nothing to do
        $this->assertSame(1, (int) $db->value("SELECT count(*) FROM meta WHERE key = 'schema_version'"));
    }

    public function testTheReadOnlyOpenSeesCommittedRowsOnlyAndStillWorksAfterTheWriterIsGone(): void
    {
        $file = $this->scratch() . '/explorer.sqlite';
        $writer = Database::openWritable($file);
        Schema::migrate($writer);
        $writer->exec("INSERT INTO meta (key, value) VALUES ('probe', 'committed')");

        $writer->pdo()->exec('BEGIN IMMEDIATE');
        $writer->exec("INSERT INTO meta (key, value) VALUES ('probe-open', 'not committed')");

        $reader = Database::openReadOnly($file);
        $this->assertTrue($reader->readOnly);
        $this->assertSame('committed', $reader->value("SELECT value FROM meta WHERE key = 'probe'"));
        $this->assertNull($reader->value("SELECT value FROM meta WHERE key = 'probe-open'"), 'an open transaction is not visible');
        $this->assertThrows(\PDOException::class, static fn () => $reader->exec("INSERT INTO meta (key, value) VALUES ('x', 'y')"));
        $this->assertThrows(\LogicException::class, static fn () => $reader->transaction(static fn () => 1));
        $this->assertNull($reader->value("SELECT value FROM meta WHERE key = 'x'"));

        $writer->pdo()->exec('COMMIT');
        $this->assertSame('not committed', $reader->value("SELECT value FROM meta WHERE key = 'probe-open'"), 'visible after the commit');

        // Close both connections: with the last one the -wal and -shm files go away.
        unset($reader, $writer);
        gc_collect_cycles();
        $this->assertFalse(file_exists($file . '-wal'), 'no -wal file left');
        $this->assertFalse(file_exists($file . '-shm'), 'no -shm file left');

        $again = Database::openReadOnly($file);
        $this->assertSame('committed', $again->value("SELECT value FROM meta WHERE key = 'probe'"));
        $this->assertSame('not committed', $again->value("SELECT value FROM meta WHERE key = 'probe-open'"));
        $this->assertThrows(\PDOException::class, static fn () => $again->exec('DELETE FROM meta'));
    }

    // --- flock between processes --------------------------------------------------------------

    public function testACacheKeyLockedByAnotherProcessIsBusyOrStaleAndThenFree(): void
    {
        $dir = $this->scratch();
        $clock = new FakeClock();
        $cache = new FileCache($dir . '/cache', $clock, 50);
        $release = $dir . '/release';
        $child = $this->lockHolder([$cache->lockPathFor('fresh-key'), $cache->lockPathFor('old-key')], $release);

        $calls = 0;
        $producer = static function () use (&$calls): string {
            $calls++;
            return 'computed';
        };

        $busy = $cache->remember('fresh-key', 60, $producer);
        $this->assertSame(CacheStatus::Busy, $busy->status);
        $this->assertNull($busy->value, 'no stale value to give');

        $cache->set('old-key', 'old value', 1);
        $clock->advance(5);
        $stale = $cache->remember('old-key', 60, $producer);
        $this->assertSame(CacheStatus::Stale, $stale->status);
        $this->assertSame('old value', $stale->value);
        $this->assertSame(0, $calls, 'the producer did not run while another process held the lock');

        $this->release($child, $release);
        $this->assertSame(CacheStatus::Computed, $cache->remember('fresh-key', 60, $producer)->status);
        $this->assertSame(CacheStatus::Computed, $cache->remember('old-key', 60, $producer)->status);
        $this->assertSame(2, $calls);
    }

    public function testTheIndexerLockFileKeepsASecondProcessOutAndThenLetsItIn(): void
    {
        $dir = $this->scratch();
        $lock = $dir . '/indexer.lock';
        $release = $dir . '/release';
        $child = $this->lockHolder([$lock], $release);

        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $app = new IndexerApplication(
            $this->config(),
            $this->transport,
            $this->clock,
            fn (): Database => $this->db,
            $out,
            $err,
            $lock,
            $dir . '/cache',
        );
        $this->assertSame(0, $app->run());
        rewind($out);
        rewind($err);
        $this->assertSame('', (string) stream_get_contents($out));
        $this->assertSame('', (string) stream_get_contents($err));
        $this->assertCount(0, $this->transport->calls, 'no node was contacted while the lock was held');
        $this->assertSame(0, $this->count('runs'));

        $this->release($child, $release);
        // With the lock free the run goes ahead; there is no node to answer, so it ends in an
        // error, but it did run and recorded that.
        $this->down(self::A);
        $this->down(self::B);
        $this->down(self::F);
        $this->assertSame(1, $app->run());
        $this->assertSame(1, $this->count('runs'));
        fclose($out);
        fclose($err);
    }

    public function testTheLimiterCountsAcrossProcesses(): void
    {
        $dir = $this->scratch() . '/limiter';
        $root = dirname(__DIR__);
        $program = '<?php
require ' . var_export($root . '/src/autoload.php', true) . ';
$limiter = \Lateo\Explorer\Rpc\CallLimiter::fallback(' . var_export($dir, true) . ', 4);
for ($i = 0; $i < 3; $i++) {
    $limiter->acquire("node");
}
echo "DONE\n";
';
        $child = $this->child($program);
        $this->assertSame('DONE', $child->readLine(), 'the child took three calls: ' . $child->errors());
        $this->assertTrue($child->waitForExit());

        $limiter = CallLimiter::fallback($dir, 4);
        $limiter->acquire('node'); // the fourth call of the minute is still allowed
        $e = $this->assertThrows(RpcException::class, static fn () => $limiter->acquire('node'));
        $this->assertSame(ErrorCategory::Budget, $e->category);
    }

    // --- the curl transport against a local listener ------------------------------------------

    /** Start the built-in server with a router script; returns the port. */
    private function startServer(): int
    {
        if (!function_exists('curl_init')) {
            throw new SkipTest('curl is not available');
        }
        $probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($probe === false) {
            throw new SkipTest('no local port can be bound');
        }
        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);

        $dir = $this->scratch();
        file_put_contents($dir . '/router.php', '<?php
$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
if ($path === "/ok") {
    header("Content-Type: application/json");
    echo "{\"id\":\"0\",\"jsonrpc\":\"2.0\",\"result\":{\"status\":\"OK\"}}";
} elseif ($path === "/slow") {
    usleep(1500000);
    echo "late";
} elseif ($path === "/big") {
    echo str_repeat("x", 5000);
} else {
    http_response_code(404);
    echo "no";
}
');
        $this->children[] = ChildProcess::server(
            [PHP_BINARY, '-n', '-S', '127.0.0.1:' . $port, $dir . '/router.php'],
            $dir,
        );
        $deadline = microtime(true) + 3.0;
        while (microtime(true) < $deadline) {
            $c = @fsockopen('127.0.0.1', $port, $en, $es, 0.2);
            if ($c !== false) {
                fclose($c);
                return $port;
            }
            usleep(50000);
        }
        throw new \RuntimeException('the local server did not accept connections within 3 s');
    }

    public function testTheCurlTransportAgainstALocalListener(): void
    {
        $port = $this->startServer();
        $transport = new CurlTransport();
        $url = 'http://127.0.0.1:' . $port;

        $r = $transport->post($url . '/ok', '{}', new RequestLimits(1.0, 3.0, 4096));
        $this->assertSame(200, $r->status);
        $this->assertSame('{"id":"0","jsonrpc":"2.0","result":{"status":"OK"}}', $r->body);

        $r = $transport->post($url . '/missing', '{}', new RequestLimits(1.0, 3.0, 4096));
        $this->assertSame(404, $r->status, 'a status other than 200 comes back as it is');
        $this->assertSame('no', $r->body);

        $e = $this->assertThrows(TransportFailure::class, static fn () => $transport->post($url . '/big', '{}', new RequestLimits(1.0, 3.0, 1024)));
        $this->assertSame(ErrorCategory::TooLarge, $e->category);

        $started = microtime(true);
        $e = $this->assertThrows(TransportFailure::class, static fn () => $transport->post($url . '/slow', '{}', new RequestLimits(1.0, 0.4, 4096)));
        $this->assertSame(ErrorCategory::Timeout, $e->category);
        $this->assertTrue(microtime(true) - $started < 1.4, 'the total timeout cut the wait short');
    }

    public function testAPortNobodyListensOnIsAConnectFailure(): void
    {
        if (!function_exists('curl_init')) {
            throw new SkipTest('curl is not available');
        }
        $probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($probe === false) {
            throw new SkipTest('no local port can be bound');
        }
        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe); // the port is free again and nobody listens on it
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);

        $e = $this->assertThrows(
            TransportFailure::class,
            static fn () => (new CurlTransport())->post('http://127.0.0.1:' . $port . '/ok', '{}', new RequestLimits(1.0, 3.0, 4096)),
        );
        $this->assertSame(ErrorCategory::Connect, $e->category);
    }
}
