<?php

declare(strict_types=1);

namespace Lateo\Explorer\Web;

use Lateo\Explorer\Cache\FileCache;
use Lateo\Explorer\Config\Config;
use Lateo\Explorer\Db\Database;
use Lateo\Explorer\Rpc\Transport;
use Lateo\Explorer\Support\Clock;
use Lateo\Explorer\Web\Page\AboutPage;
use Lateo\Explorer\Web\Page\BlockPage;
use Lateo\Explorer\Web\Page\ErrorPage;
use Lateo\Explorer\Web\Page\HomePage;
use Lateo\Explorer\Web\Page\SearchPage;
use Lateo\Explorer\Web\Page\StatusPage;

/**
 * The router: turns a Request into a Response. It sends nothing itself (public/index.php
 * does), so a test renders every page without a web server.
 *
 * Only GET and HEAD are answered. A page always renders: a database that cannot be opened is
 * a 503 page, and a node that cannot be reached is a sentence on the page.
 */
final class Application
{
    private readonly View $view;
    private readonly Layout $layout;

    /**
     * @param \Closure():Database $openDatabase opens a read-only connection to the index
     * @param string $root the repository root (style/, views/ and public/assets/ are read from it)
     * @param string|null $cacheDir only for tests; defaults to the settings' cache_dir
     */
    public function __construct(
        private readonly Config $config,
        private readonly Transport $transport,
        private readonly Clock $clock,
        private readonly \Closure $openDatabase,
        string $root,
        private readonly ?string $cacheDir = null,
    ) {
        $this->view = new View(rtrim($root, '/') . '/views');
        $this->layout = new Layout($root);
    }

    public function handle(Request $request): Response
    {
        $services = $this->services();
        $path = $request->path;

        if ($request->method !== 'GET' && $request->method !== 'HEAD') {
            return ErrorPage::methodNotAllowed($services, '');
        }
        try {
            $response = $this->route($services, $request);
        } catch (IndexUnavailable) {
            $response = ErrorPage::unavailable($services, $path);
        }
        return $request->method === 'HEAD' ? $response->withoutBody() : $response;
    }

    /**
     * Like handle(), and a failure of the code itself becomes a plain 500 page without a
     * message. $report gets the error (the front controller logs its class and nothing else).
     *
     * @param (\Closure(\Throwable):void)|null $report
     */
    public function handleSafely(Request $request, ?\Closure $report = null): Response
    {
        try {
            return $this->handle($request);
        } catch (\Throwable $e) {
            if ($report !== null) {
                $report($e);
            }
            $r = ErrorPage::serverError($this->services(), '');
            return $request->method === 'HEAD' ? $r->withoutBody() : $r;
        }
    }

    private function route(Services $s, Request $request): Response
    {
        $path = $request->path;
        if ($path === '/') {
            return (new HomePage())->respond($s);
        }
        if ($path === '/status') {
            return (new StatusPage())->respond($s);
        }
        if ($path === '/about') {
            return (new AboutPage())->respond($s);
        }
        if ($path === '/search') {
            return (new SearchPage())->respond($s, $request);
        }
        if (preg_match('#\A/block/((?:0|[1-9][0-9]{0,9})|[0-9a-f]{64})\z#', $path, $m) === 1) {
            return (new BlockPage())->respond($s, $m[1]);
        }
        return ErrorPage::notFound($s, $path);
    }

    /** The objects of one request: its own RPC client (and so its own budget) among them. */
    private function services(): Services
    {
        $cache = null;
        $cacheDir = $this->cacheDir ?? $this->config->cacheDir;
        try {
            $cache = new FileCache($cacheDir, $this->clock);
        } catch (\RuntimeException) {
            // Without a cache nothing is read live: the pages say "temporarily unavailable".
        }
        $factory = new LiveClientFactory($this->config, $this->transport, $this->clock, $cacheDir);
        return new Services(
            $this->config,
            $this->clock,
            $this->view,
            $this->layout,
            $this->openDatabase,
            fn (): LiveData => new LiveData($this->config, $factory, $cache),
        );
    }
}
