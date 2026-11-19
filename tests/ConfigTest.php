<?php

declare(strict_types=1);

namespace Lateo\Explorer\Tests;

use Lateo\Explorer\Config\Config;
use Lateo\Explorer\Config\ConfigException;
use Lateo\Explorer\Config\NodeKind;
use Lateo\Explorer\Tests\Support\TestCase;

final class ConfigTest extends TestCase
{
    /** @return array<string,mixed> */
    private function valid(): array
    {
        return [
            'nodes' => [
                ['label' => 'own-a', 'kind' => 'own', 'url' => 'http://node-a.example.invalid:17767'],
                ['label' => 'own-b', 'kind' => 'own', 'url' => 'http://node-b.example.invalid:17767'],
                ['label' => 'fallback', 'kind' => 'fallback', 'url' => 'https://node.lateo.dev'],
            ],
            'database' => '/srv/explorer-data/explorer.sqlite',
            'cache_dir' => '/srv/explorer-data/cache',
            'lock_file' => '/srv/explorer-data/indexer.lock',
        ];
    }

    /** Assert that the settings are refused, and that the message does not echo any value. */
    private function refused(array $settings, string $expectInMessage = ''): void
    {
        $e = $this->assertThrows(ConfigException::class, static fn () => Config::fromArray($settings));
        if ($expectInMessage !== '') {
            $this->assertStringContains($expectInMessage, $e->getMessage());
        }
        foreach (['example.invalid', 'node.lateo.dev', '/srv/', 'secret', 'http://'] as $private) {
            $this->assertStringNotContains($private, $e->getMessage(), 'message must not echo values');
        }
    }

    public function testExampleSettingsFileLoads(): void
    {
        $config = Config::load(__DIR__ . '/../settings.example.php');
        $this->assertCount(3, $config->nodes);
        $this->assertSame(['own-a', 'own-b', 'fallback'], array_map(static fn ($n) => $n->label, $config->orderedNodes()));
        $this->assertSame('https://node.lateo.dev', $config->node('fallback')?->url);
        foreach ($config->ownNodes() as $node) {
            $this->assertTrue(str_ends_with((string) parse_url($node->url, PHP_URL_HOST), '.example.invalid'));
        }
        $this->assertSame(4, $config->limits->pageMaxCalls);
        $this->assertSame(30, $config->limits->fallbackCallsPerMinute);
        $this->assertSame(1000, $config->limits->headerBatch);
        $this->assertSame(2.0, $config->limits->backfillPauseSeconds);
        $this->assertSame(45, $config->limits->runSeconds);
        $this->assertSame(100, $config->limits->reorgMaxDepth);
        $this->assertSame(10, $config->limits->unsettledDepth);
        $this->assertSame(60, $config->limits->webOwnCallsPerMinute);
        $this->assertSame(false, $config->limits->txBackfillEnabled, 'the example switches the transaction indexer off');
        $this->assertSame(2.0, $config->limits->txBackfillPauseSeconds);
    }

    public function testMissingSettingsFile(): void
    {
        $this->assertThrows(ConfigException::class, static fn () => Config::load(__DIR__ . '/no-such-settings.php'));
    }

    public function testNodeOrderOwnFirstThenFallback(): void
    {
        $s = $this->valid();
        $s['nodes'] = [$s['nodes'][2], $s['nodes'][1], $s['nodes'][0]];
        $config = Config::fromArray($s);
        $this->assertSame(['own-b', 'own-a', 'fallback'], array_map(static fn ($n) => $n->label, $config->orderedNodes()));
        $this->assertSame(NodeKind::Fallback, $config->node('fallback')?->kind);
        $this->assertNull($config->node('nope'));
    }

    public function testTrailingSlashOfUrlIsDropped(): void
    {
        $s = $this->valid();
        $s['nodes'][0]['url'] = 'http://node-a.example.invalid:17767/';
        $this->assertSame('http://node-a.example.invalid:17767', Config::fromArray($s)->node('own-a')?->url);
    }

    public function testKindMustBeOwnOrFallback(): void
    {
        $s = $this->valid();
        $s['nodes'][0]['kind'] = 'public';
        $this->refused($s, 'kind');
        $s['nodes'][0]['kind'] = 'OWN';
        $this->refused($s, 'kind');
        unset($s['nodes'][0]['kind']);
        $this->refused($s, 'kind');
    }

    public function testAtLeastOneOwnNode(): void
    {
        $s = $this->valid();
        $s['nodes'] = [$s['nodes'][2]];
        $this->refused($s, 'own');
        $s['nodes'] = [];
        $this->refused($s, 'nodes');
    }

    public function testSchemeMustBeHttpOrHttps(): void
    {
        foreach (['ftp://node-a.example.invalid', 'file:///etc/passwd', 'gopher://x.example.invalid', 'node-a.example.invalid:17767', '//node-a.example.invalid'] as $url) {
            $s = $this->valid();
            $s['nodes'][0]['url'] = $url;
            $this->refused($s, 'nodes[0].url');
        }
        $s = $this->valid();
        $s['nodes'][0]['url'] = 'HTTPS://node-a.example.invalid';
        $this->assertSame('HTTPS://node-a.example.invalid', Config::fromArray($s)->node('own-a')?->url);
    }

    public function testNoUserOrPasswordInUrl(): void
    {
        foreach (['http://user@node-a.example.invalid', 'http://user:secret@node-a.example.invalid', 'http://:secret@node-a.example.invalid'] as $url) {
            $s = $this->valid();
            $s['nodes'][0]['url'] = $url;
            $this->refused($s, 'user or password');
        }
    }

    public function testNoQueryFragmentOrPathInUrl(): void
    {
        foreach (['http://node-a.example.invalid/?a=1', 'http://node-a.example.invalid#x', 'http://node-a.example.invalid/json_rpc'] as $url) {
            $s = $this->valid();
            $s['nodes'][0]['url'] = $url;
            $this->refused($s, 'nodes[0].url');
        }
    }

    public function testUrlMustBePrintableAndNotEmpty(): void
    {
        foreach (['', "http://node-a.example.invalid\n", 'http://node a.example.invalid', 'http://'] as $url) {
            $s = $this->valid();
            $s['nodes'][0]['url'] = $url;
            $this->refused($s, 'nodes[0].url');
        }
        $s = $this->valid();
        $s['nodes'][0]['url'] = 12345;
        $this->refused($s, 'nodes[0].url');
    }

    public function testLabelRules(): void
    {
        foreach (['Own-A', 'own_a', '', 'abcdefghijklmnopqrstu', 'own a', "own\n", 'own.a'] as $label) {
            $s = $this->valid();
            $s['nodes'][0]['label'] = $label;
            $this->refused($s, 'label');
        }
        $s = $this->valid();
        $s['nodes'][0]['label'] = 'abcdefghijklmnopqrst'; // 20 characters
        $this->assertSame('abcdefghijklmnopqrst', Config::fromArray($s)->nodes[0]->label);
        $s['nodes'][1]['label'] = 'abcdefghijklmnopqrst';
        $this->refused($s, 'used twice');
    }

    public function testPathsMustBeAbsolute(): void
    {
        foreach (['database', 'cache_dir', 'lock_file'] as $key) {
            foreach (['relative/path', '', './x', '../x', '/srv/../etc/x', "/srv/x\0y"] as $path) {
                $s = $this->valid();
                $s[$key] = $path;
                $this->refused($s, $key);
            }
            $s = $this->valid();
            unset($s[$key]);
            $this->refused($s, $key);
        }
    }

    public function testUnknownKeysAreRefused(): void
    {
        $s = $this->valid();
        $s['database_url'] = 'x';
        $this->refused($s, 'unknown setting');
        $s = $this->valid();
        $s['nodes'][0]['token'] = 'secret';
        $this->refused($s, 'unknown key');
        $s = $this->valid();
        $s['limits'] = ['page_max_call' => 4];
        $this->refused($s, 'unknown key');
    }

    public function testLimitsAreCheckedAgainstTheirRanges(): void
    {
        $bad = [
            'page_max_calls' => [0, 21, '4', 4.0],
            'fallback_calls_per_minute' => [0, 301, '30'],
            'header_batch' => [0, 1001, 999.5],
            'backfill_pause_seconds' => [-0.1, 60.5, '2.0', NAN],
            'run_seconds' => [0, 3601],
            'reorg_max_depth' => [0, 10001],
            'unsettled_depth' => [-1, 10001],
            'web_own_calls_per_minute' => [0, 601, '60', 60.0],
        ];
        foreach ($bad as $key => $values) {
            foreach ($values as $value) {
                $s = $this->valid();
                $s['limits'] = [$key => $value];
                $this->refused($s, 'limits.' . $key);
            }
        }
        $s = $this->valid();
        $s['limits'] = ['page_max_calls' => 1, 'fallback_calls_per_minute' => 300, 'header_batch' => 1, 'backfill_pause_seconds' => 0, 'run_seconds' => 3600, 'reorg_max_depth' => 10000, 'unsettled_depth' => 0, 'web_own_calls_per_minute' => 600];
        $limits = Config::fromArray($s)->limits;
        $this->assertSame(1, $limits->pageMaxCalls);
        $this->assertSame(0.0, $limits->backfillPauseSeconds);
        $this->assertSame(10000, $limits->reorgMaxDepth);
        $this->assertSame(600, $limits->webOwnCallsPerMinute);
    }

    public function testTheTransactionIndexerIsOffByDefaultAndTheSwitchMustBeABoolean(): void
    {
        $limits = Config::fromArray($this->valid())->limits;
        $this->assertSame(false, $limits->txBackfillEnabled);
        $this->assertSame(2.0, $limits->txBackfillPauseSeconds);
        $s = $this->valid();
        $s['limits'] = ['tx_backfill_enabled' => true, 'tx_backfill_pause_seconds' => 0.1];
        $limits = Config::fromArray($s)->limits;
        $this->assertSame(true, $limits->txBackfillEnabled);
        $this->assertSame(0.1, $limits->txBackfillPauseSeconds);
        $s['limits'] = ['tx_backfill_enabled' => false, 'tx_backfill_pause_seconds' => 60];
        $this->assertSame(60.0, Config::fromArray($s)->limits->txBackfillPauseSeconds);
        foreach ([1, 0, 'true', 'yes', 'false', []] as $bad) {
            $s['limits'] = ['tx_backfill_enabled' => $bad];
            $this->refused($s, 'limits.tx_backfill_enabled');
        }
    }

    public function testThePauseOfTheTransactionIndexerIsBoundedFrom01To60(): void
    {
        foreach ([0, 0.0, 0.09, -1, 60.5, 61, '2.0', NAN, true] as $bad) {
            $s = $this->valid();
            $s['limits'] = ['tx_backfill_pause_seconds' => $bad];
            $this->refused($s, 'limits.tx_backfill_pause_seconds');
        }
    }

    public function testUnsettledDepthMustNotExceedReorgDepth(): void
    {
        $s = $this->valid();
        $s['limits'] = ['reorg_max_depth' => 5, 'unsettled_depth' => 6];
        $this->refused($s, 'unsettled_depth');
        $s['limits'] = ['reorg_max_depth' => 5, 'unsettled_depth' => 5];
        $this->assertSame(5, Config::fromArray($s)->limits->unsettledDepth);
    }

    public function testLimitsDefaultWhenOmitted(): void
    {
        $limits = Config::fromArray($this->valid())->limits;
        $this->assertSame(4, $limits->pageMaxCalls);
        $this->assertSame(45, $limits->runSeconds);
    }
}
