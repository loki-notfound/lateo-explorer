<?php

declare(strict_types=1);

namespace Lateo\Explorer\Cache;

final readonly class CacheEntry
{
    public function __construct(
        public mixed $value,
        /** Unix time the value was stored. */
        public int $storedAt,
        /** Unix time from which the value is stale. */
        public int $expiresAt,
        public bool $fresh,
    ) {
    }
}
