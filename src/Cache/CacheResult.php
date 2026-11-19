<?php

declare(strict_types=1);

namespace Lateo\Explorer\Cache;

final readonly class CacheResult
{
    public function __construct(
        public CacheStatus $status,
        /** The value; null when the status is Busy. */
        public mixed $value,
    ) {
    }

    public function hasValue(): bool
    {
        return $this->status !== CacheStatus::Busy;
    }
}
