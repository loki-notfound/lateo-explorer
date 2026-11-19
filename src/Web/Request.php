<?php

declare(strict_types=1);

namespace Lateo\Explorer\Web;

/**
 * A request as the pages see it: the method, the path and the search text. No other query
 * parameter is ever read, and no cookie or body is read at all.
 */
final readonly class Request
{
    public function __construct(
        public string $method,
        public string $path,
        /** The `q` parameter, only meaningful on /search; null when absent or not a plain string. */
        public ?string $query = null,
    ) {
    }

    /** @param array<string,mixed> $server $_SERVER */
    public static function fromGlobals(array $server): self
    {
        $method = is_string($server['REQUEST_METHOD'] ?? null) ? strtoupper($server['REQUEST_METHOD']) : 'GET';
        $uri = is_string($server['REQUEST_URI'] ?? null) ? $server['REQUEST_URI'] : '/';
        $path = parse_url($uri, PHP_URL_PATH);
        $q = isset($_GET['q']) && is_string($_GET['q']) ? $_GET['q'] : null;
        return new self($method, is_string($path) && $path !== '' ? $path : '/', $q);
    }
}
