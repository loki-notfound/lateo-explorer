<?php

declare(strict_types=1);

namespace Lateo\Explorer\Cache;

use Lateo\Explorer\Support\Clock;
use Lateo\Explorer\Support\SystemClock;

/**
 * A small file cache shared by all requests and the indexer (cache_dir).
 *
 * - Values are JSON. A key is hashed with sha256; the file is <hash>.json, written under a
 *   temporary name and renamed into place, so a reader never sees half a file.
 * - remember() computes a missing or expired value under a per-key lock (flock, not
 *   blocking, waiting at most 300 ms). Under contention it returns the stale value when there
 *   is one, and otherwise reports Busy so that the page renders without the value.
 * - Only functions that also work through a PHP stream wrapper are used: fopen (also c+),
 *   flock, fwrite, fread, rename, unlink, scandir, file_get_contents, file_exists, mkdir.
 */
final class FileCache
{
    public const LOCK_WAIT_MS = 300;

    private readonly string $dir;

    public function __construct(
        string $dir,
        private readonly Clock $clock = new SystemClock(),
        private readonly int $lockWaitMs = self::LOCK_WAIT_MS,
    ) {
        $this->dir = rtrim($dir, '/');
        if ($this->dir === '') {
            throw new \InvalidArgumentException('cache directory is empty');
        }
        if (!file_exists($this->dir) && !@mkdir($this->dir, 0700, true) && !file_exists($this->dir)) {
            throw new \RuntimeException('cache directory cannot be created');
        }
    }

    /** A fresh entry, or null. */
    public function get(string $key): ?CacheEntry
    {
        $entry = $this->read($key);
        return $entry !== null && $entry->fresh ? $entry : null;
    }

    /** The entry even when it has expired (check ->fresh), or null. */
    public function getStale(string $key): ?CacheEntry
    {
        return $this->read($key);
    }

    /** Store a JSON-encodable value for $ttl seconds (it stays readable as stale until swept). */
    public function set(string $key, mixed $value, int $ttl): void
    {
        if ($ttl < 1) {
            throw new \InvalidArgumentException('ttl must be at least 1 second');
        }
        $now = (int) floor($this->clock->now());
        $json = json_encode(
            ['a' => $now, 'e' => $now + $ttl, 'v' => $value],
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES,
        );
        $final = $this->base($key) . '.json';
        $tmp = $this->base($key) . '.' . $now . '-' . bin2hex(random_bytes(4)) . '.tmp';
        $h = @fopen($tmp, 'wb');
        if ($h === false) {
            throw new \RuntimeException('cache file cannot be written');
        }
        $written = fwrite($h, $json);
        fclose($h);
        if ($written !== strlen($json) || !@rename($tmp, $final)) {
            @unlink($tmp);
            throw new \RuntimeException('cache file cannot be written');
        }
    }

    /**
     * The fresh value, or the value of $producer stored for $ttl seconds.
     *
     * If $producer throws and an expired value exists, that is returned as Stale; without one
     * the exception goes on to the caller.
     *
     * @param callable():mixed $producer
     */
    public function remember(string $key, int $ttl, callable $producer): CacheResult
    {
        $seen = $this->read($key);
        if ($seen !== null && $seen->fresh) {
            return new CacheResult(CacheStatus::Hit, $seen->value);
        }
        $lock = $this->lock($key);
        if ($lock === null) {
            return $seen !== null
                ? new CacheResult(CacheStatus::Stale, $seen->value)
                : new CacheResult(CacheStatus::Busy, null);
        }
        try {
            // Another process may have filled the key while we waited for the lock.
            $now = $this->read($key);
            if ($now !== null && $now->fresh) {
                return new CacheResult(CacheStatus::Hit, $now->value);
            }
            try {
                $value = $producer();
            } catch (\Throwable $t) {
                $old = $now ?? $seen;
                if ($old !== null) {
                    return new CacheResult(CacheStatus::Stale, $old->value);
                }
                throw $t;
            }
            try {
                $this->set($key, $value, $ttl);
            } catch (\RuntimeException) {
                // A cache that cannot be written must not break the page: the value is still good.
            }
            return new CacheResult(CacheStatus::Computed, $value);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** The path of the lock file of a key (for tests and tools). */
    public function lockPathFor(string $key): string
    {
        return $this->base($key) . '.lock';
    }

    /**
     * Remove expired entries (kept for $keepStaleSeconds after expiry), damaged files, old
     * temporary files and unused lock files, then the entries that were written longest ago
     * (on a tie, the one that expires earlier) until at most $maxFiles entries remain. Age of
     * writing decides, not expiry: a long-lived entry written long ago must not outlive the
     * short-lived entries (tip, status, skip markers) that are written all the time. Files of
     * other names are left alone.
     *
     * @return int the number of files removed
     */
    public function sweep(int $maxFiles, int $keepStaleSeconds = 0): int
    {
        $names = @scandir($this->dir);
        if ($names === false) {
            return 0;
        }
        $now = $this->clock->now();
        $removed = 0;
        /** @var array<string,array{int,int}> $live name => [written at, expiry] */
        $live = [];
        foreach ($names as $name) {
            if (preg_match('/\A[0-9a-f]{64}\.(\d+)-[0-9a-f]+\.tmp\z/', $name, $m) === 1) {
                if ((int) $m[1] < $now - 60) {
                    $removed += $this->remove($name);
                }
                continue;
            }
            if (preg_match('/\A[0-9a-f]{64}\.json\z/', $name) !== 1) {
                continue;
            }
            $entry = $this->parse(@file_get_contents($this->dir . '/' . $name), $now);
            if ($entry === null || $entry->expiresAt + $keepStaleSeconds <= $now) {
                $removed += $this->remove($name);
            } else {
                $live[$name] = [$entry->storedAt, $entry->expiresAt];
            }
        }
        if (count($live) > $maxFiles) {
            uasort($live, static fn (array $x, array $y): int => $x <=> $y);
            foreach (array_slice(array_keys($live), 0, count($live) - $maxFiles) as $name) {
                $removed += $this->remove((string) $name);
            }
        }
        foreach ($names as $name) {
            if (preg_match('/\A([0-9a-f]{64})\.lock\z/', $name, $m) === 1
                && !file_exists($this->dir . '/' . $m[1] . '.json')) {
                $removed += $this->removeLock($name);
            }
        }
        return $removed;
    }

    private function base(string $key): string
    {
        return $this->dir . '/' . hash('sha256', $key);
    }

    private function read(string $key): ?CacheEntry
    {
        $path = $this->base($key) . '.json';
        if (!file_exists($path)) {
            return null;
        }
        return $this->parse(@file_get_contents($path), $this->clock->now());
    }

    private function parse(string|false $raw, float $now): ?CacheEntry
    {
        if ($raw === false || $raw === '') {
            return null;
        }
        try {
            $data = json_decode($raw, true, 512, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($data) || !is_int($data['a'] ?? null) || !is_int($data['e'] ?? null)
            || !array_key_exists('v', $data)) {
            return null;
        }
        return new CacheEntry($data['v'], $data['a'], $data['e'], $now < $data['e']);
    }

    /** @return resource|null the locked lock file, or null when it could not be locked in time */
    private function lock(string $key)
    {
        $h = @fopen($this->lockPathFor($key), 'c+');
        if ($h === false) {
            return null;
        }
        $deadline = hrtime(true) + $this->lockWaitMs * 1_000_000;
        while (!flock($h, LOCK_EX | LOCK_NB)) {
            if (hrtime(true) >= $deadline) {
                fclose($h);
                return null;
            }
            usleep(5000);
        }
        return $h;
    }

    private function remove(string $name): int
    {
        return @unlink($this->dir . '/' . $name) ? 1 : 0;
    }

    /**
     * Remove a lock file nobody holds. Another process may open it in the same moment; the
     * worst case is that two processes compute one value, which is harmless.
     */
    private function removeLock(string $name): int
    {
        $h = @fopen($this->dir . '/' . $name, 'c+');
        if ($h === false) {
            return 0;
        }
        $removed = 0;
        if (flock($h, LOCK_EX | LOCK_NB)) {
            $removed = $this->remove($name);
            flock($h, LOCK_UN);
        }
        fclose($h);
        return $removed;
    }
}
