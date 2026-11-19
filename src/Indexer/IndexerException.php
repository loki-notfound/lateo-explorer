<?php

declare(strict_types=1);

namespace Lateo\Explorer\Indexer;

/**
 * The indexer stops with this error. The message names the field or the rule, never a value
 * from a node and never an address.
 */
final class IndexerException extends \RuntimeException
{
    public function __construct(public readonly FailureKind $kind, string $detail)
    {
        parent::__construct($kind->value . ': ' . $detail);
    }
}
