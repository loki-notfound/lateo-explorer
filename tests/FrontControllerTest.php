<?php

declare(strict_types=1);

namespace Lateo\Explorer\Tests;

use Lateo\Explorer\Tests\Support\TestCase;

/**
 * public/index.php run as a script, with the constants a deploy stub defines. The settings file
 * is written to the scratch directory, the database does not exist (so pages that need it answer
 * 503) and no node is contacted.
 *
 * The test runner has written to standard output already, so PHP refuses header() and
 * http_response_code() here (a warning, which these runs ignore). The status and the headers of
 * a response are tested on the Response objects in WebPagesTest and PolicyTest; this class
 * tests that the front controller builds the application from the stub's constants and sends
 * the body, and only for GET.
 */
final class FrontControllerTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $savedServer = [];
    /** @var array<string,mixed> */
    private array $savedGet = [];

    public function setUp(): void
    {
        $this->savedServer = $_SERVER;
        $this->savedGet = $_GET;
    }

    public function tearDown(): void
    {
        $_SERVER = $this->savedServer;
        $_GET = $this->savedGet;
        parent::tearDown();
    }

    /** @return string the output of one run of the front controller */
    private function run(string $method, string $uri): string
    {
        // The constants are defined once per process, so the paths must not change between runs.
        $dir = rtrim(self::$scratchBase, '/');
        $settings = $dir . '/front-controller-settings.php';
        file_put_contents($settings, "<?php\nreturn " . var_export([
            'nodes' => [
                ['label' => 'own-a', 'kind' => 'own', 'url' => 'http://node-a.example.invalid:17767'],
                ['label' => 'fallback', 'kind' => 'fallback', 'url' => 'https://node.lateo.dev'],
            ],
            'database' => '/nonexistent/explorer.sqlite',
            'cache_dir' => '/nonexistent/cache',
            'lock_file' => '/nonexistent/indexer.lock',
        ], true) . ";\n");
        if (!defined('LATEO_EXPLORER_ROOT')) {
            define('LATEO_EXPLORER_ROOT', __DIR__ . '/..');
        }
        if (!defined('LATEO_EXPLORER_SETTINGS')) {
            define('LATEO_EXPLORER_SETTINGS', $settings);
        }
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $uri;
        $_GET = [];
        $previous = set_error_handler(static fn (int $severity, string $message): bool => str_contains($message, 'headers already sent'));
        ob_start();
        try {
            require __DIR__ . '/../public/index.php';
        } finally {
            $out = (string) ob_get_clean();
            restore_error_handler();
            unset($previous);
        }
        return $out;
    }

    public function testThePageIsBuiltFromTheStubsConstantsAndSent(): void
    {
        $out = $this->run('GET', '/about');
        $this->assertStringContains('<h1>About this explorer</h1>', $out);
    }

    public function testAnUnknownPathGivesTheNotFoundPage(): void
    {
        $out = $this->run('GET', '/nope?x=1');
        $this->assertStringContains('Page not found', $out);
    }

    public function testAPageThatNeedsAMissingDatabaseGivesTheUnavailablePage(): void
    {
        $out = $this->run('GET', '/status');
        $this->assertStringContains('Index not available', $out);
        $this->assertStringNotContains('/nonexistent', $out, 'no path in a page');
    }

    public function testPostGivesTheMethodNotAllowedPage(): void
    {
        $out = $this->run('POST', '/');
        $this->assertStringContains('Method not allowed', $out);
    }

    public function testHeadSendsNoBody(): void
    {
        $out = $this->run('HEAD', '/about');
        $this->assertSame('', $out);
    }
}
