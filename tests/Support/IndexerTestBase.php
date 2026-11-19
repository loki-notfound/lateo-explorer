<?php

declare(strict_types=1);

namespace Lateo\Explorer\Tests\Support;

use Lateo\Explorer\Config\Config;
use Lateo\Explorer\Db\ChainStore;
use Lateo\Explorer\Db\Database;
use Lateo\Explorer\Db\Schema;
use Lateo\Explorer\Indexer\HeaderIndexer;
use Lateo\Explorer\Rpc\ErrorCategory;
use Lateo\Explorer\Rpc\FallbackLimiter;
use Lateo\Explorer\Rpc\Profile;
use Lateo\Explorer\Rpc\RpcClient;
use Lateo\Explorer\Rpc\TransportFailure;
use Lateo\Explorer\Rpc\TransportResponse;

/** Common setup of the indexer tests: an in-memory database, a fake clock, a fixture transport. */
abstract class IndexerTestBase extends TestCase
{
    protected const A = 'node-a.example.invalid';
    protected const B = 'node-b.example.invalid';
    protected const F = 'node.lateo.dev';

    protected FakeClock $clock;
    protected Database $db;
    protected ChainStore $store;
    protected FixtureTransport $transport;

    public function setUp(): void
    {
        $this->clock = new FakeClock();
        $this->db = Database::memory();
        Schema::migrate($this->db);
        $this->store = new ChainStore($this->db);
        $this->transport = new FixtureTransport();
    }

    /** @param array<string,mixed> $limits */
    protected function config(array $limits = []): Config
    {
        if (isset($limits['reorg_max_depth']) && !isset($limits['unsettled_depth'])) {
            $limits['unsettled_depth'] = min(10, $limits['reorg_max_depth']);
        }
        return Config::fromArray([
            'nodes' => [
                ['label' => 'own-a', 'kind' => 'own', 'url' => 'http://' . self::A . ':17777'],
                ['label' => 'own-b', 'kind' => 'own', 'url' => 'http://' . self::B . ':17777'],
                ['label' => 'fallback', 'kind' => 'fallback', 'url' => 'https://' . self::F],
            ],
            'database' => '/srv/explorer-data/explorer.sqlite',
            'cache_dir' => '/srv/explorer-data/cache',
            'lock_file' => '/srv/explorer-data/indexer.lock',
            'limits' => $limits,
        ]);
    }

    protected function client(Config $config): RpcClient
    {
        return new RpcClient(
            $config,
            $this->transport,
            Profile::Indexer,
            new FallbackLimiter($this->scratch() . '/limiter', $config->limits->fallbackCallsPerMinute, $this->clock),
            $this->clock,
        );
    }

    protected function indexer(Config $config, int $startHeight = 0): HeaderIndexer
    {
        return new HeaderIndexer($this->client($config), $this->store, $config->limits, $this->clock, $startHeight);
    }

    /** Make the given hosts (default: all three) serve $chain. @param list<string>|null $hosts */
    protected function serve(FakeChain $chain, ?array $hosts = null): void
    {
        foreach ($hosts ?? [self::A, self::B, self::F] as $host) {
            $this->transport->serve($host, static fn (RecordedCall $c): TransportResponse => $chain->respond($c));
        }
    }

    /** Make a host fail every call at the transport. */
    protected function down(string $host): void
    {
        $this->transport->serve($host, static function (RecordedCall $c): TransportResponse {
            throw new TransportFailure(ErrorCategory::Connect);
        });
    }

    /** Serve $chain and the recorded supply answers on one host. */
    protected function serveWithSupply(FakeChain $chain, string $host): void
    {
        $this->transport->serve($host, static function (RecordedCall $c) use ($chain): TransportResponse {
            if (in_array($c->method, ['get_reserve_info', 'get_circulating_supply', 'get_audited_supply'], true)) {
                return new TransportResponse(200, (string) file_get_contents(__DIR__ . '/../fixtures/rpc/' . $c->method . '.json'));
            }
            return $chain->respond($c);
        });
    }

    /** Serve $chain and the recorded supply answers on both own nodes. */
    protected function serveWithSupplyOnOwn(FakeChain $chain): void
    {
        $this->serveWithSupply($chain, self::A);
        $this->serveWithSupply($chain, self::B);
    }

    /** @return list<string> the hosts of the calls to $method, in order */
    protected function hostsOf(string $method): array
    {
        $out = [];
        foreach ($this->transport->calls as $c) {
            if ($c->method === $method) {
                $out[] = $c->host;
            }
        }
        return $out;
    }

    protected function count(string $table): int
    {
        return (int) $this->db->value('SELECT count(*) FROM ' . $table);
    }
}
