<?php

declare(strict_types=1);

namespace Lateo\Explorer\Tests;

use Lateo\Explorer\Chain\Asset;
use Lateo\Explorer\Chain\Generation;
use Lateo\Explorer\Tests\Support\TestCase;

final class AssetTest extends TestCase
{
    public function testOldNamesMapToTickers(): void
    {
        $expected = ['ZEPH' => 'ZPH', 'ZEPHUSD' => 'ZSD', 'ZEPHRSV' => 'ZRS', 'ZYIELD' => 'ZYS'];
        foreach ($expected as $raw => $ticker) {
            $asset = Asset::fromChain($raw);
            $this->assertSame($ticker, $asset->ticker, $raw);
            $this->assertSame(Generation::Old, $asset->generation, $raw);
            $this->assertTrue($asset->isKnown());
        }
    }

    public function testNewNamesMapToThemselves(): void
    {
        foreach (['ZPH', 'ZSD', 'ZRS', 'ZYS'] as $raw) {
            $asset = Asset::fromChain($raw);
            $this->assertSame($raw, $asset->ticker);
            $this->assertSame(Generation::New, $asset->generation);
            $this->assertTrue($asset->isKnown());
        }
        $this->assertSame(['ZPH', 'ZSD', 'ZRS', 'ZYS'], Asset::TICKERS);
    }

    public function testEverythingElseIsUnknownAndNeverEchoed(): void
    {
        $inputs = [
            '', 'zph', 'Zeph', 'ZEPH ', " ZPH", "ZPH\n", "ZPH\0", 'ZEPHX', 'ZEPHUSDX', 'XMR', 'DJED', 'YIELD', 'ZYIELDRSV', 'BTC',
            '<script>alert(1)</script>', '"><img src=x onerror=1>', str_repeat('A', 5000), "\xff\xfe", 'ZPH,ZSD',
        ];
        foreach ($inputs as $raw) {
            $asset = Asset::fromChain($raw);
            $this->assertSame('unknown', $asset->ticker);
            $this->assertSame(Generation::Unknown, $asset->generation);
            $this->assertFalse($asset->isKnown());
            $this->assertStringNotContains('script', serialize($asset));
            $this->assertTrue(strlen(serialize($asset)) < 200, 'the input is not carried along');
        }
    }
}
