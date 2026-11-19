<?php

declare(strict_types=1);

namespace Lateo\Explorer\Tests;

use Lateo\Explorer\Cache\FileCache;
use Lateo\Explorer\Rpc\ErrorCategory;
use Lateo\Explorer\Rpc\FallbackLimiter;
use Lateo\Explorer\Rpc\Profile;
use Lateo\Explorer\Rpc\RpcClient;
use Lateo\Explorer\Rpc\TransportFailure;
use Lateo\Explorer\Rpc\TransportResponse;
use Lateo\Explorer\Tests\Support\RecordedCall;
use Lateo\Explorer\Tests\Support\WebTestBase;

/** What a page may ask of the nodes: the budget, the skip marker and the shared cache. */
final class LiveDataTest extends WebTestBase
{
    /** Every page of the site that can reach a node. */
    private const ROUTES = ['/', '/status', '/block/910657', '/block/910640', '/block/89300', '/about', '/search', '/nope'];

    public function testNoPageExceedsItsCallBudgetWhenEveryNodeFails(): void
    {
        $this->loadStandardIndex();
        $this->down(self::A, self::B, self::F);
        foreach (self::ROUTES as $path) {
            $this->transport->calls = [];
            $this->clock->advance(100); // failure markers and cache entries expire between pages
            $r = $this->get($path, '910657');
            $this->assertTrue($r->status < 500, $path);
            $this->assertTrue(count($this->transport->calls) <= 4, $path . ' made ' . count($this->transport->calls) . ' calls');
        }
    }

    public function testCallsPerPageWhenEveryNodeAnswers(): void
    {
        $this->loadStandardIndex();
        $limits = ['/' => 1, '/block/910657' => 1, '/block/910640' => 0, '/about' => 0, '/search' => 0, '/nope' => 0, '/status' => 3];
        foreach ($limits as $path => $max) {
            $this->transport->calls = [];
            $this->clock->advance(100);
            $this->get($path, '910657');
            $this->assertSame($max, count($this->transport->calls), $path);
        }
    }

    public function testTheBudgetOfTheClientEndsTheAttemptsOfOnePage(): void
    {
        $this->loadStandardIndex();
        $this->down(self::A, self::B, self::F);
        $r = $this->get('/', null, 'GET', $this->app(['page_max_calls' => 1]));
        $this->assertSame(1, count($this->transport->calls), 'the budget of one stops the second node');
        $this->assertSame(200, $r->status);
        $this->assertStringContains('Node not reachable', $r->body);
    }

    public function testEveryPageRequestGetsAFreshBudget(): void
    {
        $this->loadStandardIndex();
        $app = $this->app(['page_max_calls' => 1]);
        $first = $this->get('/', null, 'GET', $app);
        $this->clock->advance(11);
        $second = $this->get('/', null, 'GET', $app);
        $this->assertSame(2, count($this->transport->calls));
        $this->assertStringNotContains('Node not reachable', $first->body);
        $this->assertStringNotContains('Node not reachable', $second->body, 'the budget of the first request is not spent in the second');
    }

    public function testAFailedConnectionLeavesAMarkerForThirtySeconds(): void
    {
        $this->loadStandardIndex();
        $this->down(self::A);
        $this->get('/');
        $this->assertSame([self::A, self::B], $this->transport->hosts(), 'the first own node fails, the second answers');

        $this->clock->advance(11); // the tip expired, the marker has not
        $this->get('/');
        $this->assertSame([self::A, self::B, self::B], $this->transport->hosts(), 'the marked node is not asked');

        $this->clock->advance(20); // 31 s since the failure
        $this->get('/');
        $this->assertSame([self::A, self::B, self::B, self::A, self::B], $this->transport->hosts(), 'asked again after the marker ran out');
    }

    public function testATimeoutAlsoLeavesAMarker(): void
    {
        $this->loadStandardIndex();
        $this->transport->serve(self::A, static function (RecordedCall $c): TransportResponse {
            throw new TransportFailure(ErrorCategory::Timeout);
        });
        $this->get('/');
        $this->clock->advance(11);
        $this->get('/');
        $this->assertSame(1, count(array_filter($this->transport->hosts(), static fn (string $h): bool => $h === self::A)));
    }

    public function testAnHttpErrorLeavesNoMarker(): void
    {
        $this->loadStandardIndex();
        $this->transport->serve(self::A, static fn (RecordedCall $c): TransportResponse => new TransportResponse(500, ''));
        $this->get('/');
        $this->clock->advance(11);
        $this->get('/');
        $this->assertSame(2, count(array_filter($this->transport->hosts(), static fn (string $h): bool => $h === self::A)), 'a node that answers badly is asked again');
    }

    public function testTheIndexerIgnoresTheMarker(): void
    {
        $cache = new FileCache($this->cacheDir, $this->clock);
        $cache->set('skip:own-a', 1, 30);
        $client = new RpcClient(
            $this->config(),
            $this->transport,
            Profile::Indexer,
            new FallbackLimiter($this->cacheDir, 30, $this->clock),
            $this->clock,
        );
        $result = $client->call('get_last_block_header', [], false, 'own-a');
        $this->assertSame('own-a', $result->node);
        $this->assertSame([self::A], $this->transport->hosts(), 'the indexer asks a marked node');
    }

    public function testTheNodesAreAskedByLabelInTheUsualOrderAndTheLabelsStayInside(): void
    {
        $this->loadStandardIndex();
        $this->get('/status');
        $this->assertSame([self::A, self::B, self::F], $this->transport->hosts());
        $entry = (new FileCache($this->cacheDir, $this->clock))->get('status');
        $this->assertTrue($entry !== null);
        $json = json_encode($entry->value, JSON_THROW_ON_ERROR);
        $this->assertStringNotContains('own-a', $json, 'the cache holds names, not labels');
        $this->assertStringNotContains('example.invalid', $json);
        $this->assertStringContains('Own node 1', $json);
    }

    public function testTheFallbackIsUsedOnlyWhenTheOwnNodesDoNotAnswer(): void
    {
        $this->loadStandardIndex();
        $this->get('/');
        $this->assertSame([self::A], $this->transport->hosts());
        $this->transport->calls = [];
        $this->clock->advance(100);
        $this->down(self::A, self::B);
        $r = $this->get('/');
        $this->assertSame([self::A, self::B, self::F], $this->transport->hosts());
        $this->assertStringContains('910,670', $r->body);
    }
}
