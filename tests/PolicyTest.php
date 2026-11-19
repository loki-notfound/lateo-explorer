<?php

declare(strict_types=1);

namespace Lateo\Explorer\Tests;

use Lateo\Explorer\Rpc\RpcMethod;
use Lateo\Explorer\Tests\Support\WebTestBase;
use Lateo\Explorer\Web\Response;

/**
 * Policy checks over the source and over every rendered page: no cookies, no scripts, no inline
 * style, every printed value escaped, no URL beyond the site's own, the security headers on every
 * response, no query logged, and nothing but read-only calls to the nodes.
 */
final class PolicyTest extends WebTestBase
{
    /** Hosts a page may link to: the site, the lateo family bar of the style, the public repository. */
    private const ALLOWED_HOSTS = [
        'explorer.lateo.dev', 'lateo.dev', 'node.lateo.dev', 'wallet.lateo.dev', 'map.lateo.dev', 'stats.lateo.dev',
        'github.com',
    ];

    /** @return list<string> */
    private function files(string $dir, string $extension): array
    {
        $out = [];
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . '/' . $name;
            if (is_dir($path)) {
                array_push($out, ...$this->files($path, $extension));
            } elseif (str_ends_with($name, '.' . $extension)) {
                $out[] = $path;
            }
        }
        sort($out);
        return $out;
    }

    private function read(string $path): string
    {
        return (string) file_get_contents($path);
    }

    /** The code of this project that runs in a page request or in cron (not the style's copies, not tests). @return array<string,string> */
    private function ownCode(): array
    {
        $root = self::root();
        $out = [];
        foreach ([...$this->files($root . '/src', 'php'), ...$this->files($root . '/views', 'php'), ...$this->files($root . '/bin', 'php'), $root . '/public/index.php'] as $file) {
            $out[$file] = $this->read($file);
        }
        $this->assertTrue(count($out) > 40, 'the source was found');
        return $out;
    }

    /** @return array<string,Response> every kind of page, rendered with the standard index */
    private function corpus(): array
    {
        $this->loadStandardIndex();
        $orphan = hash('sha256', 'orphan');
        $this->db->exec('INSERT INTO orphaned_blocks (hash, height, detected_at) VALUES (?, ?, ?)', [$orphan, 910655, 1795047000]);
        $address = 'ZEPHs0000EXAMPLE0000PLACEHOLDER0000' . str_repeat('0', 63);
        $requests = [
            'home' => ['/', null], 'status' => ['/status', null], 'about' => ['/about', null],
            'block' => ['/block/910640', null], 'unsettled' => ['/block/910657', null],
            'before-records' => ['/block/89298', null], 'first-record' => ['/block/89300', null],
            'fork-11' => ['/block/536000', null], 'orphaned' => ['/block/' . $orphan, null],
            'not-indexed' => ['/block/910700', null], 'unknown' => ['/nope', null],
            'search-empty' => ['/search', null], 'search-script' => ['/search', '<script>alert(1)</script>'],
            'search-address' => ['/search', $address], 'search-tx' => ['/search', str_repeat('ab', 32)],
            'search-hit' => ['/search', '910657'],
        ];
        $out = [];
        foreach ($requests as $name => [$path, $q]) {
            $out[$name] = $this->get($path, $q);
            $this->clock->advance(1);
        }
        $out['post'] = $this->get('/', null, 'POST');
        $out['head'] = $this->get('/', null, 'HEAD');
        // The same pages when no node answers.
        $this->down(self::A, self::B, self::F);
        $this->clock->advance(100);
        foreach (['home' => '/', 'status' => '/status', 'unsettled' => '/block/910657'] as $name => $path) {
            $out[$name . '-down'] = $this->get($path);
        }
        return $out;
    }

    // --- every response -----------------------------------------------------------------------

    public function testEveryResponseCarriesTheSecurityHeadersAndNoCookie(): void
    {
        foreach ($this->corpus() as $name => $r) {
            $this->assertSame(Response::CONTENT_SECURITY_POLICY, $r->header('Content-Security-Policy'), $name);
            $this->assertSame('nosniff', $r->header('X-Content-Type-Options'), $name);
            $this->assertSame('no-referrer', $r->header('Referrer-Policy'), $name);
            $this->assertSame('DENY', $r->header('X-Frame-Options'), $name);
            $this->assertTrue($r->header('Cache-Control') !== null, $name . ' has a Cache-Control');
            $this->assertSame('text/html; charset=utf-8', $r->header('Content-Type'), $name);
            foreach (array_keys($r->headers) as $header) {
                $this->assertStringNotContains('cookie', strtolower($header), $name . ' sets no cookie');
            }
        }
    }

    public function testThePolicyIsTheHouseStylesWithFormActionSelf(): void
    {
        $helpers = $this->read(self::root() . '/style/helpers.php');
        $this->assertStringContains("\"Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self'; base-uri 'none'; form-action \"", $helpers);
        $this->assertStringContains("\"; frame-ancestors 'none'\"", $helpers);
        $this->assertSame(
            "default-src 'none'; style-src 'self'; img-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'",
            Response::CONTENT_SECURITY_POLICY,
        );
        $this->assertStringNotContains('script-src', Response::CONTENT_SECURITY_POLICY);
        $this->assertStringNotContains('connect-src', Response::CONTENT_SECURITY_POLICY);
    }

    public function testCacheControlFollowsTheKindOfPage(): void
    {
        $c = $this->corpus();
        $this->assertSame('public, max-age=5', $c['home']->header('Cache-Control'));
        $this->assertSame('public, max-age=5', $c['status']->header('Cache-Control'));
        $this->assertSame('public, max-age=3600', $c['block']->header('Cache-Control'), 'a settled block');
        $this->assertSame('public, max-age=15', $c['unsettled']->header('Cache-Control'), 'an unsettled block: short');
        foreach (['search-empty', 'search-script', 'search-address', 'search-tx', 'search-hit'] as $name) {
            $this->assertSame('no-store', $c[$name]->header('Cache-Control'), $name);
        }
        $this->assertSame('no-store', $c['post']->header('Cache-Control'));
    }

    // --- every rendered page ------------------------------------------------------------------

    public function testRenderedPagesHaveNoScriptNoInlineStyleAndNoEventHandlers(): void
    {
        foreach ($this->corpus() as $name => $r) {
            $b = $r->body;
            $this->assertSame(0, preg_match('/<script/i', $b), $name . ': no script element');
            $this->assertSame(0, preg_match('/<style/i', $b), $name . ': no style element');
            $this->assertSame(0, preg_match('/\sstyle\s*=/i', $b), $name . ': no style attribute');
            $this->assertSame(0, preg_match('/\son[a-z]+\s*=/i', $b), $name . ': no event handler attribute');
            $this->assertSame(0, preg_match('/javascript:|data:|<iframe|<object|<embed|<form[^>]*method="post"/i', $b), $name);
            $this->assertSame(0, preg_match('/<link[^>]*rel="(?!stylesheet|icon)/i', $b), $name . ': only stylesheets and the icon are linked');
        }
    }

    public function testRenderedPagesLinkOnlyToTheSiteItsFamilyAndTheRepository(): void
    {
        foreach ($this->corpus() as $name => $r) {
            preg_match_all('/(?:href|src|action|srcset)="([^"]*)"/', $r->body, $m);
            foreach ($m[1] as $url) {
                $decoded = html_entity_decode($url);
                if (preg_match('#\Ahttps?://([^/]+)#', $decoded, $h) === 1) {
                    $this->assertTrue(in_array($h[1], self::ALLOWED_HOSTS, true), $name . ': link to ' . $h[1]);
                    $this->assertStringContains('https://', $decoded, $name . ': https only');
                    if ($h[1] === 'github.com') {
                        $this->assertSame('https://github.com/loki-notfound/lateo-explorer', $decoded, $name);
                    }
                } else {
                    $this->assertFalse(str_starts_with($decoded, '//'), $name . ': no protocol-relative link');
                    $this->assertTrue($decoded === '' || $decoded[0] === '/' || $decoded[0] === '#', $name . ': relative links start at the root: ' . $decoded);
                }
            }
            $this->assertSame(0, preg_match('#http://#', $r->body), $name . ': no plain http');
        }
    }

    public function testStylesheetsAndImagesComeFromTheSite(): void
    {
        $page = $this->get('/about')->body;
        preg_match_all('/<link rel="(?:stylesheet|icon)"[^>]*href="([^"]+)"/', $page, $m);
        $this->assertTrue(count($m[1]) >= 5);
        foreach ($m[1] as $href) {
            $this->assertTrue(str_starts_with($href, '/assets/'), $href);
            $file = self::root() . '/public' . strtok($href, '?');
            $this->assertTrue(is_file($file), 'the asset exists: ' . $href);
        }
        $this->assertStringContains('/assets/content.css?v=', $page, 'a mark of the content');
    }

    public function testTheOldAssetNamesAppearOnlyOnTheMethodPage(): void
    {
        foreach ($this->corpus() as $name => $r) {
            if ($name === 'about') {
                continue;
            }
            foreach (['ZEPHUSD', 'ZEPHRSV', 'ZYIELD'] as $old) {
                $this->assertStringNotContains($old, $r->body, $name);
            }
            $this->assertSame(0, preg_match('/\bZEPH\b/', $r->body), $name . ': the old name ZEPH is not shown');
        }
    }

    public function testNoPageNamesANodeAddressOrAUserOfTheIndexer(): void
    {
        foreach ($this->corpus() as $name => $r) {
            foreach (['example.invalid', ':17767', 'own-a', 'own-b', '/srv/', '/home/'] as $secret) {
                $this->assertStringNotContains($secret, $r->body, $name);
            }
        }
    }

    public function testThePagesSpeakInTheHouseVoice(): void
    {
        foreach ($this->corpus() as $name => $r) {
            $text = strtolower(strip_tags($r->body));
            $this->assertSame(0, preg_match('/\bswap(s|ped|ping)?\b/', $text), $name . ': convert, mint or redeem, never swap');
            $this->assertStringNotContains('market price', $text, $name);
            $this->assertStringNotContains('!', strip_tags($r->body), $name . ': no exclamation marks');
        }
    }

    // --- the source ---------------------------------------------------------------------------

    public function testNoCookieNoSessionInOurOwnCode(): void
    {
        foreach ($this->ownCode() as $file => $code) {
            foreach (['setcookie', 'setrawcookie', 'session_start', 'lt_start_session', 'lt_csrf_token', 'lt_verify_csrf', 'lt_set_flash', 'lt_take_flash', '$_COOKIE', '$_SESSION', '$_REQUEST', '$_POST', '$_FILES', 'php://input'] as $word) {
                $this->assertStringNotContains($word, $code, basename($file) . ' must not use ' . $word);
            }
        }
    }

    public function testOnlyTheFrontControllerSendsHeadersOrOutput(): void
    {
        foreach ($this->ownCode() as $file => $code) {
            if (basename($file) === 'index.php' && str_contains($file, 'public')) {
                continue;
            }
            if (str_contains($file, '/bin/') || str_contains($file, '/views/')) {
                continue;
            }
            $this->assertSame(0, preg_match('/(?<![\w>])(?<!function )(header|http_response_code|header_remove|setcookie)\s*\(/', $code), basename($file) . ' sends headers');
            if (str_contains($file, '/Web/')) {
                $this->assertSame(0, preg_match('/\b(echo|print|exit|die)\b\s*[(\'"$;]/', $code), basename($file) . ' prints or exits');
            }
        }
    }

    public function testOnlyQIsReadFromTheQueryAndNothingIsLogged(): void
    {
        foreach ($this->ownCode() as $file => $code) {
            preg_match_all('/\$_GET\b[^;]{0,20}/', $code, $m);
            foreach ($m[0] as $use) {
                $this->assertTrue(str_starts_with($use, "\$_GET['q']"), basename($file) . ': ' . $use);
            }
            $this->assertSame(0, preg_match('/\b(syslog|openlog|file_put_contents|fputs|trigger_error)\s*\(/', $code), basename($file) . ' writes a log or a file');
            $this->assertSame(0, preg_match('/QUERY_STRING|HTTP_REFERER|REMOTE_ADDR|HTTP_X_FORWARDED|HTTP_USER_AGENT/', $code), basename($file) . ' reads request details');
            if (preg_match_all('/\berror_log\s*\([^;]*;/', $code, $logs) > 0) {
                $this->assertSame('index.php', basename($file), 'only the front controller logs');
                foreach ($logs[0] as $call) {
                    $this->assertSame("error_log('explorer: ' . \$e::class);", $call, 'a log line holds the class of an error and nothing else');
                }
            }
        }
        // The request object holds q and the path; the REQUEST_URI is cut to its path.
        $request = $this->read(self::root() . '/src/Web/Request.php');
        $this->assertStringContains('PHP_URL_PATH', $request);
    }

    public function testEveryPrintedValueInTheViewsIsEscaped(): void
    {
        $views = $this->files(self::root() . '/views', 'php');
        $this->assertTrue(count($views) >= 9);
        foreach ($views as $file) {
            $code = $this->read($file);
            $this->assertStringContains('declare(strict_types=1);', $code, basename($file));
            $this->assertSame(0, preg_match('/<\?php\s+(echo|print)\b/', $code), basename($file) . ' prints with echo');
            preg_match_all('/<\?=\s*(.*?)\s*\?>/s', $code, $m);
            $this->assertTrue(count($m[1]) > 0 || basename($file) === 'x');
            foreach ($m[1] as $expression) {
                $this->assertSame(
                    1,
                    preg_match('/\A(lt_esc\(|lt_partial\(|Html::[a-zA-Z]+\(|\$view->render\()/', $expression),
                    basename($file) . ' prints an unescaped expression: ' . substr($expression, 0, 60),
                );
            }
            $this->assertSame(0, preg_match('/<script|<style|\sstyle\s*=|\son[a-z]+\s*=|javascript:/i', $code), basename($file));
        }
    }

    public function testTheHtmlBuildersEscapeWhatTheyPrint(): void
    {
        $this->app(); // loads the style's helpers
        $evil = '<b onclick="x">&"\'';
        $escaped = '&lt;b onclick=&quot;x&quot;&gt;&amp;&quot;&#039;';
        $this->assertStringContains($escaped, \Lateo\Explorer\Web\Html::hash($evil));
        $link = \Lateo\Explorer\Web\Html::hashLink('<b"&' . str_repeat('a', 30), '/x"y');
        $this->assertStringContains('&lt;b&quot;&amp;aaaa', $link);
        $this->assertStringContains('href="/x&quot;y"', $link);
        $this->assertStringNotContains('<b"', $link);
        $this->assertStringContains($escaped, \Lateo\Explorer\Web\Html::tile($evil, 'v'));
        $this->assertStringContains($escaped, \Lateo\Explorer\Web\Html::badge('online', $evil));
        $this->assertStringContains($escaped, \Lateo\Explorer\Web\Html::figure($evil));
    }

    public function testThePagesUseOnlyReadOnlyCallsOfTheAllowList(): void
    {
        $used = [];
        foreach ($this->ownCode() as $file => $code) {
            if (!str_contains($file, '/Web/')) {
                continue;
            }
            preg_match_all('/RpcMethod::([A-Za-z]+)/', $code, $m);
            array_push($used, ...$m[1]);
            $this->assertStringNotContains('Profile::Indexer', $code, basename($file));
            $this->assertStringNotContains('curl_', $code, basename($file) . ' does not open connections itself');
            $this->assertSame(0, preg_match('#https?://(?!explorer\.lateo\.dev|github\.com/loki-notfound/lateo-explorer)#', $code), basename($file) . ' names no other URL');
            $this->assertSame(0, preg_match('/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/', $code), basename($file) . ' holds no IP address');
        }
        $used = array_values(array_unique($used));
        sort($used);
        $this->assertSame(['GetBlock', 'GetLastBlockHeader'], $used, 'the pages ask two calls, both reads');
        $this->assertTrue(RpcMethod::tryFrom('get_block') !== null && RpcMethod::tryFrom('relay_tx') === null);
    }

    public function testTheTransactionIndexerAsksOnlyOwnNodesAndOnlyReads(): void
    {
        $code = $this->read(self::root() . '/src/Indexer/TransactionIndexer.php');
        // Every call names its method, passes allowFallback = false and one node label.
        preg_match_all('/->call\((.*?)\);/s', $code, $m);
        $this->assertCount(2, $m[1], 'two calls: get_block and /get_transactions');
        $methods = [];
        foreach ($m[1] as $call) {
            $this->assertSame(1, preg_match('/RpcMethod::([A-Za-z]+),/', $call, $name), $call);
            $methods[] = $name[1];
            $this->assertSame(1, preg_match('/,\s*false,\s*\$label\s*,?\s*\z/', $call), 'no fallback, one chosen node: ' . $call);
        }
        sort($methods);
        $this->assertSame(['GetBlock', 'GetTransactions'], $methods);
        $this->assertStringNotContains('fallbackLabel', $code);
        $this->assertStringNotContains('orderedNodes', $code);
        $this->assertStringNotContains('Profile::Web', $code);
        $this->assertStringNotContains('http', $code);
        // Only the indexer profile is used by the code that runs it.
        $app = $this->read(self::root() . '/src/Indexer/IndexerApplication.php');
        $this->assertSame(1, substr_count($app, 'new TransactionIndexer('));
        $this->assertSame(1, preg_match('/if \(!\$this->config->limits->txBackfillEnabled\) \{\s*return TxSummary::off\(\);\s*\}\s*if \(\$summary->outcome === RunSummary::ERROR \|\| \$summary->mode !== RunMode::Tip\) \{\s*return TxSummary::skipped\(\);\s*\}/', $app), 'off, and skipped after an error or in backfill mode, before the indexer is made');
        // Nothing of a transaction that is private to its owner is read or stored.
        $parser = $this->read(self::root() . '/src/Indexer/TransactionParser.php');
        $store = $this->read(self::root() . '/src/Db/TransactionStore.php');
        foreach (['k_image', 'key_offsets', 'view_tag', 'extra', 'unlock_time', 'ecdhInfo', 'outPk'] as $field) {
            $this->assertStringNotContains("'" . $field . "'", $parser, $field . ' is not read');
            $this->assertStringNotContains($field, $store, $field . ' is not stored');
        }
        // The page code never uses the transaction calls.
        foreach ($this->ownCode() as $file => $source) {
            if (str_contains($file, '/Web/')) {
                $this->assertStringNotContains('GetTransactions', $source, basename($file));
                $this->assertStringNotContains('TransactionIndexer', $source, basename($file));
            }
        }
    }

    public function testTheRpcAllowListHoldsOnlyReads(): void
    {
        $names = array_map(static fn (RpcMethod $m): string => $m->value, RpcMethod::cases());
        sort($names);
        $this->assertSame([
            '/get_transaction_pool_hashes', '/get_transaction_pool_stats', '/get_transactions', 'get_audited_supply',
            'get_block', 'get_block_header_by_hash', 'get_block_header_by_height', 'get_block_headers_range',
            'get_circulating_supply', 'get_fee_estimate', 'get_info', 'get_last_block_header', 'get_reserve_info',
            'get_version', 'hard_fork_info',
        ], $names);
        foreach ($names as $name) {
            $this->assertSame(0, preg_match('/send|relay|submit|stop|start|set_|save|flush|pop|mining|ban|out_peers|in_peers|limit/', $name), $name);
        }
    }

    public function testTheSkipMarkerBelongsToThePagesOnly(): void
    {
        foreach ($this->ownCode() as $file => $code) {
            if (str_contains($file, '/Indexer/') || str_contains($file, '/Rpc/') || str_contains($file, '/bin/')) {
                $this->assertStringNotContains('skip:', $code, basename($file) . ': the indexer ignores the marker');
            }
        }
    }

    // --- static files -------------------------------------------------------------------------

    public function testHtaccessRoutesRefusesDotFilesAndSetsNosniff(): void
    {
        $h = $this->read(self::root() . '/public/.htaccess');
        $this->assertStringContains('RewriteCond %{REQUEST_FILENAME} !-f', $h);
        $this->assertStringContains('RewriteRule ^ index.php [L]', $h);
        $this->assertStringContains('<FilesMatch "^\.">', $h);
        $this->assertStringContains('Require all denied', $h);
        $this->assertStringContains('Header always set X-Content-Type-Options "nosniff"', $h);
        $this->assertStringContains('Options -Indexes', $h);
        $this->assertStringNotContains('Set-Cookie', $h);
    }

    public function testRobotsTxtAsksCrawlersToLeaveSearchAlone(): void
    {
        $robots = $this->read(self::root() . '/public/robots.txt');
        $this->assertStringContains("User-agent: *\n", $robots);
        $this->assertStringContains("Disallow: /search\n", $robots);
    }

    public function testTheFrontControllerTakesItsPathsFromTheStubOrTheEnvironment(): void
    {
        $i = $this->read(self::root() . '/public/index.php');
        $this->assertStringContains("defined('LATEO_EXPLORER_ROOT')", $i);
        $this->assertStringContains("defined('LATEO_EXPLORER_SETTINGS')", $i);
        $this->assertStringContains("getenv('LATEO_EXPLORER_SETTINGS')", $i);
        $this->assertStringContains('dirname(__DIR__)', $i);
        $this->assertStringContains("header_remove('X-Powered-By')", $i);
    }

    public function testNothingPrivateIsInThePublicTree(): void
    {
        $root = self::root();
        $paths = [...$this->files($root . '/public', 'php'), ...$this->files($root . '/public', 'css'), ...$this->files($root . '/public', 'txt'), ...$this->files($root . '/views', 'php')];
        foreach ($paths as $file) {
            $code = $this->read($file);
            $this->assertSame(0, preg_match('#/home/[a-z_][a-z0-9_.-]*/|/Users/#', $code), basename($file));
            $this->assertSame(0, preg_match('/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/', $code), basename($file));
        }
    }
}
