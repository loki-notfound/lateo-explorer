<?php

declare(strict_types=1);

namespace Lateo\Explorer\Tests;

use Lateo\Explorer\Cache\CacheStatus;
use Lateo\Explorer\Cache\FileCache;
use Lateo\Explorer\Tests\Support\FakeClock;
use Lateo\Explorer\Tests\Support\TestCase;

final class CacheTest extends TestCase
{
    private function cache(?FakeClock &$clock = null, int $lockWaitMs = 40): FileCache
    {
        $clock = new FakeClock();
        return new FileCache($this->scratch() . '/cache', $clock, $lockWaitMs);
    }

    /** @return list<string> file names in the cache directory */
    private function files(FileCache $cache, string $key): array
    {
        $dir = dirname($cache->lockPathFor($key));
        return array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
    }

    public function testSetGetAndExpiry(): void
    {
        $clock = null;
        $cache = $this->cache($clock);
        $this->assertNull($cache->get('k'));
        $cache->set('k', ['a' => 1, 'b' => [true, null, 'x']], 10);
        $entry = $cache->get('k');
        $this->assertSame(['a' => 1, 'b' => [true, null, 'x']], $entry?->value);
        $this->assertTrue($entry->fresh);
        $this->assertSame($entry->storedAt + 10, $entry->expiresAt);

        $clock->advance(9.9);
        $this->assertTrue($cache->get('k') !== null, 'still fresh just before the ttl');
        $clock->advance(0.2);
        $this->assertNull($cache->get('k'), 'expired');
        $stale = $cache->getStale('k');
        $this->assertSame(['a' => 1, 'b' => [true, null, 'x']], $stale?->value);
        $this->assertFalse($stale->fresh);
    }

    public function testSetOverwritesAndKeepsNullDistinctFromMiss(): void
    {
        $clock = null;
        $cache = $this->cache($clock);
        $cache->set('k', 'one', 5);
        $cache->set('k', 'two', 5);
        $this->assertSame('two', $cache->get('k')?->value);
        $cache->set('n', null, 5);
        $entry = $cache->get('n');
        $this->assertTrue($entry !== null, 'a stored null is a hit');
        $this->assertNull($entry->value);
        $this->assertNull($cache->get('missing'));
    }

    public function testBigIntegersSurviveAsExactStrings(): void
    {
        $clock = null;
        $cache = $this->cache($clock);
        $cache->set('big', ['free_space' => '18446744073709551615'], 5);
        $this->assertSame('18446744073709551615', $cache->get('big')?->value['free_space']);
    }

    public function testTtlMustBePositive(): void
    {
        $clock = null;
        $cache = $this->cache($clock);
        $this->assertThrows(\InvalidArgumentException::class, static fn () => $cache->set('k', 1, 0));
    }

    public function testKeysAreHashedAndNoTemporaryFileRemains(): void
    {
        $clock = null;
        $cache = $this->cache($clock);
        $key = 'tip/secret key with spaces?x=1';
        $cache->set($key, 1, 5);
        $files = $this->files($cache, $key);
        $this->assertCount(1, $files);
        $this->assertSame(hash('sha256', $key) . '.json', $files[0]);
        $this->assertSame(1, preg_match('/\A[0-9a-f]{64}\.json\z/', $files[0]));
    }

    public function testDamagedFileIsAMiss(): void
    {
        $clock = null;
        $cache = $this->cache($clock);
        $cache->set('k', 1, 5);
        $path = dirname($cache->lockPathFor('k')) . '/' . hash('sha256', 'k') . '.json';
        file_put_contents($path, '{"a":1,"e":');
        $this->assertNull($cache->get('k'));
        $this->assertNull($cache->getStale('k'));
        file_put_contents($path, '[1,2,3]');
        $this->assertNull($cache->get('k'));
    }

    public function testRememberComputesOnceThenHits(): void
    {
        $clock = null;
        $cache = $this->cache($clock);
        $calls = 0;
        $producer = static function () use (&$calls): array {
            $calls++;
            return ['n' => $calls];
        };
        $first = $cache->remember('r', 10, $producer);
        $this->assertSame(CacheStatus::Computed, $first->status);
        $this->assertSame(['n' => 1], $first->value);
        $second = $cache->remember('r', 10, $producer);
        $this->assertSame(CacheStatus::Hit, $second->status);
        $this->assertSame(['n' => 1], $second->value);
        $this->assertSame(1, $calls);

        $clock->advance(11);
        $third = $cache->remember('r', 10, $producer);
        $this->assertSame(CacheStatus::Computed, $third->status);
        $this->assertSame(['n' => 2], $third->value);
        $this->assertSame(2, $calls);
    }

    public function testRememberReturnsStaleWhenProducerFails(): void
    {
        $clock = null;
        $cache = $this->cache($clock);
        $cache->set('r', 'old', 5);
        $clock->advance(6);
        $result = $cache->remember('r', 5, static function (): never {
            throw new \RuntimeException('node down');
        });
        $this->assertSame(CacheStatus::Stale, $result->status);
        $this->assertSame('old', $result->value);
        $this->assertTrue($result->hasValue());
    }

    public function testRememberRethrowsWhenProducerFailsWithoutAnOldValue(): void
    {
        $clock = null;
        $cache = $this->cache($clock);
        $this->assertThrows(\RuntimeException::class, static fn () => $cache->remember('r', 5, static function (): never {
            throw new \RuntimeException('node down');
        }));
        // the lock was released: a later call can compute
        $this->assertSame(CacheStatus::Computed, $cache->remember('r', 5, static fn () => 'ok')->status);
    }

    public function testContentionWithAStaleValueReturnsIt(): void
    {
        $clock = null;
        $cache = $this->cache($clock);
        $cache->set('c', 'old', 5);
        $clock->advance(6);

        $other = fopen($cache->lockPathFor('c'), 'c+'); // a second handle: another process in real life
        $this->assertTrue(flock($other, LOCK_EX | LOCK_NB), 'the test handle takes the lock');
        $called = false;
        $result = $cache->remember('c', 5, static function () use (&$called): string {
            $called = true;
            return 'new';
        });
        $this->assertSame(CacheStatus::Stale, $result->status);
        $this->assertSame('old', $result->value);
        $this->assertFalse($called, 'the producer must not run while another handle holds the lock');

        flock($other, LOCK_UN);
        fclose($other);
        $after = $cache->remember('c', 5, static fn () => 'new');
        $this->assertSame(CacheStatus::Computed, $after->status);
        $this->assertSame('new', $after->value);
    }

    public function testContentionWithoutAValueReportsBusy(): void
    {
        $clock = null;
        $cache = $this->cache($clock);
        $other = fopen($cache->lockPathFor('c'), 'c+');
        $this->assertTrue(flock($other, LOCK_EX | LOCK_NB));
        $result = $cache->remember('c', 5, static fn () => 'new');
        $this->assertSame(CacheStatus::Busy, $result->status);
        $this->assertFalse($result->hasValue());
        $this->assertNull($result->value);
        flock($other, LOCK_UN);
        fclose($other);
    }

    public function testTheLockWaitIsAtMostAboutThreeHundredMilliseconds(): void
    {
        $this->assertSame(300, FileCache::LOCK_WAIT_MS);
        $clock = new FakeClock();
        $cache = new FileCache($this->scratch() . '/cache', $clock); // the default wait
        $other = fopen($cache->lockPathFor('c'), 'c+');
        flock($other, LOCK_EX | LOCK_NB);
        $t = hrtime(true);
        $result = $cache->remember('c', 5, static fn () => 'new');
        $ms = (hrtime(true) - $t) / 1_000_000;
        $this->assertSame(CacheStatus::Busy, $result->status);
        $this->assertTrue($ms >= 250 && $ms < 1500, 'waited ' . round($ms) . ' ms');
        flock($other, LOCK_UN);
        fclose($other);
    }

    public function testSharedLockHeldByAnotherHandleAlsoBlocks(): void
    {
        $clock = null;
        $cache = $this->cache($clock);
        $other = fopen($cache->lockPathFor('s'), 'c+');
        flock($other, LOCK_SH);
        $this->assertSame(CacheStatus::Busy, $cache->remember('s', 5, static fn () => 1)->status);
        flock($other, LOCK_UN);
        fclose($other);
    }

    public function testLockOfOneKeyDoesNotBlockAnother(): void
    {
        $clock = null;
        $cache = $this->cache($clock);
        $other = fopen($cache->lockPathFor('one'), 'c+');
        flock($other, LOCK_EX | LOCK_NB);
        $this->assertSame(CacheStatus::Computed, $cache->remember('two', 5, static fn () => 2)->status);
        flock($other, LOCK_UN);
        fclose($other);
    }

    public function testSweepRemovesExpiredEntriesAndCapsTheNumber(): void
    {
        $clock = null;
        $cache = $this->cache($clock);
        foreach (['a' => 5, 'b' => 100, 'c' => 200, 'd' => 300, 'e' => 400] as $key => $ttl) {
            $cache->set($key, $key, $ttl);
        }
        $clock->advance(10); // a has expired
        $removed = $cache->sweep(10);
        $this->assertSame(1, $removed);
        $this->assertNull($cache->getStale('a'));
        $this->assertTrue($cache->get('b') !== null);

        $removed = $cache->sweep(2); // keep the two that expire last
        $this->assertSame(2, $removed);
        $this->assertNull($cache->getStale('b'));
        $this->assertNull($cache->getStale('c'));
        $this->assertSame('d', $cache->get('d')?->value);
        $this->assertSame('e', $cache->get('e')?->value);
    }

    public function testSweepRemovesTheEntriesWrittenLongestAgoNotThoseThatExpireFirst(): void
    {
        $clock = null;
        $cache = $this->cache($clock);
        // 2000 one-day entries written over ten minutes (the ttl grows by one second per
        // entry, so the earlier written also expire earlier).
        for ($i = 0; $i < 2000; $i++) {
            $cache->set('list:' . $i, $i, 86400 + $i);
            $clock->advance(0.3);
        }
        // Then three short-lived entries written now: they expire long before any list entry.
        $cache->set('tip', 1, 10);
        $cache->set('status', 2, 15);
        $cache->set('skip:own-a', 3, 30);

        $removed = $cache->sweep(2000);
        $this->assertSame(3, $removed);
        $this->assertTrue($cache->get('tip') !== null, 'the tip entry stays');
        $this->assertTrue($cache->get('status') !== null, 'the status entry stays');
        $this->assertTrue($cache->get('skip:own-a') !== null, 'the skip marker stays');
        foreach ([0, 1, 2] as $i) {
            $this->assertNull($cache->getStale('list:' . $i), 'the oldest list entry ' . $i . ' went');
        }
        foreach ([3, 1000, 1999] as $i) {
            $this->assertTrue($cache->get('list:' . $i) !== null, 'list entry ' . $i . ' stays');
        }
        $this->assertSame(0, $cache->sweep(2000), 'a second sweep finds nothing to do');
    }

    public function testSweepKeepsStaleEntriesForTheGivenGrace(): void
    {
        $clock = null;
        $cache = $this->cache($clock);
        $cache->set('a', 1, 5);
        $clock->advance(20); // expired for 15 s
        $this->assertSame(0, $cache->sweep(10, 60));
        $this->assertTrue($cache->getStale('a') !== null);
        $clock->advance(60);
        $this->assertSame(1, $cache->sweep(10, 60));
    }

    public function testSweepRemovesDamagedFilesOldTemporaryFilesAndUnusedLocks(): void
    {
        $clock = null;
        $cache = $this->cache($clock);
        $dir = dirname($cache->lockPathFor('x'));
        $cache->set('live', 1, 1000);
        $hash = static fn (string $s): string => hash('sha256', $s);

        file_put_contents($dir . '/' . $hash('broken') . '.json', 'not json');
        $oldTmp = $dir . '/' . $hash('t1') . '.' . ((int) $clock->time - 120) . '-abcd.tmp';
        $newTmp = $dir . '/' . $hash('t2') . '.' . ((int) $clock->time - 5) . '-abcd.tmp';
        file_put_contents($oldTmp, 'x');
        file_put_contents($newTmp, 'x');
        file_put_contents($dir . '/' . $hash('orphan') . '.lock', '');
        file_put_contents($cache->lockPathFor('live'), '');
        file_put_contents($dir . '/README.txt', 'foreign file');

        $held = fopen($dir . '/' . $hash('held') . '.lock', 'c+');
        flock($held, LOCK_EX | LOCK_NB);

        $removed = $cache->sweep(100);
        $this->assertSame(3, $removed, 'broken entry, old temporary file, orphan lock');
        $left = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
        sort($left);
        $expected = [
            $hash('held') . '.lock',
            $hash('live') . '.json',
            $hash('live') . '.lock',
            basename($newTmp),
            'README.txt',
        ];
        sort($expected);
        $this->assertSame($expected, $left);
        flock($held, LOCK_UN);
        fclose($held);
    }
}
