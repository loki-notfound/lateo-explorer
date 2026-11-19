<?php

declare(strict_types=1);

namespace Lateo\Explorer\Rpc;

/**
 * The one exception type of the RPC client. The message holds the category, the node's label
 * and a fixed phrase of this code. It never holds a URL, an address, text of the node or text
 * of curl.
 */
final class RpcException extends \RuntimeException
{
    public function __construct(
        public readonly ErrorCategory $category,
        public readonly ?string $node = null,
        string $detail = '',
    ) {
        parent::__construct(
            'rpc ' . $category->value
            . ($node !== null ? ' (node ' . $node . ')' : '')
            . ($detail !== '' ? ': ' . $detail : '')
        );
    }
}
