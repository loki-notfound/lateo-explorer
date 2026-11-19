<?php

declare(strict_types=1);

namespace Lateo\Explorer\Rpc;

/** A transfer that failed. It carries a category only: curl's own text is dropped. */
final class TransportFailure extends \RuntimeException
{
    public function __construct(public readonly ErrorCategory $category)
    {
        parent::__construct('transport failure: ' . $category->value);
    }
}
