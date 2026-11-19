<?php

declare(strict_types=1);

namespace Lateo\Explorer\Tests;

use Lateo\Explorer\Rpc\BodyCollector;
use Lateo\Explorer\Rpc\CurlTransport;
use Lateo\Explorer\Rpc\ErrorCategory;
use Lateo\Explorer\Rpc\RequestLimits;
use Lateo\Explorer\Rpc\Profile;
use Lateo\Explorer\Rpc\RpcException;
use Lateo\Explorer\Rpc\TransportFailure;
use Lateo\Explorer\Tests\Support\FixtureTransport;
use Lateo\Explorer\Tests\Support\TestCase;

final class TransportTest extends TestCase
{
    public function testTheUserAgentIsAConstant(): void
    {
        $this->assertSame('lateo-explorer (+https://explorer.lateo.dev)', CurlTransport::USER_AGENT);
    }

    public function testCurlErrorsAreMappedToCategories(): void
    {
        $this->assertSame(ErrorCategory::Timeout, CurlTransport::categoryFor(28));
        foreach ([1, 3, 5, 6, 7, 18, 23, 35, 51, 52, 56, 58, 60, 77] as $errno) {
            $this->assertSame(ErrorCategory::Connect, CurlTransport::categoryFor($errno), 'curl error ' . $errno);
        }
    }

    public function testTheBodyCollectorEnforcesTheCapInTheWriteCallback(): void
    {
        $c = new BodyCollector(10);
        $this->assertSame(4, $c->accept('abcd'));
        $this->assertSame(6, $c->accept('efghij'));
        $this->assertFalse($c->overflowed(), 'exactly at the cap is allowed');
        $this->assertSame(0, $c->accept('k'), '0 aborts the transfer');
        $this->assertTrue($c->overflowed());
        $this->assertSame('abcdefghij', $c->body());

        $big = new BodyCollector(5);
        $this->assertSame(0, $big->accept('abcdef'), 'one chunk over the cap');
        $this->assertSame('', $big->body());
    }

    public function testTransportFailureCarriesACategoryOnly(): void
    {
        $f = new TransportFailure(ErrorCategory::Timeout);
        $this->assertSame(ErrorCategory::Timeout, $f->category);
        $this->assertSame('transport failure: timeout', $f->getMessage());
    }

    public function testProfiles(): void
    {
        $this->assertSame([1.0, 3.0, 0], [Profile::Web->connectTimeout(), Profile::Web->totalTimeout(), Profile::Web->sameNodeRetries()]);
        $this->assertSame([2.0, 20.0, 2], [Profile::Indexer->connectTimeout(), Profile::Indexer->totalTimeout(), Profile::Indexer->sameNodeRetries()]);
        $this->assertTrue(Profile::Web->fallbackByDefault());
        $this->assertFalse(Profile::Indexer->fallbackByDefault());
        $this->assertTrue(Profile::Web->hasCallBudget());
        $this->assertFalse(Profile::Indexer->hasCallBudget());
        $this->assertTrue(Profile::Indexer->maxResponseBytes() > 2 * 1024 * 1024, 'room for 1000 headers');
    }

    public function testRetryableCategories(): void
    {
        $retry = array_filter(ErrorCategory::cases(), static fn (ErrorCategory $c): bool => $c->isRetryable());
        $names = array_map(static fn (ErrorCategory $c): string => $c->value, array_values($retry));
        sort($names);
        $this->assertSame(['connect', 'decode', 'timeout'], $names);
        $all = array_map(static fn (ErrorCategory $c): string => $c->value, ErrorCategory::cases());
        sort($all);
        $this->assertSame(['budget', 'connect', 'decode', 'http', 'missing', 'refused-method', 'rpc-error', 'status', 'timeout', 'too-large', 'untrusted'], $all);
    }

    public function testRpcExceptionMessageHoldsOnlyFixedWords(): void
    {
        $e = new RpcException(ErrorCategory::Http, 'own-a');
        $this->assertSame('rpc http (node own-a)', $e->getMessage());
        $e = new RpcException(ErrorCategory::RefusedMethod, null, 'not on the allow-list');
        $this->assertSame('rpc refused-method: not on the allow-list', $e->getMessage());
    }

    public function testFixtureNames(): void
    {
        $this->assertSame('get_info', FixtureTransport::fixtureName('get_info', []));
        $this->assertSame(
            'get_block_headers_range__end_height-89302__start_height-89298',
            FixtureTransport::fixtureName('get_block_headers_range', ['start_height' => 89298, 'end_height' => 89302]),
        );
        $this->assertSame(
            'get_transactions__decode_as_json-true__txs_hashes-64b3f44e2521',
            FixtureTransport::fixtureName('/get_transactions', ['txs_hashes' => ['64b3f44e2521b677a24eaaa6bcc3b795e0637f92e8b2b1b12e13982aca95d8c8'], 'decode_as_json' => true]),
        );
    }
}
