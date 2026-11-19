<?php

declare(strict_types=1);

namespace Lateo\Explorer\Tests;

use Lateo\Explorer\Indexer\FailureKind;
use Lateo\Explorer\Indexer\HeaderParser;
use Lateo\Explorer\Indexer\IndexerException;
use Lateo\Explorer\Tests\Support\FakeChain;
use Lateo\Explorer\Tests\Support\TestCase;

final class HeaderParserTest extends TestCase
{
    /** @return array<string,mixed> */
    private function header(): array
    {
        return FakeChain::header(500, FakeChain::hashOf('a', 500), FakeChain::hashOf('a', 499));
    }

    /** @param array<string,mixed> $h */
    private function assertInvalid(array $h, string $needle): void
    {
        $e = $this->assertThrows(IndexerException::class, static fn () => HeaderParser::parse($h));
        $this->assertSame(FailureKind::InvalidData, $e->kind);
        $this->assertStringContains($needle, $e->getMessage());
    }

    public function testHexToDecimal(): void
    {
        $this->assertSame('0', HeaderParser::hexToDecimal('0x0'));
        $this->assertSame('255', HeaderParser::hexToDecimal('0xff'));
        $this->assertSame('83925787525', HeaderParser::hexToDecimal('0x138a5de385'));
        $this->assertSame('18446744073709551615', HeaderParser::hexToDecimal('0xffffffffffffffff'));
        $this->assertSame('18446744073709551616', HeaderParser::hexToDecimal('0x10000000000000000'));
        $this->assertSame('340282366920938463463374607431768211455', HeaderParser::hexToDecimal('0x' . str_repeat('f', 32)));
        $this->assertSame('7', HeaderParser::hexToDecimal('0x0000000000000007'));
        $this->assertThrows(\InvalidArgumentException::class, static fn () => HeaderParser::hexToDecimal('0xFF'));
        $this->assertThrows(\InvalidArgumentException::class, static fn () => HeaderParser::hexToDecimal('0x'));
    }

    public function testValidHeader(): void
    {
        $h = HeaderParser::parse($this->header());
        $this->assertSame(500, $h->height);
        $this->assertSame(FakeChain::hashOf('a', 500), $h->hash);
        $this->assertSame('1500', $h->difficulty);
        $this->assertSame('500005', $h->cumulativeDifficulty);
        $this->assertSame(200_000_000_000 + 500, $h->pricing['reserve'] ?? null);
    }

    public function testWideDifficultyAbove64BitsIsKeptWhole(): void
    {
        $arr = $this->header();
        $arr['wide_difficulty'] = '0x10000000000000005';
        $arr['difficulty'] = 5;
        $arr['difficulty_top64'] = 1;
        $this->assertSame('18446744073709551621', HeaderParser::parse($arr)->difficulty);

        $arr['difficulty_top64'] = 2; // the two forms disagree
        $this->assertInvalid($arr, 'wide_difficulty does not match');
    }

    public function testAllZeroPricingRecordIsNotStored(): void
    {
        $arr = $this->header();
        foreach (HeaderParser::PRICING_FIELDS as $f) {
            $arr['pricing_record'][$f] = 0;
        }
        $arr['pricing_record']['signature'] = str_repeat('0', 128);
        $this->assertNull(HeaderParser::parse($arr)->pricing);

        // One non-zero number, or a non-zero signature, makes it a record.
        $arr['pricing_record']['spot'] = 1;
        $this->assertSame(1, HeaderParser::parse($arr)->pricing['spot'] ?? null);
        $arr['pricing_record']['spot'] = 0;
        $arr['pricing_record']['signature'] = str_repeat('0', 127) . '1';
        $this->assertTrue(HeaderParser::parse($arr)->pricing !== null);
    }

    public function testRecordedEmptyAndNonEmptyRecords(): void
    {
        $chain = FakeChain::fromFixture('get_block_headers_range__end_height-89302__start_height-89298');
        $this->assertNull(HeaderParser::parse($chain->at(89299))->pricing);
        $p = HeaderParser::parse($chain->at(89300))->pricing;
        $this->assertSame(3057468230000, $p['spot'] ?? null);
        $this->assertSame(0, $p['yield_price'] ?? null);
    }

    public function testBadFieldsAreRefused(): void
    {
        $base = $this->header();
        $cases = [
            'hash is not 64' => ['hash', 'ABCD'],
            'hash is not 64 ' => ['hash', strtoupper(FakeChain::hashOf('a', 500))],
            'prev_hash is not 64' => ['prev_hash', substr(FakeChain::hashOf('a', 499), 1)],
            'miner_tx_hash is not 64' => ['miner_tx_hash', 5],
            'height is missing or out of range' => ['height', -1],
            'timestamp is missing or out of range' => ['timestamp', 1.5],
            'major_version is missing or out of range' => ['major_version', 256],
            'nonce is missing or out of range' => ['nonce', 4294967296],
            'reward is missing or out of range' => ['reward', null],
            'num_txes is missing or out of range' => ['num_txes', true],
            'wide_difficulty is not a hex number' => ['wide_difficulty', '0xAB'],
            'wide_cumulative_difficulty is not a hex number' => ['wide_cumulative_difficulty', 12],
            'a header of an orphaned block' => ['orphan_status', true],
        ];
        foreach ($cases as $needle => [$field, $value]) {
            $h = $base;
            $h[$field] = $value;
            $this->assertInvalid($h, trim($needle));
        }
        $h = $base;
        unset($h['block_weight']);
        $this->assertInvalid($h, 'block_weight');
        $this->assertInvalid(['height' => 1], 'is not a hex number');
        $this->assertThrows(IndexerException::class, static fn () => HeaderParser::parse('x'));
        $this->assertThrows(IndexerException::class, static fn () => HeaderParser::parse([1, 2]));
    }

    public function testUint64AboveTheSignedRangeIsRefused(): void
    {
        // As received with JSON_BIGINT_AS_STRING: a string of digits.
        $h = $this->header();
        $h['block_size'] = '18446744073709551615';
        $this->assertInvalid($h, 'block_size does not fit a signed 64-bit integer');

        $h = $this->header();
        $h['reward'] = '9223372036854775808';
        $this->assertInvalid($h, 'reward does not fit a signed 64-bit integer');

        $h = $this->header();
        $h['reward'] = PHP_INT_MAX; // the largest value that does fit
        $this->assertSame(PHP_INT_MAX, HeaderParser::parse($h)->reward);

        $h = $this->header();
        $h['pricing_record']['spot'] = '18446744073709551615';
        $this->assertInvalid($h, 'pricing_record.spot does not fit');
    }

    public function testPricingRecordFieldsAreChecked(): void
    {
        $h = $this->header();
        unset($h['pricing_record']['yield_price']);
        $this->assertInvalid($h, 'pricing_record.yield_price');

        $h = $this->header();
        $h['pricing_record']['signature'] = str_repeat('g', 128);
        $this->assertInvalid($h, 'pricing_record.signature');

        $h = $this->header();
        $h['pricing_record']['signature'] = str_repeat('a', 127);
        $this->assertInvalid($h, 'pricing_record.signature');

        $h = $this->header();
        unset($h['pricing_record']);
        $this->assertInvalid($h, 'pricing_record');
    }

    public function testRewardsMemberIsIgnored(): void
    {
        // The daemon declares `rewards` but never fills it; whatever it holds is not read.
        $h = $this->header();
        $h['rewards'] = [['asset_type' => 'ZNEW', 'amount' => 'not a number'], 'junk'];
        $parsed = HeaderParser::parse($h);
        $this->assertSame(500, $parsed->height);
        $this->assertFalse(property_exists($parsed, 'rewards'));
        $h['rewards'] = 'ZPH';
        $this->assertSame(500, HeaderParser::parse($h)->height);
    }
}
