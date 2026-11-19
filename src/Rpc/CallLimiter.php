<?php

declare(strict_types=1);

namespace Lateo\Explorer\Rpc;

use Lateo\Explorer\Support\Clock;
use Lateo\Explorer\Support\SystemClock;

/**
 * Caps a kind of calls per minute, over all processes. The timestamps of the last minute live
 * in one small file in the cache directory, guarded by flock. When the file cannot be used the
 * limiter refuses: without a count there is no call.
 *
 * It is used twice: for the calls to the fallback node (fallback-calls.json, named
 * "fallback") and for the web calls to the own nodes (own-calls.json, named "own-node").
 * The messages carry the short name and never an address.
 */
final class CallLimiter
{
    public const FALLBACK_FILE = 'fallback-calls.json';
    public const OWN_FILE = 'own-calls.json';

    private const WINDOW_SECONDS = 60.0;
    private const LOCK_WAIT_MS = 300;

    private readonly string $file;

    public function __construct(
        string $dir,
        private readonly int $perMinute,
        string $fileName,
        private readonly string $name,
        private readonly Clock $clock = new SystemClock(),
    ) {
        $this->file = rtrim($dir, '/') . '/' . $fileName;
    }

    /** The cap on calls to the fallback node. */
    public static function fallback(string $dir, int $perMinute, Clock $clock = new SystemClock()): self
    {
        return new self($dir, $perMinute, self::FALLBACK_FILE, 'fallback', $clock);
    }

    /** The cap on web calls to the own nodes (the indexer never uses it). */
    public static function ownNodes(string $dir, int $perMinute, Clock $clock = new SystemClock()): self
    {
        return new self($dir, $perMinute, self::OWN_FILE, 'own-node', $clock);
    }

    /** @throws RpcException with category budget when the cap is reached or the count unavailable */
    public function acquire(string $node): void
    {
        $dir = dirname($this->file);
        if (!file_exists($dir)) {
            @mkdir($dir, 0700, true);
        }
        $h = @fopen($this->file, 'c+');
        if ($h === false) {
            throw new RpcException(ErrorCategory::Budget, $node, $this->name . ' counter unavailable');
        }
        try {
            if (!$this->lock($h)) {
                throw new RpcException(ErrorCategory::Budget, $node, $this->name . ' counter busy');
            }
            $now = $this->clock->now();
            $stamps = $this->read($h, $now);
            if (count($stamps) >= $this->perMinute) {
                throw new RpcException(ErrorCategory::Budget, $node, $this->name . ' calls per minute used up');
            }
            $stamps[] = $now;
            $json = json_encode($stamps, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
            if (ftruncate($h, 0) === false || rewind($h) === false || fwrite($h, $json) !== strlen($json)) {
                throw new RpcException(ErrorCategory::Budget, $node, $this->name . ' counter unavailable');
            }
            fflush($h);
        } finally {
            flock($h, LOCK_UN);
            fclose($h);
        }
    }

    /** @param resource $h */
    private function lock($h): bool
    {
        $deadline = hrtime(true) + self::LOCK_WAIT_MS * 1_000_000;
        while (!flock($h, LOCK_EX | LOCK_NB)) {
            if (hrtime(true) >= $deadline) {
                return false;
            }
            usleep(5000);
        }
        return true;
    }

    /**
     * @param resource $h
     * @return list<float> the call times within the last minute
     */
    private function read($h, float $now): array
    {
        $raw = '';
        while (!feof($h)) {
            $chunk = fread($h, 65536);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $raw .= $chunk;
        }
        $decoded = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $stamps = [];
        foreach ($decoded as $t) {
            if ((is_int($t) || is_float($t)) && $t > $now - self::WINDOW_SECONDS && $t <= $now + 1.0) {
                $stamps[] = (float) $t;
            }
        }
        return $stamps;
    }
}
