<?php

declare(strict_types=1);

namespace Lateo\Explorer\Rpc;

/** How a client behaves: the web profile for page requests, the indexer profile for cron. */
enum Profile: string
{
    case Web = 'web';
    case Indexer = 'indexer';

    public function connectTimeout(): float
    {
        return match ($this) {
            self::Web => 1.0,
            self::Indexer => 2.0,
        };
    }

    public function totalTimeout(): float
    {
        return match ($this) {
            self::Web => 3.0,
            self::Indexer => 20.0,
        };
    }

    /** Extra tries on the same node after a connect, timeout or decode error. */
    public function sameNodeRetries(): int
    {
        return match ($this) {
            self::Web => 0,
            self::Indexer => 2,
        };
    }

    /** Seconds to wait before a retry on the same node. */
    public function retryDelay(): float
    {
        return match ($this) {
            self::Web => 0.0,
            self::Indexer => 1.0,
        };
    }

    /** Cap on one response, in bytes. A page of 1000 headers is about 1.5 MB. */
    public function maxResponseBytes(): int
    {
        return match ($this) {
            self::Web => 4 * 1024 * 1024,
            self::Indexer => 16 * 1024 * 1024,
        };
    }

    /** Whether the fallback node may be used when the caller does not say. */
    public function fallbackByDefault(): bool
    {
        return $this === self::Web;
    }

    /** Whether the number of attempts per client is capped (a client is one page request). */
    public function hasCallBudget(): bool
    {
        return $this === self::Web;
    }
}
