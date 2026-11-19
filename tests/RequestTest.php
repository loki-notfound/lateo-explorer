<?php

declare(strict_types=1);

namespace Lateo\Explorer\Tests;

use Lateo\Explorer\Tests\Support\TestCase;
use Lateo\Explorer\Web\Request;

/** The request object reads the method, the path and q, and nothing else. */
final class RequestTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $savedGet = [];

    public function setUp(): void
    {
        $this->savedGet = $_GET;
    }

    public function tearDown(): void
    {
        $_GET = $this->savedGet;
        parent::tearDown();
    }

    public function testThePathIsCutFromTheUriAndQComesFromTheQuery(): void
    {
        $_GET = ['q' => '910657', 'page' => '2', 'debug' => '1'];
        $r = Request::fromGlobals(['REQUEST_METHOD' => 'get', 'REQUEST_URI' => '/search?q=910657&page=2&debug=1']);
        $this->assertSame('GET', $r->method);
        $this->assertSame('/search', $r->path);
        $this->assertSame('910657', $r->query);
    }

    public function testAQueryThatIsNotAPlainStringIsIgnored(): void
    {
        $_GET = ['q' => ['a', 'b']];
        $this->assertNull(Request::fromGlobals(['REQUEST_URI' => '/search?q[]=a&q[]=b'])->query);
        $_GET = [];
        $this->assertNull(Request::fromGlobals(['REQUEST_URI' => '/search'])->query);
    }

    public function testMissingOrOddServerValuesFallBackToTheHomePage(): void
    {
        $_GET = [];
        $r = Request::fromGlobals([]);
        $this->assertSame('GET', $r->method);
        $this->assertSame('/', $r->path);
        $this->assertSame('/', Request::fromGlobals(['REQUEST_URI' => '?q=1'])->path);
        $this->assertSame('/', Request::fromGlobals(['REQUEST_URI' => 5, 'REQUEST_METHOD' => ['x']])->path);
    }

    public function testThePathKeepsEncodingsAsSent(): void
    {
        $_GET = [];
        $this->assertSame('/block/%39', Request::fromGlobals(['REQUEST_URI' => '/block/%39'])->path, 'no decoding: such a path is not a block page');
    }
}
