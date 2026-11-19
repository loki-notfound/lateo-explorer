<?php

declare(strict_types=1);

namespace Lateo\Explorer\Tests\Support;

use Lateo\Explorer\Config\Config;
use Lateo\Explorer\Db\ChainStore;
use Lateo\Explorer\Db\Database;
use Lateo\Explorer\Db\Schema;
use Lateo\Explorer\Indexer\HeaderParser;
use Lateo\Explorer\Rpc\ErrorCategory;
use Lateo\Explorer\Rpc\TransportFailure;
use Lateo\Explorer\Rpc\TransportResponse;
use Lateo\Explorer\Web\Application;
use Lateo\Explorer\Web\Request;
use Lateo\Explorer\Web\Response;

/**
 * Common setup of the page tests: an in-memory index filled from the recorded fixtures, a fake
 * clock, a fixture transport and a cache in the scratch directory. Pages are rendered through
 * the real Application, without a web server.
 */
abstract class WebTestBase extends TestCase
{
    protected const A = 'node-a.example.invalid';
    protected const B = 'node-b.example.invalid';
    protected const F = 'node.lateo.dev';

    /** The recorded ranges the standard index holds. */
    protected const RANGES = [
        'get_block_headers_range__end_height-910659__start_height-910640',
        'get_block_headers_range__end_height-89302__start_height-89298',
        'get_block_headers_range__end_height-536002__start_height-535998',
    ];

    protected FakeClock $clock;
    protected Database $db;
    protected FixtureTransport $transport;
    protected string $cacheDir;

    public function setUp(): void
    {
        $this->clock = new FakeClock(1_795_048_800.0); // shortly after block 910670
        $this->db = Database::memory();
        Schema::migrate($this->db);
        $this->transport = new FixtureTransport();
        $this->cacheDir = $this->scratch() . '/cache';
    }

    protected static function root(): string
    {
        return __DIR__ . '/../..';
    }

    /** @param array<string,mixed> $limits */
    protected function config(array $limits = []): Config
    {
        return Config::fromArray([
            'nodes' => [
                ['label' => 'own-a', 'kind' => 'own', 'url' => 'http://' . self::A . ':17767'],
                ['label' => 'own-b', 'kind' => 'own', 'url' => 'http://' . self::B . ':17767'],
                ['label' => 'fallback', 'kind' => 'fallback', 'url' => 'https://' . self::F],
            ],
            'database' => '/srv/explorer-data/explorer.sqlite',
            'cache_dir' => '/srv/explorer-data/cache',
            'lock_file' => '/srv/explorer-data/indexer.lock',
            'limits' => $limits,
        ]);
    }

    /** @param array<string,mixed> $limits */
    protected function app(array $limits = [], ?\Closure $openDatabase = null): Application
    {
        return new Application(
            $this->config($limits),
            $this->transport,
            $this->clock,
            $openDatabase ?? fn (): Database => $this->db,
            self::root(),
            $this->cacheDir,
        );
    }

    /** Store the headers of a recorded range in the index. */
    protected function loadRange(string $fixture): void
    {
        $decoded = json_decode(
            (string) file_get_contents(__DIR__ . '/../fixtures/rpc/' . $fixture . '.json'),
            true,
            512,
            JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR,
        );
        $headers = array_map(static fn (array $h) => HeaderParser::parse($h), $decoded['result']['headers']);
        (new ChainStore($this->db))->insertBatch($headers);
    }

    protected function loadStandardIndex(): void
    {
        foreach (self::RANGES as $range) {
            $this->loadRange($range);
        }
    }

    /**
     * A header of the recorded ranges, as the daemon sent it.
     *
     * @return array<string,mixed>
     */
    protected function recorded(int $height): array
    {
        foreach (self::RANGES as $fixture) {
            $decoded = json_decode(
                (string) file_get_contents(__DIR__ . '/../fixtures/rpc/' . $fixture . '.json'),
                true,
                512,
                JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR,
            );
            foreach ($decoded['result']['headers'] as $h) {
                if ($h['height'] === $height) {
                    return $h;
                }
            }
        }
        throw new \LogicException('no recorded header at ' . $height);
    }

    /** Render a page: the response of a GET (or another method) to $path. */
    protected function get(string $path, ?string $q = null, string $method = 'GET', ?Application $app = null): Response
    {
        return ($app ?? $this->app())->handle(new Request($method, $path, $q));
    }

    /** Make a host fail every call at the transport. */
    protected function down(string ...$hosts): void
    {
        foreach ($hosts as $host) {
            $this->transport->serve($host, static function (RecordedCall $c): TransportResponse {
                throw new TransportFailure(ErrorCategory::Connect);
            });
        }
    }

    /** Answer get_last_block_header on a host with the recorded one, but another height and hash. */
    protected function tipAt(string $host, int $height, string $hash): void
    {
        $body = (string) file_get_contents(__DIR__ . '/../fixtures/rpc/get_last_block_header.json');
        $d = json_decode($body, true, 512, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
        $d['result']['block_header']['height'] = $height;
        $d['result']['block_header']['hash'] = $hash;
        $json = json_encode($d, JSON_THROW_ON_ERROR);
        $this->transport->serve($host, static fn (RecordedCall $c): TransportResponse => new TransportResponse(200, $json));
    }

    protected function calls(string $method): int
    {
        return count(array_filter($this->transport->calls, static fn (RecordedCall $c): bool => $c->method === $method));
    }
}
