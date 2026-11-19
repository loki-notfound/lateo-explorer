<?php

declare(strict_types=1);

namespace Lateo\Explorer\Tests\Support;

use Lateo\Explorer\Rpc\RequestLimits;
use Lateo\Explorer\Rpc\Transport;
use Lateo\Explorer\Rpc\TransportFailure;
use Lateo\Explorer\Rpc\TransportResponse;

/**
 * A transport for tests. It records every call and answers from a script per host (a queue
 * of failures, responses or closures) and otherwise from a fixture file in tests/fixtures/rpc,
 * chosen by method and parameters (see fixtureName()). A request for which there is neither
 * throws a LogicException: that is a mistake in the test.
 *
 * A fixture holds the body of a recorded response. A file <name>.status holds only an HTTP
 * status (a gated route) and is answered with an empty body.
 */
final class FixtureTransport implements Transport
{
    /** @var list<RecordedCall> */
    public array $calls = [];
    /** @var array<string,list<TransportFailure|TransportResponse|\Closure>> */
    private array $scripts = [];
    /** @var array<string,\Closure> */
    private array $handlers = [];

    public function __construct(private readonly string $dir = __DIR__ . '/../fixtures/rpc')
    {
    }

    /** @param list<TransportFailure|TransportResponse|\Closure> $queue consumed in order; then fixtures again */
    public function script(string $host, array $queue): void
    {
        $this->scripts[$host] = $queue;
    }

    /**
     * Answer every call to $host (after its script queue is used up) with $handler, which gets
     * the RecordedCall and returns a TransportResponse or throws a TransportFailure.
     *
     * @param \Closure(RecordedCall): TransportResponse $handler
     */
    public function serve(string $host, \Closure $handler): void
    {
        $this->handlers[$host] = $handler;
    }

    /** A JSON-RPC response with status OK; $members is raw JSON for more members of the result. */
    public static function rpcOk(string $members = ''): TransportResponse
    {
        return new TransportResponse(200, '{"id":"0","jsonrpc":"2.0","result":{"status":"OK"' . ($members !== '' ? ',' . $members : '') . '}}');
    }

    /** @return list<string> hosts of the calls, in order */
    public function hosts(): array
    {
        return array_map(static fn (RecordedCall $c): string => $c->host, $this->calls);
    }

    public function post(string $url, string $jsonBody, RequestLimits $limits): TransportResponse
    {
        $parts = parse_url($url);
        $host = (string) ($parts['host'] ?? '');
        $path = (string) ($parts['path'] ?? '');
        $decoded = json_decode($jsonBody, true, 512, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
        if ($path === '/json_rpc') {
            $method = (string) $decoded['method'];
            $params = $decoded['params'] ?? [];
        } else {
            $method = $path;
            $params = $decoded;
        }
        $call = new RecordedCall($host, $path, $method, is_array($params) ? $params : [], $jsonBody);
        $this->calls[] = $call;

        if (!empty($this->scripts[$host])) {
            $next = array_shift($this->scripts[$host]);
            if ($next instanceof TransportFailure) {
                throw $next;
            }
            if ($next instanceof \Closure) {
                return $next($call);
            }
            return $next;
        }
        if (isset($this->handlers[$host])) {
            return ($this->handlers[$host])($call);
        }
        return $this->fromFixture($call);
    }

    private function fromFixture(RecordedCall $call): TransportResponse
    {
        $name = self::fixtureName($call->method, $call->params);
        $json = $this->dir . '/' . $name . '.json';
        if (file_exists($json)) {
            $body = file_get_contents($json);
            if ($body === false) {
                throw new \LogicException('fixture cannot be read: ' . $name);
            }
            return new TransportResponse(200, $body);
        }
        $status = $this->dir . '/' . $name . '.status';
        if (file_exists($status)) {
            return new TransportResponse((int) trim((string) file_get_contents($status)), '');
        }
        throw new \LogicException('no fixture and no script for ' . $name);
    }

    /**
     * The fixture name of a call: the method (without a leading slash), then key-value pairs
     * in key order, joined by "__". A list value is its elements joined by "+", each cut to 12
     * characters; other values are cut to 40. Characters outside A-Z a-z 0-9 _ . + - become _.
     *
     * @param array<string,mixed> $params
     */
    public static function fixtureName(string $method, array $params): string
    {
        $parts = [self::clean(ltrim($method, '/'))];
        ksort($params, SORT_STRING);
        foreach ($params as $key => $value) {
            $parts[] = self::clean($key . '-' . self::valueText($value));
        }
        return implode('__', $parts);
    }

    private static function valueText(mixed $v): string
    {
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_array($v)) {
            if (!array_is_list($v)) {
                return 'obj';
            }
            return implode('+', array_map(static fn (mixed $x): string => substr(self::valueText($x), 0, 12), $v));
        }
        return substr((string) $v, 0, 40);
    }

    private static function clean(string $s): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_.+-]/', '_', $s);
    }
}
