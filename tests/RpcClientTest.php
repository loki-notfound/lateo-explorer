<?php

declare(strict_types=1);

namespace Lateo\Explorer\Tests;

use Lateo\Explorer\Config\Config;
use Lateo\Explorer\Rpc\ErrorCategory;
use Lateo\Explorer\Rpc\CallLimiter;
use Lateo\Explorer\Rpc\Profile;
use Lateo\Explorer\Rpc\RpcClient;
use Lateo\Explorer\Rpc\RpcException;
use Lateo\Explorer\Rpc\RpcMethod;
use Lateo\Explorer\Rpc\TransportFailure;
use Lateo\Explorer\Rpc\TransportResponse;
use Lateo\Explorer\Tests\Support\FakeClock;
use Lateo\Explorer\Tests\Support\FixtureTransport;
use Lateo\Explorer\Tests\Support\TestCase;

final class RpcClientTest extends TestCase
{
    private const A = 'node-a.example.invalid';
    private const B = 'node-b.example.invalid';
    private const F = 'node.lateo.dev';

    private FakeClock $clock;

    public function setUp(): void
    {
        $this->clock = new FakeClock();
    }

    /**
     * @param array<string,mixed> $limits
     * @param list<string> $labels which of own-a, own-b and fallback to configure
     */
    private function config(array $limits = [], array $labels = ['own-a', 'own-b', 'fallback']): Config
    {
        $all = [
            'own-a' => ['label' => 'own-a', 'kind' => 'own', 'url' => 'http://' . self::A . ':17767'],
            'own-b' => ['label' => 'own-b', 'kind' => 'own', 'url' => 'http://' . self::B . ':17767'],
            'fallback' => ['label' => 'fallback', 'kind' => 'fallback', 'url' => 'https://' . self::F],
        ];
        return Config::fromArray([
            'nodes' => array_values(array_map(static fn (string $l): array => $all[$l], $labels)),
            'database' => '/srv/explorer-data/explorer.sqlite',
            'cache_dir' => '/srv/explorer-data/cache',
            'lock_file' => '/srv/explorer-data/indexer.lock',
            'limits' => $limits,
        ]);
    }

    private function client(
        FixtureTransport $transport,
        Profile $profile = Profile::Web,
        ?Config $config = null,
        ?CallLimiter $limiter = null,
        ?CallLimiter $ownLimiter = null,
    ): RpcClient {
        $config ??= $this->config();
        $dir = $this->scratch() . '/limiter';
        $limiter ??= CallLimiter::fallback($dir, $config->limits->fallbackCallsPerMinute, $this->clock);
        $ownLimiter ??= CallLimiter::ownNodes($dir, $config->limits->webOwnCallsPerMinute, $this->clock);
        return new RpcClient($config, $transport, $profile, $limiter, $this->clock, $ownLimiter);
    }

    private function assertRpc(ErrorCategory $category, callable $fn, ?string $node = null): RpcException
    {
        $e = $this->assertThrows(RpcException::class, $fn);
        $this->assertSame($category, $e->category, 'category of "' . $e->getMessage() . '"');
        if ($node !== null) {
            $this->assertSame($node, $e->node, 'node label');
        }
        return $e;
    }

    private static function failure(ErrorCategory $c): TransportFailure
    {
        return new TransportFailure($c);
    }

    // --- allow-list ---

    public function testTheAllowListIsExactlyTheFifteenCalls(): void
    {
        $names = array_map(static fn (RpcMethod $m): string => $m->value, RpcMethod::cases());
        sort($names);
        $expected = [
            'get_info', 'get_version', 'get_last_block_header', 'get_block_header_by_height',
            'get_block_header_by_hash', 'get_block_headers_range', 'get_block', 'hard_fork_info',
            'get_fee_estimate', 'get_reserve_info', 'get_circulating_supply', 'get_audited_supply',
            '/get_transactions', '/get_transaction_pool_hashes', '/get_transaction_pool_stats',
        ];
        sort($expected);
        $this->assertSame($expected, $names);
        foreach (RpcMethod::cases() as $m) {
            $this->assertSame($m->isPlain() ? $m->value : '/json_rpc', $m->path());
        }
    }

    public function testAnythingElseIsRefusedBeforeAnyIo(): void
    {
        $refused = [
            'get_connections', 'relay_tx', 'send_raw_transaction', '/send_raw_transaction', '/sendrawtransaction',
            '/get_transaction_pool', 'get_transactions', 'get_transaction_pool_hashes', '/get_info', '/json_rpc',
            'GET_INFO', 'get_info ', ' get_info', "get_info\n", '', '/', 'flush_txpool', 'set_bans', 'stop_daemon',
            '/stop_daemon', '/start_mining', 'get_peer_list', 'sync_info', 'get_alternate_chains', 'submit_block',
            'get_block_template', '/get_alt_blocks_hashes', '../get_info', 'get_info/../stop_daemon', 'getinfo',
            'get_block_count', 'get_txpool_backlog', 'get_output_distribution', 'is_key_image_spent', 'get_outs',
        ];
        foreach ([Profile::Web, Profile::Indexer] as $profile) {
            foreach ($refused as $name) {
                $t = new FixtureTransport();
                $c = $this->client($t, $profile);
                $this->assertRpc(ErrorCategory::RefusedMethod, static fn () => $c->call($name), null);
                $this->assertCount(0, $t->calls, 'no I/O for "' . $name . '"');
                $this->assertSame(0, $c->attempts());
            }
        }
    }

    public function testParametersCannotChangeTheTarget(): void
    {
        $t = new FixtureTransport();
        $t->script(self::A, [FixtureTransport::rpcOk()]);
        $c = $this->client($t);
        $c->call(RpcMethod::GetBlock, ['height' => 536000, 'method' => 'stop_daemon', 'path' => '/stop_daemon']);
        $this->assertSame('/json_rpc', $t->calls[0]->path);
        $this->assertSame('get_block', $t->calls[0]->method);
    }

    public function testRequestShape(): void
    {
        $t = new FixtureTransport();
        $c = $this->client($t);
        $c->call('get_info');
        $this->assertSame('{"jsonrpc":"2.0","id":"0","method":"get_info","params":{}}', $t->calls[0]->body);
        $c->call('/get_transaction_pool_hashes');
        $this->assertSame('{}', $t->calls[1]->body);
        $this->assertSame('/get_transaction_pool_hashes', $t->calls[1]->path);
        $c->call('get_block_headers_range', ['start_height' => 89298, 'end_height' => 89302]);
        $this->assertSame('{"jsonrpc":"2.0","id":"0","method":"get_block_headers_range","params":{"start_height":89298,"end_height":89302}}', $t->calls[2]->body);
    }

    // --- decoding ---

    public function testUint64AboveIntMaxStaysAnExactString(): void
    {
        $t = new FixtureTransport();
        $r = $this->client($t)->call('get_info');
        $this->assertSame('18446744073709551615', $r->data['free_space'], 'recorded get_info: free_space is uint64 max');
        $this->assertSame(910671, $r->data['height']);

        $t = new FixtureTransport();
        $t->script(self::A, [FixtureTransport::rpcOk(
            '"max":18446744073709551615,"over_int":9223372036854775808,"int_max":9223372036854775807,'
            . '"int_min":-9223372036854775808,"below_int_min":-9223372036854775809,"small":7,"text":"18446744073709551615"'
        )]);
        $d = $this->client($t)->call('get_info')->data;
        $this->assertSame('18446744073709551615', $d['max']);
        $this->assertSame('9223372036854775808', $d['over_int']);
        $this->assertSame(PHP_INT_MAX, $d['int_max']);
        $this->assertSame(PHP_INT_MIN, $d['int_min']);
        $this->assertSame('-9223372036854775809', $d['below_int_min']);
        $this->assertSame(7, $d['small']);
        $this->assertSame('18446744073709551615', $d['text']);
    }

    public function testErrorCategories(): void
    {
        $envelope = static fn (string $rest): TransportResponse => new TransportResponse(200, '{"id":"0","jsonrpc":"2.0"' . $rest . '}');
        $cases = [
            'connect' => [self::failure(ErrorCategory::Connect), ErrorCategory::Connect],
            'timeout' => [self::failure(ErrorCategory::Timeout), ErrorCategory::Timeout],
            'too large' => [self::failure(ErrorCategory::TooLarge), ErrorCategory::TooLarge],
            'http 500' => [new TransportResponse(500, ''), ErrorCategory::Http],
            'http 404' => [new TransportResponse(404, 'not found'), ErrorCategory::Http],
            'http 429' => [new TransportResponse(429, '{"id":"0","result":{"status":"OK"}}'), ErrorCategory::Http],
            'http 302' => [new TransportResponse(302, ''), ErrorCategory::Http],
            'empty body' => [new TransportResponse(200, ''), ErrorCategory::Decode],
            'html' => [new TransportResponse(200, '<html>oops</html>'), ErrorCategory::Decode],
            'cut off json' => [new TransportResponse(200, '{"id":"0","jsonrpc":"2.0","result":{"status":"OK"'), ErrorCategory::Decode],
            'top level list' => [new TransportResponse(200, '[1,2]'), ErrorCategory::Decode],
            'top level scalar' => [new TransportResponse(200, '"x"'), ErrorCategory::Decode],
            'id mismatch' => [new TransportResponse(200, '{"id":"1","jsonrpc":"2.0","result":{"status":"OK"}}'), ErrorCategory::Decode],
            'no id' => [new TransportResponse(200, '{"jsonrpc":"2.0","result":{"status":"OK"}}'), ErrorCategory::Decode],
            'result not an object' => [$envelope(',"result":[1,2]'), ErrorCategory::Decode],
            'result scalar' => [$envelope(',"result":"OK"'), ErrorCategory::Decode],
            'no result' => [$envelope(''), ErrorCategory::Decode],
            'rpc error' => [$envelope(',"error":{"code":-32601,"message":"Method not found"}'), ErrorCategory::RpcError],
            'rpc error with result' => [$envelope(',"error":{"code":-1,"message":"x"},"result":{"status":"OK"}'), ErrorCategory::RpcError],
            'no status' => [$envelope(',"result":{"height":1}'), ErrorCategory::Missing],
            'status busy' => [$envelope(',"result":{"status":"BUSY"}'), ErrorCategory::Status],
            'status failed' => [$envelope(',"result":{"status":"Failed"}'), ErrorCategory::Status],
            'status lower case' => [$envelope(',"result":{"status":"ok"}'), ErrorCategory::Status],
            'status boolean' => [$envelope(',"result":{"status":true}'), ErrorCategory::Status],
            'status null' => [$envelope(',"result":{"status":null}'), ErrorCategory::Status],
            'untrusted' => [FixtureTransport::rpcOk('"untrusted":true'), ErrorCategory::Untrusted],
            'untrusted as text' => [FixtureTransport::rpcOk('"untrusted":"false"'), ErrorCategory::Untrusted],
            'untrusted as number' => [FixtureTransport::rpcOk('"untrusted":1'), ErrorCategory::Untrusted],
        ];
        $private = ['http://', 'https://', '.invalid', 'lateo.dev', ':17767', 'curl', 'cURL', 'resolve', 'SSL', 'Method not found', 'BUSY', 'oops'];
        foreach ($cases as $name => [$answer, $category]) {
            $t = new FixtureTransport();
            $t->script(self::A, [$answer]);
            $c = $this->client($t, Profile::Web, $this->config([], ['own-a']));
            $e = $this->assertRpc($category, static fn () => $c->call('get_info'), 'own-a');
            foreach ($private as $text) {
                $this->assertStringNotContains($text, $e->getMessage(), $name);
            }
            $this->assertStringContains($category->value, $e->getMessage(), $name);
            $this->assertStringContains('own-a', $e->getMessage(), $name);
        }
    }

    public function testStatusIsCheckedOnPlainPathsToo(): void
    {
        $t = new FixtureTransport();
        $t->script(self::A, [new TransportResponse(200, '{"status":"BUSY","top_hash":""}')]);
        $c = $this->client($t, Profile::Web, $this->config([], ['own-a']));
        $this->assertRpc(ErrorCategory::Status, static fn () => $c->call('/get_transaction_pool_hashes'));
        $t->script(self::A, [new TransportResponse(200, '{"top_hash":""}')]);
        $this->assertRpc(ErrorCategory::Missing, static fn () => $c->call('/get_transaction_pool_hashes'));
        $t->script(self::A, [new TransportResponse(200, '{"status":"OK","untrusted":true}')]);
        $this->assertRpc(ErrorCategory::Untrusted, static fn () => $c->call('/get_transaction_pool_stats'));
    }

    public function testMissedTransactionsAreAnError(): void
    {
        $hash = str_repeat('ab', 32);
        $t = new FixtureTransport();
        $t->script(self::A, [new TransportResponse(200, '{"credits":0,"missed_tx":["' . $hash . '"],"status":"OK","top_hash":"","txs":[],"untrusted":false}')]);
        $c = $this->client($t, Profile::Web, $this->config([], ['own-a']));
        $e = $this->assertRpc(ErrorCategory::Missing, static fn () => $c->call('/get_transactions', ['txs_hashes' => [$hash], 'decode_as_json' => true]));
        $this->assertStringNotContains($hash, $e->getMessage());

        $t->script(self::A, [new TransportResponse(200, '{"status":"OK","missed_tx":[],"txs":[],"untrusted":false}')]);
        $r = $c->call('/get_transactions', ['txs_hashes' => [$hash]]);
        $this->assertSame([], $r->data['txs']);
    }

    // --- node order and failover ---

    public function testNodesAreTriedInOrderAndTheResultNamesTheNode(): void
    {
        $t = new FixtureTransport();
        $r = $this->client($t)->call('get_info');
        $this->assertSame('own-a', $r->node);
        $this->assertSame([self::A], $t->hosts());
        $this->assertSame('/json_rpc', $t->calls[0]->path);
    }

    public function testWebFailsOverToTheNextNodeOnceEach(): void
    {
        $t = new FixtureTransport();
        $t->script(self::A, [self::failure(ErrorCategory::Connect)]);
        $r = $this->client($t)->call('get_info');
        $this->assertSame('own-b', $r->node);
        $this->assertSame([self::A, self::B], $t->hosts(), 'no retry on the same node');

        $t = new FixtureTransport();
        $t->script(self::A, [self::failure(ErrorCategory::Timeout)]);
        $t->script(self::B, [new TransportResponse(502, '')]);
        $r = $this->client($t)->call('get_info');
        $this->assertSame('fallback', $r->node);
        $this->assertSame([self::A, self::B, self::F], $t->hosts());
    }

    public function testWhenEveryNodeFailsTheLastErrorIsThrown(): void
    {
        $t = new FixtureTransport();
        $t->script(self::A, [self::failure(ErrorCategory::Connect)]);
        $t->script(self::B, [self::failure(ErrorCategory::Timeout)]);
        $t->script(self::F, [new TransportResponse(503, '')]);
        $c = $this->client($t);
        $this->assertRpc(ErrorCategory::Http, static fn () => $c->call('get_info'), 'fallback');
        $this->assertSame([self::A, self::B, self::F], $t->hosts());
        $this->assertSame(3, $c->attempts());
    }

    public function testADecodeOrStatusProblemAlsoFailsOverToTheNextNode(): void
    {
        $t = new FixtureTransport();
        $t->script(self::A, [new TransportResponse(200, '{"id":"0","jsonrpc":"2.0","result":{"status":"BUSY"}}')]);
        $this->assertSame('own-b', $this->client($t)->call('get_info')->node);
    }

    // --- the indexer profile ---

    public function testIndexerRetriesTheSameNodeForTransportAndDecodeErrors(): void
    {
        $t = new FixtureTransport();
        $t->script(self::A, [self::failure(ErrorCategory::Connect), self::failure(ErrorCategory::Timeout), FixtureTransport::rpcOk('"x":1')]);
        $r = $this->client($t, Profile::Indexer)->call('get_info');
        $this->assertSame('own-a', $r->node);
        $this->assertSame([self::A, self::A, self::A], $t->hosts());
        $this->assertSame([1.0, 1.0], $this->clock->sleeps, 'a pause before each retry');

        $t = new FixtureTransport();
        $t->script(self::A, [new TransportResponse(200, 'garbage'), FixtureTransport::rpcOk()]);
        $this->assertSame('own-a', $this->client($t, Profile::Indexer)->call('get_info')->node);
        $this->assertSame([self::A, self::A], $t->hosts());
    }

    public function testIndexerGivesUpOnANodeAfterTwoRetriesAndMovesOn(): void
    {
        $t = new FixtureTransport();
        $t->script(self::A, [self::failure(ErrorCategory::Connect), self::failure(ErrorCategory::Connect), self::failure(ErrorCategory::Connect), FixtureTransport::rpcOk()]);
        $r = $this->client($t, Profile::Indexer)->call('get_info');
        $this->assertSame('own-b', $r->node);
        $this->assertSame([self::A, self::A, self::A, self::B], $t->hosts(), 'one try and two retries on a, then b');
    }

    public function testIndexerDoesNotRetryHttpStatusRpcOrSizeErrors(): void
    {
        $answers = [
            new TransportResponse(500, ''),
            new TransportResponse(200, '{"id":"0","jsonrpc":"2.0","result":{"status":"BUSY"}}'),
            new TransportResponse(200, '{"id":"0","jsonrpc":"2.0","error":{"code":-1,"message":"x"}}'),
            self::failure(ErrorCategory::TooLarge),
        ];
        foreach ($answers as $answer) {
            $t = new FixtureTransport();
            $t->script(self::A, [$answer]);
            $r = $this->client($t, Profile::Indexer)->call('get_info');
            $this->assertSame('own-b', $r->node);
            $this->assertSame([self::A, self::B], $t->hosts());
        }
    }

    public function testIndexerUsesTheFallbackOnlyWhenAllowed(): void
    {
        $failing = static fn (): array => [
            self::failure(ErrorCategory::Connect), self::failure(ErrorCategory::Connect), self::failure(ErrorCategory::Connect),
        ];
        $t = new FixtureTransport();
        $t->script(self::A, $failing());
        $t->script(self::B, $failing());
        $c = $this->client($t, Profile::Indexer);
        $this->assertRpc(ErrorCategory::Connect, static fn () => $c->call('get_info'), 'own-b');
        $this->assertSame([self::A, self::A, self::A, self::B, self::B, self::B], $t->hosts(), 'the fallback is not asked');

        $t->script(self::A, $failing());
        $t->script(self::B, $failing());
        $this->assertRpc(ErrorCategory::Connect, static fn () => $c->call('get_info', [], false), 'own-b');
        $this->assertCount(12, $t->calls);
        $this->assertFalse(in_array(self::F, $t->hosts(), true));

        $t = new FixtureTransport();
        $t->script(self::A, $failing());
        $t->script(self::B, $failing());
        $c = $this->client($t, Profile::Indexer);
        $r = $c->call('get_info', [], true);
        $this->assertSame('fallback', $r->node);
        $this->assertSame(self::F, $t->hosts()[6]);
    }

    public function testWebUsesTheFallbackUnlessTheCallerSaysNo(): void
    {
        $t = new FixtureTransport();
        $t->script(self::A, [self::failure(ErrorCategory::Connect)]);
        $t->script(self::B, [self::failure(ErrorCategory::Connect)]);
        $c = $this->client($t);
        $this->assertRpc(ErrorCategory::Connect, static fn () => $c->call('get_info', [], false), 'own-b');
        $this->assertSame([self::A, self::B], $t->hosts());
    }

    // --- budgets ---

    public function testThePageBudgetCapsAttemptsOverAllNodes(): void
    {
        $t = new FixtureTransport();
        $c = $this->client($t, Profile::Web, $this->config(['page_max_calls' => 4]));
        for ($i = 0; $i < 4; $i++) {
            $c->call('get_info');
        }
        $this->assertRpc(ErrorCategory::Budget, static fn () => $c->call('get_info'));
        $this->assertCount(4, $t->calls, 'the fifth call does no I/O');
        $this->assertSame(4, $c->attempts());
    }

    public function testFailedAttemptsCountAgainstThePageBudget(): void
    {
        $t = new FixtureTransport();
        $t->script(self::A, [self::failure(ErrorCategory::Connect)]);
        $t->script(self::B, [self::failure(ErrorCategory::Connect)]);
        $c = $this->client($t, Profile::Web, $this->config(['page_max_calls' => 2]));
        // a and b fail (2 attempts); the fallback would be the third
        $this->assertRpc(ErrorCategory::Budget, static fn () => $c->call('get_info'));
        $this->assertSame([self::A, self::B], $t->hosts());
        $this->assertRpc(ErrorCategory::Budget, static fn () => $c->call('get_info'));
        $this->assertCount(2, $t->calls);
    }

    public function testTheBudgetIsPerClient(): void
    {
        $t = new FixtureTransport();
        $config = $this->config(['page_max_calls' => 1]);
        $one = $this->client($t, Profile::Web, $config);
        $two = $this->client($t, Profile::Web, $config);
        $one->call('get_info');
        $two->call('get_info');
        $this->assertRpc(ErrorCategory::Budget, static fn () => $one->call('get_info'));
        $this->assertRpc(ErrorCategory::Budget, static fn () => $two->call('get_info'));
    }

    public function testTheIndexerHasNoPageBudget(): void
    {
        $t = new FixtureTransport();
        $c = $this->client($t, Profile::Indexer, $this->config(['page_max_calls' => 1]));
        for ($i = 0; $i < 10; $i++) {
            $c->call('get_info');
        }
        $this->assertCount(10, $t->calls);
    }

    public function testTheFallbackCapPerMinuteIsSharedBetweenClientInstances(): void
    {
        $dir = $this->scratch() . '/shared';
        $config = $this->config(['page_max_calls' => 20, 'fallback_calls_per_minute' => 3]);
        $t1 = new FixtureTransport();
        $t2 = new FixtureTransport();
        $c1 = $this->client($t1, Profile::Web, $config, CallLimiter::fallback($dir, 3, $this->clock));
        $c2 = $this->client($t2, Profile::Web, $config, CallLimiter::fallback($dir, 3, $this->clock));

        $this->assertSame('fallback', $c1->call('get_info', [], null, 'fallback')->node);
        $this->assertSame('fallback', $c2->call('get_info', [], null, 'fallback')->node);
        $this->assertSame('fallback', $c1->call('get_info', [], null, 'fallback')->node);
        $this->assertRpc(ErrorCategory::Budget, static fn () => $c2->call('get_info', [], null, 'fallback'), 'fallback');
        $this->assertRpc(ErrorCategory::Budget, static fn () => $c1->call('get_info', [], null, 'fallback'), 'fallback');
        $this->assertCount(2, $t1->calls);
        $this->assertCount(1, $t2->calls, 'a refused call does no I/O');

        // own nodes are not limited by it
        $this->assertSame('own-a', $c1->call('get_info')->node);

        $this->clock->advance(30);
        $this->assertRpc(ErrorCategory::Budget, static fn () => $c1->call('get_info', [], null, 'fallback'));
        $this->clock->advance(31); // the first calls are now more than a minute old
        $this->assertSame('fallback', $c2->call('get_info', [], null, 'fallback')->node);
    }

    public function testTheFallbackCapAlsoStopsTheFailoverChain(): void
    {
        $dir = $this->scratch() . '/shared';
        $config = $this->config(['page_max_calls' => 20, 'fallback_calls_per_minute' => 1]);
        $t = new FixtureTransport();
        $c = $this->client($t, Profile::Web, $config, CallLimiter::fallback($dir, 1, $this->clock));
        $t->script(self::A, [self::failure(ErrorCategory::Connect), self::failure(ErrorCategory::Connect)]);
        $t->script(self::B, [self::failure(ErrorCategory::Connect), self::failure(ErrorCategory::Connect)]);
        $this->assertSame('fallback', $c->call('get_info')->node);
        $this->assertRpc(ErrorCategory::Budget, static fn () => $c->call('get_info'), 'fallback');
        $this->assertSame([self::A, self::B, self::F, self::A, self::B], $t->hosts());
    }

    public function testTheOwnNodeCapPerMinuteIsSharedBetweenClientsAndStopsTheFailoverChain(): void
    {
        $dir = $this->scratch() . '/shared';
        $config = $this->config(['page_max_calls' => 20, 'web_own_calls_per_minute' => 3]);
        $t1 = new FixtureTransport();
        $t2 = new FixtureTransport();
        $c1 = $this->client($t1, Profile::Web, $config, null, CallLimiter::ownNodes($dir, 3, $this->clock));
        $c2 = $this->client($t2, Profile::Web, $config, null, CallLimiter::ownNodes($dir, 3, $this->clock));

        $this->assertSame('own-a', $c1->call('get_info')->node);
        $this->assertSame('own-a', $c2->call('get_info')->node);
        $this->assertSame('own-b', $c1->call('get_info', [], null, 'own-b')->node);
        $e = $this->assertRpc(ErrorCategory::Budget, static fn () => $c2->call('get_info'));
        $this->assertStringNotContains('example.invalid', $e->getMessage());
        $this->assertStringContains('own-node calls per minute', $e->getMessage());
        $this->assertCount(2, $t1->calls);
        $this->assertCount(1, $t2->calls, 'a refused call does no I/O and tries no other node (nor the fallback)');
        $this->assertTrue(file_exists($dir . '/own-calls.json'));
        $this->assertFalse(file_exists($dir . '/fallback-calls.json'), 'the fallback count is a file of its own');

        $this->clock->advance(61);
        $this->assertSame('own-a', $c2->call('get_info')->node);
    }

    public function testTheOwnNodeCapDoesNotCountTheFallbackAndTheFallbackCapDoesNotCountOwnNodes(): void
    {
        $dir = $this->scratch() . '/shared';
        $config = $this->config(['page_max_calls' => 20]);
        $t = new FixtureTransport();
        $c = $this->client(
            $t,
            Profile::Web,
            $config,
            CallLimiter::fallback($dir, 1, $this->clock),
            CallLimiter::ownNodes($dir, 1, $this->clock),
        );
        $this->assertSame('own-a', $c->call('get_info')->node);
        $this->assertSame('fallback', $c->call('get_info', [], null, 'fallback')->node);
        $this->assertRpc(ErrorCategory::Budget, static fn () => $c->call('get_info'), 'own-a');
        $this->assertRpc(ErrorCategory::Budget, static fn () => $c->call('get_info', [], null, 'fallback'), 'fallback');
    }

    public function testTheIndexerProfileIgnoresTheOwnNodeCap(): void
    {
        $dir = $this->scratch() . '/shared';
        $config = $this->config();
        $t = new FixtureTransport();
        $c = $this->client($t, Profile::Indexer, $config, null, CallLimiter::ownNodes($dir, 1, $this->clock));
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame('own-a', $c->call('get_info')->node);
        }
        $this->assertCount(5, $t->calls);
        $this->assertFalse(file_exists($dir . '/own-calls.json'), 'the indexer never touches the web cap');
    }

    public function testTheLimiterRefusesWhenItsFileCannotBeUsed(): void
    {
        $dir = $this->scratch() . '/blocked';
        $limiter = CallLimiter::fallback($dir . '/sub', 5, $this->clock);
        file_put_contents($dir, 'a file where the directory should be');
        $t = new FixtureTransport();
        $c = $this->client($t, Profile::Web, $this->config(['page_max_calls' => 20]), $limiter);
        $this->assertRpc(ErrorCategory::Budget, static fn () => $c->call('get_info', [], null, 'fallback'));
        $this->assertCount(0, $t->calls);
    }

    // --- named nodes ---

    public function testACallToOneNamedNodeUsesOnlyThatNode(): void
    {
        $t = new FixtureTransport();
        $c = $this->client($t);
        $r = $c->call('get_info', [], null, 'own-b');
        $this->assertSame('own-b', $r->node);
        $this->assertSame([self::B], $t->hosts());

        $t->script(self::B, [self::failure(ErrorCategory::Connect)]);
        $this->assertRpc(ErrorCategory::Connect, static fn () => $c->call('get_info', [], null, 'own-b'), 'own-b');
        $this->assertSame([self::B, self::B], $t->hosts(), 'no failover for a named node');
    }

    public function testNamedNodesGoThroughTheSameAllowListAndBudget(): void
    {
        $t = new FixtureTransport();
        $c = $this->client($t, Profile::Web, $this->config(['page_max_calls' => 1]));
        $this->assertRpc(ErrorCategory::RefusedMethod, static fn () => $c->call('get_connections', [], null, 'own-a'));
        $this->assertCount(0, $t->calls);
        $c->call('get_info', [], null, 'own-a');
        $this->assertRpc(ErrorCategory::Budget, static fn () => $c->call('get_info', [], null, 'own-b'));
        $this->assertCount(1, $t->calls);
    }

    public function testUnknownLabelAndFallbackNotAllowed(): void
    {
        $t = new FixtureTransport();
        $c = $this->client($t, Profile::Indexer);
        $this->assertRpc(ErrorCategory::Missing, static fn () => $c->call('get_info', [], null, 'nope'));
        $this->assertRpc(ErrorCategory::RefusedMethod, static fn () => $c->call('get_info', [], null, 'fallback'), 'fallback');
        $this->assertCount(0, $t->calls);
        $this->assertSame('fallback', $c->call('get_info', [], true, 'fallback')->node);
    }

    public function testParametersThatCannotBeEncodedAreRefused(): void
    {
        $t = new FixtureTransport();
        $c = $this->client($t);
        $this->assertRpc(ErrorCategory::RefusedMethod, static fn () => $c->call('get_block', ['hash' => "\xff\xfe"]));
        $this->assertCount(0, $t->calls);
    }
}
