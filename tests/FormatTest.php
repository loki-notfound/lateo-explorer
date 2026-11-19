<?php

declare(strict_types=1);

namespace Lateo\Explorer\Tests;

use Lateo\Explorer\Tests\Support\TestCase;
use Lateo\Explorer\Web\Format;

/** The text formats of the house style: amounts, ratios, prices, times, ages, numbers. */
final class FormatTest extends TestCase
{
    public function testIntegersGetCommasFromFiveDigits(): void
    {
        $this->assertSame('0', Format::int(0));
        $this->assertSame('1024', Format::int(1024));
        $this->assertSame('89,300', Format::int(89300));
        $this->assertSame('910,657', Format::int(910657));
        $this->assertSame('1,250,000', Format::int(1250000));
        $this->assertSame('57,841,357,900,601,495', Format::int('57841357900601495'));
        $this->assertSame("\u{2212}12,345", Format::int(-12345), 'the Unicode minus');
        $this->assertThrows(\InvalidArgumentException::class, fn () => Format::int('12a'));
    }

    public function testAmountsInDetailHaveTwelveDecimals(): void
    {
        $this->assertSame('0.000000000000 ZPH', Format::amount(0, 'ZPH'));
        $this->assertSame('1.250000000000 ZPH', Format::amount(1_250_000_000_000, 'ZPH'));
        $this->assertSame('3.846798411949 ZPH', Format::amount(3846798411949, 'ZPH'));
        $this->assertSame('0.000045000000 ZSD', Format::amount(45_000_000, 'ZSD'));
        $this->assertSame('0.000000000001 ZRS', Format::amount(1, 'ZRS'));
        $this->assertSame('1,921,656.672716101217 ZPH', Format::amount(1921656672716101217, 'ZPH'));
        $this->assertSame('12,406,707.323459691262 ZPH', Format::amount('12406707323459691262', 'ZPH'), 'above PHP_INT_MAX, exact');
    }

    public function testCompactAmountsKeepTwoToFourDecimals(): void
    {
        $this->assertSame('0.00 ZPH', Format::amount(0, 'ZPH', false));
        $this->assertSame('1.25 ZPH', Format::amount(1_250_000_000_000, 'ZPH', false));
        $this->assertSame('14.0825 ZSD', Format::amount(14_082_500_000_000, 'ZSD', false));
        $this->assertSame('3.8468 ZPH', Format::amount(3846798411949, 'ZPH', false), 'rounded half up to four decimals');
        $this->assertSame('1.00 ZPH', Format::amount(1_000_049_999_999, 'ZPH', false));
        $this->assertSame('1.0001 ZPH', Format::amount(1_000_050_000_000, 'ZPH', false));
        $this->assertSame('1,000.00 ZSD', Format::amount(1_000_000_000_000_000, 'ZSD', false));
    }

    public function testACompactAmountNeverShowsANonZeroValueAsZero(): void
    {
        $this->assertSame('0.000045 ZPH', Format::amount(45_000_000, 'ZPH', false));
        $this->assertSame('0.000000000001 ZPH', Format::amount(1, 'ZPH', false));
        $this->assertSame('0.00001 ZPH', Format::amount(10_000_000, 'ZPH', false));
    }

    public function testRatiosAreTwoDecimalPercentages(): void
    {
        $this->assertSame('197.11%', Format::ratio(1971054120000));
        $this->assertSame('195.24%', Format::ratio(1952384560000));
        $this->assertSame('100.00%', Format::ratio(1_000_000_000_000));
        $this->assertSame('0.00%', Format::ratio(0));
        $this->assertSame('0.01%', Format::ratio(100_000_000));
        $this->assertSame('559.16%', Format::ratio(5591554480000));
        $this->assertSame('1,000.00%', Format::ratio(10_000_000_000_000));
    }

    public function testPricesComeFromTheTwelveDecimalScale(): void
    {
        $this->assertSame('0.132514140000', Format::price(132514140000));
        $this->assertSame('0.1325', Format::price(132514140000, false));
        $this->assertSame('3.057468230000', Format::price(3057468230000));
        $this->assertSame('3.0575', Format::price(3057468230000, false));
        $this->assertSame('1.9956', Format::price(1995604220000, false));
        $this->assertSame('7.546364480000', Format::decimal(7546364480000));
    }

    public function testTimesAreUtcWithTheDateTimeFormat(): void
    {
        $this->assertSame('2023-10-01 09:27:07 UTC', Format::time(1696152427));
        $this->assertSame('2023-10-01T09:27:07Z', Format::isoTime(1696152427));
        $this->assertSame('1970-01-01 00:00:00 UTC', Format::time(0));
    }

    public function testAgesFollowTheHouseStyle(): void
    {
        $now = 1_800_000_000;
        $this->assertSame('just now', Format::age($now - 9, $now));
        $this->assertSame('just now', Format::age($now + 30, $now), 'a time ahead of the clock');
        $this->assertSame('10 s ago', Format::age($now - 10, $now));
        $this->assertSame('45 s ago', Format::age($now - 45, $now));
        $this->assertSame('1 min ago', Format::age($now - 60, $now));
        $this->assertSame('3 min ago', Format::age($now - 200, $now));
        $this->assertSame('59 min ago', Format::age($now - 3599, $now));
        $this->assertSame('1 h ago', Format::age($now - 3600, $now));
        $this->assertSame('2 h ago', Format::age($now - 7300, $now));
        $this->assertSame('1 d ago', Format::age($now - 86400, $now));
        $this->assertSame('5 d ago', Format::age($now - 5 * 86400 - 100, $now));
        $this->assertSame('30 d ago', Format::age($now - 30 * 86400, $now));
        $this->assertSame('', Format::age($now - 30 * 86400 - 1, $now), 'beyond 30 days the date alone stands');
    }

    public function testHashesAndTextAreShortened(): void
    {
        $hash = '79a4f374d0e222481ec9e48c3e31e2bc2120eb573f16670b54f56e88037d6a85';
        $this->assertSame('79a4f374…037d6a85', Format::hashShort($hash));
        $this->assertSame('abc', Format::hashShort('abc'));
        $this->assertSame('abc', Format::shorten('abc', 40));
        $this->assertSame(str_repeat('a', 40) . '…', Format::shorten(str_repeat('a', 50), 40));
        $this->assertSame('a?b', Format::shorten("a\x01b", 40), 'control bytes are not echoed');
        $this->assertSame('caf??', Format::shorten("caf\u{e9}", 40), 'nor are non-ASCII bytes');
        $this->assertSame('60,818 bytes', Format::bytes(60818));
    }
}
