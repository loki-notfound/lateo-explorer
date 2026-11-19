<?php

declare(strict_types=1);

namespace Lateo\Explorer\Tests\Support;

use Lateo\Explorer\Rpc\TransportResponse;

/**
 * A chain of block headers held in memory and served like a node would: get_last_block_header
 * and get_block_headers_range. Headers are the arrays of the daemon's JSON (decoded), either
 * built here (synthetic()) or read from a recorded fixture (fromFixture()).
 *
 * A test can change what is served: mutateHeaders() on the decoded headers, mutateBody() on
 * the raw JSON text of a range response.
 */
final class FakeChain
{
    /** @var array<int,array<string,mixed>> headers by height */
    private array $headers = [];
    /** @var (\Closure(list<array<string,mixed>>): list<array<string,mixed>>)|null */
    private ?\Closure $headerMutation = null;
    /** @var (\Closure(string): string)|null */
    private ?\Closure $bodyMutation = null;
    /** Number of calls answered, by method. @var array<string,int> */
    public array $served = [];

    /** @param list<array<string,mixed>> $headers consecutive, ascending */
    public function __construct(array $headers)
    {
        foreach ($headers as $h) {
            $this->headers[(int) $h['height']] = $h;
        }
        ksort($this->headers);
    }

    /** The headers of a recorded get_block_headers_range response. */
    public static function fromFixture(string $name): self
    {
        $file = __DIR__ . '/../fixtures/rpc/' . $name . '.json';
        $decoded = json_decode((string) file_get_contents($file), true, 512, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
        return new self($decoded['result']['headers']);
    }

    /**
     * Valid headers from $from to $to. The hashes depend on $seed and the height, so two seeds
     * give two chains that differ from the first block on.
     */
    public static function synthetic(int $from, int $to, string $seed = 'a', ?string $prevHash = null): self
    {
        $headers = [];
        $prev = $prevHash ?? hash('sha256', $seed . ':before:' . $from);
        for ($h = $from; $h <= $to; $h++) {
            $hash = self::hashOf($seed, $h);
            $headers[] = self::header($h, $hash, $prev);
            $prev = $hash;
        }
        return new self($headers);
    }

    public static function hashOf(string $seed, int $height): string
    {
        return hash('sha256', $seed . ':' . $height);
    }

    /**
     * A chain that follows $base up to $forkHeight and then continues with other blocks (seed
     * $seed) up to $newTop. The new blocks carry $extraWork more (or, when negative, less)
     * cumulative difficulty than a block of the same height in $base; the default of 1 makes
     * the new branch the heavier one at any height.
     */
    public static function forked(self $base, int $forkHeight, string $seed, int $newTop, int $extraWork = 1): self
    {
        $headers = [];
        foreach ($base->headers as $h => $header) {
            if ($h <= $forkHeight) {
                $headers[] = $header;
            }
        }
        $prev = $base->headers[$forkHeight]['hash'];
        for ($h = $forkHeight + 1; $h <= $newTop; $h++) {
            $hash = self::hashOf($seed, $h);
            $header = self::header($h, $hash, $prev);
            self::addWorkTo($header, $extraWork);
            $headers[] = $header;
            $prev = $hash;
        }
        return new self($headers);
    }

    /** Add $extra to the cumulative difficulty of every header from $fromHeight on. */
    public function addWork(int $fromHeight, int $extra): self
    {
        foreach ($this->headers as $h => $header) {
            if ($h >= $fromHeight) {
                self::addWorkTo($header, $extra);
                $this->headers[$h] = $header;
            }
        }
        return $this;
    }

    /** @param array<string,mixed> $header */
    private static function addWorkTo(array &$header, int $extra): void
    {
        $cumulative = (int) $header['cumulative_difficulty'] + $extra;
        $header['cumulative_difficulty'] = $cumulative;
        $header['wide_cumulative_difficulty'] = '0x' . dechex($cumulative);
    }

    /** @return array<string,mixed> */
    public static function header(int $height, string $hash, string $prev): array
    {
        $diff = 1000 + $height;
        $cumulative = $height * 1000 + 5;
        return [
            'block_size' => 300 + $height % 7,
            'block_weight' => 300 + $height % 7,
            'cumulative_difficulty' => $cumulative,
            'cumulative_difficulty_top64' => 0,
            'depth' => 0,
            'difficulty' => $diff,
            'difficulty_top64' => 0,
            'hash' => $hash,
            'height' => $height,
            'long_term_weight' => 300,
            'major_version' => 11,
            'miner_tx_hash' => hash('sha256', 'miner:' . $hash),
            'minor_version' => 11,
            'nonce' => 12345 + $height,
            'num_txes' => $height % 3,
            'orphan_status' => false,
            'pow_hash' => '',
            'prev_hash' => $prev,
            'pricing_record' => [
                'moving_average' => 100_000_000_000 + $height,
                'reserve' => 200_000_000_000 + $height,
                'reserve_ma' => 300_000_000_000,
                'reserve_ratio' => 400_000_000_000,
                'reserve_ratio_ma' => 500_000_000_000,
                'signature' => str_repeat('ab', 64),
                'spot' => 600_000_000_000,
                'stable' => 700_000_000_000,
                'stable_ma' => 800_000_000_000,
                'timestamp' => 1_700_000_000 + $height * 120,
                'yield_price' => 900_000_000_000,
            ],
            'reward' => 3_000_000_000_000 + $height,
            'timestamp' => 1_700_000_000 + $height * 120,
            'wide_cumulative_difficulty' => '0x' . dechex($cumulative),
            'wide_difficulty' => '0x' . dechex($diff),
        ];
    }

    /** @return array<string,mixed> */
    public function at(int $height): array
    {
        return $this->headers[$height];
    }

    public function hashAt(int $height): string
    {
        return (string) $this->headers[$height]['hash'];
    }

    public function topHeight(): int
    {
        return (int) array_key_last($this->headers);
    }

    /** Replace one header (for example to add a rewards list or break a field). @param array<string,mixed> $header */
    public function set(int $height, array $header): void
    {
        $this->headers[$height] = $header;
    }

    /** @param \Closure(list<array<string,mixed>>): list<array<string,mixed>> $fn applied to every served range */
    public function mutateHeaders(?\Closure $fn): void
    {
        $this->headerMutation = $fn;
    }

    /** @param \Closure(string): string $fn applied to the raw JSON of every served range */
    public function mutateBody(?\Closure $fn): void
    {
        $this->bodyMutation = $fn;
    }

    /** Serve a call like a node: get_last_block_header and get_block_headers_range only. */
    public function respond(RecordedCall $call): TransportResponse
    {
        $this->served[$call->method] = ($this->served[$call->method] ?? 0) + 1;
        if ($call->method === 'get_last_block_header') {
            return new TransportResponse(200, self::ok(['block_header' => $this->headers[$this->topHeight()]]));
        }
        if ($call->method === 'get_block_headers_range') {
            $start = (int) $call->params['start_height'];
            $end = (int) $call->params['end_height'];
            if (!isset($this->headers[$start]) || !isset($this->headers[$end]) || $end < $start) {
                return new TransportResponse(200, '{"id":"0","jsonrpc":"2.0","error":{"code":-2,"message":"out of range"}}');
            }
            $list = [];
            for ($h = $start; $h <= $end; $h++) {
                $list[] = $this->headers[$h];
            }
            if ($this->headerMutation !== null) {
                $list = ($this->headerMutation)($list);
            }
            $body = self::ok(['headers' => $list]);
            if ($this->bodyMutation !== null) {
                $body = ($this->bodyMutation)($body);
            }
            return new TransportResponse(200, $body);
        }
        throw new \LogicException('FakeChain does not serve ' . $call->method);
    }

    /** @param array<string,mixed> $result */
    private static function ok(array $result): string
    {
        return json_encode(
            ['id' => '0', 'jsonrpc' => '2.0', 'result' => ['status' => 'OK', 'untrusted' => false] + $result],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
    }
}
