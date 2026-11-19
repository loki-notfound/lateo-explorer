<?php

declare(strict_types=1);

namespace Lateo\Explorer\Rpc;

/**
 * A successful call. $data is the "result" object of a JSON-RPC call, or the whole body of a
 * plain-path call. Integers above PHP_INT_MAX are exact decimal strings.
 */
final readonly class RpcResult
{
    /** @param array<string,mixed> $data */
    public function __construct(
        public array $data,
        /** Label of the node that answered. */
        public string $node,
    ) {
    }
}
