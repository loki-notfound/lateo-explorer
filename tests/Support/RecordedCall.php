<?php

declare(strict_types=1);

namespace Lateo\Explorer\Tests\Support;

/** One request seen by the fixture transport. */
final readonly class RecordedCall
{
    public function __construct(
        /** Host of the url, for example own-a.example.invalid. */
        public string $host,
        /** The URL path: /json_rpc or a plain path. */
        public string $path,
        /** The JSON-RPC method, or the plain path for plain calls. */
        public string $method,
        /** @var array<string,mixed> */
        public array $params,
        public string $body,
    ) {
    }
}
