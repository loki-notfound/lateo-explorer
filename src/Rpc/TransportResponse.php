<?php

declare(strict_types=1);

namespace Lateo\Explorer\Rpc;

final readonly class TransportResponse
{
    public function __construct(
        public int $status,
        public string $body,
    ) {
    }
}
