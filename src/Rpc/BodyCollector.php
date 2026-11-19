<?php

declare(strict_types=1);

namespace Lateo\Explorer\Rpc;

/**
 * Collects a response body for a transport and enforces the size cap. Used as the write
 * callback of curl: returning 0 aborts the transfer.
 */
final class BodyCollector
{
    private string $body = '';
    private bool $overflowed = false;

    public function __construct(private readonly int $maxBytes)
    {
    }

    /** @return int the number of bytes taken; 0 when the chunk would pass the cap */
    public function accept(string $chunk): int
    {
        if (strlen($this->body) + strlen($chunk) > $this->maxBytes) {
            $this->overflowed = true;
            return 0;
        }
        $this->body .= $chunk;
        return strlen($chunk);
    }

    public function overflowed(): bool
    {
        return $this->overflowed;
    }

    public function body(): string
    {
        return $this->body;
    }
}
