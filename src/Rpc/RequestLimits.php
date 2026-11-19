<?php

declare(strict_types=1);

namespace Lateo\Explorer\Rpc;

/** Per-request bounds handed to a transport. */
final readonly class RequestLimits
{
    public function __construct(
        public float $connectTimeout,
        public float $totalTimeout,
        public int $maxBytes,
    ) {
    }
}
