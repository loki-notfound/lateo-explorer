<?php

declare(strict_types=1);

namespace Lateo\Explorer\Tests;

use Lateo\Explorer\Chain\ConversionResult;
use Lateo\Explorer\Chain\ConversionRule;
use Lateo\Explorer\Chain\Generation;
use Lateo\Explorer\Tests\Support\TestCase;

/**
 * The recomputation rule. The expected numbers were computed independently (Python integers)
 * from the rule of the research, with the record below: its magnitudes are real, its use is
 * synthetic.
 */
final class ConversionRuleTest extends TestCase
{
    /** stable < stable_ma and reserve > reserve_ma. @var array<string,int|string> */
    private const RECORD = [
        'spot' => 116620590000, 'moving_average' => 109769140000,
        'stable' => 8640300900000, 'stable_ma' => 9173102000000,
        'reserve' => 566273020000, 'reserve_ma' => 537826170000,
        'reserve_ratio' => 1641198260000, 'reserve_ratio_ma' => 1522058320000,
        'yield_price' => 1995233720000,
    ];

    /** @param array<string,int|string> $changes @return array<string,int|string> */
    private static function record(array $changes = []): array
    {
        return $changes + self::RECORD;
    }

    /** A check at height 1000 with the record at 998 (age 2). */
    private static function check(string $type, int $hf, int|string $burnt, int|string $minted = 1, ?array $record = self::RECORD, int|string $prh = 998, int $height = 1000): ConversionResult
    {
        $generation = self::generation($hf);
        return ConversionRule::check($type, $hf, $height, $prh, $burnt, $minted, $record, $generation, $generation);
    }

    /** The names a transaction of that hard fork uses: the old ones up to hard fork 10, then the new. */
    private static function generation(int $hf): Generation
    {
        return $hf >= 11 ? Generation::New : Generation::Old;
    }

    /** @param list<string> $want r0, fee, rate, expected, remainder */
    private function assertNumbers(array $want, ConversionResult $r, string $what): void
    {
        $this->assertSame('checked', $r->reason, $what);
        $this->assertSame($want, [$r->r0, $r->fee, $r->rate, $r->expected, $r->remainder], $what);
    }

    // --- the independent table ----------------------------------------------------------------

    public function testTheIndependentTableExactly(): void
    {
        $table = [
            ['redeem_stable', 11, '1000000000000000', ['8640300900000', '8640300900', '8631660590000', '8631660590000000', '0']],
            ['mint_stable', 11, '1000000000000000', ['109014377034', '109014377', '108905360000', '108905360000000', '0']],
            ['mint_yield', 11, '500000000000000', ['501194416461', '501194416', '500693220000', '250346610000000', '0']],
            ['redeem_reserve', 11, '300000000000000', ['537826170000', '5378261700', '532447900000', '159734370000000', '0']],
            ['mint_stable', 4, '1000000000000000', ['109014377034', '2180287540', '106834080000', '106834080000000', '0']],
            ['redeem_stable', 4, '1000000000000000', ['8640300900000', '172806018000', '8467494880000', '8467494880000000', '0']],
            ['mint_reserve', 4, '1000000000000000', ['1765932623807', '0', '1765932620000', '1765932620000000', '0']],
            ['redeem_reserve', 4, '300000000000000', ['537826170000', '10756523400', '527069640000', '158120892000000', '0']],
        ];
        foreach ($table as [$type, $hf, $burnt, $want]) {
            $r = self::check($type, $hf, $burnt, $want[3]);
            $this->assertNumbers($want, $r, $type . ' hf ' . $hf);
            $this->assertSame(ConversionResult::MATCH, $r->verdict, $type . ' hf ' . $hf);
        }
    }

    public function testBothBranchesOfMaxAndMinAndTheFieldTheyCameFrom(): void
    {
        // The record has stable < stable_ma and reserve > reserve_ma.
        $this->assertSame(['stable', '8640300900000'], $this->field('redeem_stable', self::RECORD));
        $this->assertSame(['stable_ma', '9173102000000'], $this->field('mint_stable', self::RECORD));
        $this->assertSame(['reserve_ma', '537826170000'], $this->field('redeem_reserve', self::RECORD));
        $this->assertSame(['reserve', '566273020000'], $this->field('mint_reserve', self::RECORD));
        // The other way round.
        $swapped = self::record([
            'stable' => 9173102000000, 'stable_ma' => 8640300900000, 'reserve' => 537826170000, 'reserve_ma' => 566273020000,
        ]);
        $this->assertSame(['stable_ma', '8640300900000'], $this->field('redeem_stable', $swapped));
        $this->assertSame(['stable', '9173102000000'], $this->field('mint_stable', $swapped));
        $this->assertSame(['reserve', '537826170000'], $this->field('redeem_reserve', $swapped));
        $this->assertSame(['reserve_ma', '566273020000'], $this->field('mint_reserve', $swapped));
        // The result is the same for both, as max and min are symmetric.
        $a = self::check('mint_reserve', 11, '1000000000000000', 1, $swapped);
        $this->assertNumbers(['1765932623807', '17659326238', '1748273290000', '1748273290000000', '0'], $a, 'mint_reserve swapped');
        // Yield uses its own price.
        $this->assertSame(['yield_price', '1995233720000'], $this->field('redeem_yield', self::RECORD, 6));
        $this->assertSame(['yield_price', '1995233720000'], $this->field('mint_yield', self::RECORD, 6));
        // On a tie the first field is named, the value is the same.
        $tie = self::record(['stable_ma' => 8640300900000]);
        $this->assertSame(['stable', '8640300900000'], $this->field('redeem_stable', $tie));
        $this->assertSame(['stable', '8640300900000'], $this->field('mint_stable', $tie));
    }

    /** @param array<string,int|string> $record @return array{0:?string,1:?string} */
    private function field(string $type, array $record, int $hf = 11): array
    {
        $r = self::check($type, $hf, 1000000, 1, $record);
        $this->assertSame('checked', $r->reason);
        return [$r->priceField, $r->price];
    }

    // --- the fees at their boundaries ---------------------------------------------------------

    public function testTheFeeRegimesAtTheirBoundaries(): void
    {
        $burnt = '1000000000000000';
        // hf 4 to 5: the stable fee falls from 2% to 0.1%, the reserve mint fee rises from 0 to 1%,
        // the reserve redeem fee falls from 2% to 1%.
        $want = [
            ['mint_stable', 4, ['109014377034', '2180287540', '106834080000', '106834080000000', '0']],
            ['mint_stable', 5, ['109014377034', '109014377', '108905360000', '108905360000000', '0']],
            ['redeem_stable', 4, ['8640300900000', '172806018000', '8467494880000', '8467494880000000', '0']],
            ['redeem_stable', 5, ['8640300900000', '8640300900', '8631660590000', '8631660590000000', '0']],
            ['mint_reserve', 4, ['1765932623807', '0', '1765932620000', '1765932620000000', '0']],
            ['mint_reserve', 5, ['1765932623807', '17659326238', '1748273290000', '1748273290000000', '0']],
            ['redeem_reserve', 4, ['537826170000', '10756523400', '527069640000', '527069640000000', '0']],
            ['redeem_reserve', 5, ['537826170000', '5378261700', '532447900000', '532447900000000', '0']],
        ];
        foreach ($want as [$type, $hf, $numbers]) {
            $this->assertNumbers($numbers, self::check($type, $hf, $burnt), $type . ' hf ' . $hf);
        }
        // hf 3 has the hf 4 fees.
        foreach (['mint_stable', 'redeem_stable', 'mint_reserve', 'redeem_reserve'] as $type) {
            $a = self::check($type, 3, $burnt, 1, self::record(['reserve_ratio' => 0]));
            $b = self::check($type, 4, $burnt);
            $this->assertSame([$b->r0, $b->fee, $b->rate], [$a->r0, $a->fee, $a->rate], $type . ' hf 3 and 4');
        }
        // hf 5 to 6 and later: the stable and reserve fees stay, yield appears (0.1%).
        foreach (['mint_stable', 'redeem_stable', 'mint_reserve', 'redeem_reserve'] as $type) {
            foreach ([6, 7, 11] as $hf) {
                $a = self::check($type, 5, $burnt);
                $b = self::check($type, $hf, $burnt);
                $this->assertSame([$a->r0, $a->fee, $a->rate], [$b->r0, $b->fee, $b->rate], $type . ' hf 5 and ' . $hf);
            }
        }
        $this->assertNumbers(['1995233720000', '1995233720', '1993238480000', '1993238480000000', '0'], self::check('redeem_yield', 6, $burnt), 'redeem_yield hf 6');
        $this->assertNumbers(['501194416461', '501194416', '500693220000', '500693220000000', '0'], self::check('mint_yield', 6, $burnt), 'mint_yield hf 6');
        foreach ([7, 11] as $hf) {
            $this->assertSame('1993238480000', self::check('redeem_yield', $hf, $burnt)->rate, 'yield hf ' . $hf);
        }
    }

    public function testTheRateIsRoundedDownToAMultipleOf10000(): void
    {
        $record = self::record(['stable' => 1234567890123, 'stable_ma' => 2000000000000]);
        // r0 1234567890123, fee 1234567890, r0 - fee 1233333322233, rounded 1233333320000.
        $r = self::check('redeem_stable', 11, 123456789, 152263371, $record);
        $this->assertNumbers(['1234567890123', '1234567890', '1233333320000', '152263371', '453909480000'], $r, 'rounding');
        $this->assertSame(ConversionResult::MATCH, $r->verdict, 'the remainder does not matter to the match');
        $this->assertSame('0', (string) (((int) $r->rate) % 10000));
        // Without the rounding the answer would be 152263371 too, so show a case where it is not:
        // 10^12 burnt gives exactly the rate.
        $this->assertSame('1233333320000', self::check('redeem_stable', 11, '1000000000000', 1, $record)->expected);
    }

    public function testALargeAmountIsExact(): void
    {
        $max = '9223372036854775807'; // 2^63 - 1
        $r = self::check('redeem_stable', 11, '2000000000000000000', '17263321180000000000');
        $this->assertNumbers(['8640300900000', '8640300900', '8631660590000', '17263321180000000000', '0'], $r, 'large redeem');
        $this->assertSame(ConversionResult::MATCH, $r->verdict);
        $r = self::check('mint_stable', 11, $max, '1004474652087602626');
        $this->assertNumbers(['109014377034', '109014377', '108905360000', '1004474652087602626', '980625520000'], $r, 'large mint');
        $this->assertSame(ConversionResult::MATCH, $r->verdict);
        // An integer burnt and a string burnt give the same answer.
        $this->assertSame($r->expected, self::check('mint_stable', 11, PHP_INT_MAX)->expected);
        // A minted amount one unit off is a mismatch.
        $this->assertSame(ConversionResult::MISMATCH, self::check('mint_stable', 11, $max, '1004474652087602627')->verdict);
    }

    // --- match and mismatch -------------------------------------------------------------------

    public function testMatchAndMismatchDifferByTheMintedAmountOnly(): void
    {
        $ok = self::check('redeem_stable', 11, '1000000000000000', '8631660590000000');
        $this->assertSame([ConversionResult::MATCH, 'checked'], [$ok->verdict, $ok->reason]);
        $this->assertTrue($ok->isChecked());
        foreach (['8631660590000001', '8631660589999999', '1'] as $minted) {
            $bad = self::check('redeem_stable', 11, '1000000000000000', $minted);
            $this->assertSame([ConversionResult::MISMATCH, 'checked'], [$bad->verdict, $bad->reason], $minted);
            $this->assertSame('8631660590000000', $bad->expected, 'the expected value is what the rule computes');
        }
    }

    public function testAnAuditIsCheckedWithoutARecord(): void
    {
        foreach ([8, 10] as $hf) {
            $r = ConversionRule::check('audit', $hf, 481502, 0, '123456789012', '123456789012', null, Generation::Old, Generation::New);
            $this->assertSame(ConversionResult::MATCH, $r->verdict, 'hf ' . $hf);
            $this->assertSame([null, null, '1000000000000', '0', '1000000000000', '123456789012', '0'], [$r->priceField, $r->price, $r->r0, $r->fee, $r->rate, $r->expected, $r->remainder]);
            $bad = ConversionRule::check('audit', $hf, 481502, 0, '123456789012', '123456789013', null, Generation::Old, Generation::New);
            $this->assertSame(ConversionResult::MISMATCH, $bad->verdict);
            // The age is not looked at and a record is not used.
            $this->assertSame(ConversionResult::MATCH, ConversionRule::check('audit', $hf, 481502, 0, 7, 7, self::RECORD, Generation::Old, Generation::New)->verdict);
        }
    }

    // --- not recomputed -----------------------------------------------------------------------

    private function assertNot(string $reason, ConversionResult $r, string $what): void
    {
        $this->assertSame([ConversionResult::NOT_RECOMPUTED, $reason], [$r->verdict, $r->reason], $what);
        $this->assertFalse($r->isChecked(), $what);
        $this->assertSame([null, null, null, null, null, null, null], [$r->priceField, $r->price, $r->r0, $r->fee, $r->rate, $r->expected, $r->remainder], $what);
    }

    public function testTheAgeWindowIsOneToTen(): void
    {
        foreach ([0, -3, 11, 12, 1000] as $age) {
            $this->assertNot('age_out_of_window', self::check('redeem_stable', 11, 1000, 1, self::RECORD, 1000 - $age), 'age ' . $age);
        }
        foreach ([1, 2, 10] as $age) {
            $this->assertSame('checked', self::check('redeem_stable', 11, 1000, 1, self::RECORD, 1000 - $age)->reason, 'age ' . $age);
        }
        // A conversion with no record height (0) is far outside the window.
        $this->assertNot('age_out_of_window', self::check('mint_stable', 11, 1000, 1, self::RECORD, 0), 'height 0');
        $this->assertNot('age_out_of_window', self::check('mint_stable', 11, 1000, 1, self::RECORD, 1001), 'a record from the future');
    }

    public function testAMissingRecordIsNotAMismatch(): void
    {
        $this->assertNot('pr_missing', self::check('redeem_stable', 11, 1000, 1, null), 'no record');
    }

    public function testAnIncompleteRecordPerHardFork(): void
    {
        $always = ['spot', 'moving_average', 'stable', 'stable_ma', 'reserve', 'reserve_ma'];
        foreach ([3, 4, 5, 6, 11] as $hf) {
            foreach ($always as $field) {
                $this->assertNot('pr_incomplete', self::check('redeem_stable', $hf, 1000, 1, self::record([$field => 0])), $field . ' zero at hf ' . $hf);
            }
        }
        // reserve_ratio from hf 4, reserve_ratio_ma from hf 5, yield_price from hf 6.
        $this->assertSame('checked', self::check('redeem_stable', 3, 1000, 1, self::record(['reserve_ratio' => 0, 'reserve_ratio_ma' => 0, 'yield_price' => 0]))->reason);
        $this->assertNot('pr_incomplete', self::check('redeem_stable', 4, 1000, 1, self::record(['reserve_ratio' => 0])), 'hf 4 without reserve_ratio');
        $this->assertSame('checked', self::check('redeem_stable', 4, 1000, 1, self::record(['reserve_ratio_ma' => 0, 'yield_price' => 0]))->reason);
        $this->assertNot('pr_incomplete', self::check('redeem_stable', 5, 1000, 1, self::record(['reserve_ratio' => 0])), 'hf 5 without reserve_ratio');
        $this->assertNot('pr_incomplete', self::check('redeem_stable', 5, 1000, 1, self::record(['reserve_ratio_ma' => 0])), 'hf 5 without reserve_ratio_ma');
        $this->assertSame('checked', self::check('redeem_stable', 5, 1000, 1, self::record(['yield_price' => 0]))->reason);
        foreach ([6, 7, 11] as $hf) {
            foreach (['reserve_ratio', 'reserve_ratio_ma', 'yield_price'] as $field) {
                $this->assertNot('pr_incomplete', self::check('redeem_stable', $hf, 1000, 1, self::record([$field => 0])), $field . ' zero at hf ' . $hf);
            }
        }
        $this->assertTrue(ConversionRule::recordComplete(self::RECORD, 11));
        $this->assertFalse(ConversionRule::recordComplete(self::record(['yield_price' => 0]), 11));
        $this->assertTrue(ConversionRule::recordComplete(self::record(['yield_price' => 0]), 5));
    }

    public function testTheTypesAllowedPerHardFork(): void
    {
        $conversions = ['mint_stable', 'redeem_stable', 'mint_reserve', 'redeem_reserve'];
        foreach ([1, 2] as $hf) {
            foreach ([...$conversions, 'mint_yield', 'redeem_yield', 'audit'] as $type) {
                $this->assertNot('type_not_allowed', self::check($type, $hf, 1000), $type . ' hf ' . $hf);
            }
        }
        foreach ($conversions as $type) {
            foreach ([3, 4, 5, 6, 7, 11, 12] as $hf) {
                $this->assertTrue(ConversionRule::allowed($type, $hf), $type . ' hf ' . $hf);
            }
            foreach ([8, 9, 10] as $hf) {
                $this->assertNot('type_not_allowed', self::check($type, $hf, 1000), $type . ' hf ' . $hf);
            }
        }
        foreach (['mint_yield', 'redeem_yield'] as $type) {
            foreach ([3, 4, 5] as $hf) {
                $this->assertNot('type_not_allowed', self::check($type, $hf, 1000), $type . ' hf ' . $hf);
            }
            foreach ([6, 7, 11] as $hf) {
                $this->assertTrue(ConversionRule::allowed($type, $hf), $type . ' hf ' . $hf);
            }
            foreach ([8, 9, 10] as $hf) {
                $this->assertFalse(ConversionRule::allowed($type, $hf), $type . ' hf ' . $hf);
            }
        }
        foreach ([3, 4, 5, 6, 7, 9, 11, 12] as $hf) {
            $this->assertNot('type_not_allowed', ConversionRule::check('audit', $hf, 1000, 0, 5, 5, null, Generation::Old, Generation::New), 'audit hf ' . $hf);
        }
        $this->assertTrue(ConversionRule::allowed('audit', 8) && ConversionRule::allowed('audit', 10));
    }

    public function testAnUnknownTypeAndATransferWithConversionFields(): void
    {
        foreach (['unknown', '', 'swap', 'MINT_STABLE'] as $type) {
            $this->assertNot('unknown_type', self::check($type, 11, 1000), 'type ' . $type);
        }
        $this->assertNot('unknown_type', self::check('unknown', 3, 1000), 'unknown comes before the hard fork');
        $this->assertNot('transfer_with_conversion_fields', self::check('transfer', 11, 5, 0), 'burnt');
        $this->assertNot('transfer_with_conversion_fields', self::check('transfer', 11, 0, 5), 'minted');
        $this->assertNot('transfer_with_conversion_fields', self::check('transfer', 11, 0, 0, null, 998), 'a record height');
    }

    public function testNeedsCheckIsEverythingButAPlainTransfer(): void
    {
        $this->assertFalse(ConversionRule::needsCheck('transfer', 0, 0, 0));
        $this->assertFalse(ConversionRule::needsCheck('transfer', '0', '0', '0'));
        $this->assertTrue(ConversionRule::needsCheck('transfer', 1, 0, 0));
        $this->assertTrue(ConversionRule::needsCheck('transfer', 0, 1, 0));
        $this->assertTrue(ConversionRule::needsCheck('transfer', 0, 0, 1));
        foreach (['mint_stable', 'audit', 'unknown'] as $type) {
            $this->assertTrue(ConversionRule::needsCheck($type, 0, 0, 0), $type);
        }
    }

    public function testValuesThatCannotBeHandledNeverThrow(): void
    {
        foreach ([-1, 'abc', '', '1e5', '01', '-5', ' 5', '5.0', str_repeat('9', 60)] as $bad) {
            $this->assertNot('invalid_value', self::check('redeem_stable', 11, $bad), 'burnt ' . var_export($bad, true));
            $this->assertNot('invalid_value', self::check('redeem_stable', 11, 1000, $bad), 'minted ' . var_export($bad, true));
            $this->assertNot('invalid_value', self::check('redeem_stable', 11, 1000, 1, self::RECORD, $bad), 'prh ' . var_export($bad, true));
        }
        $this->assertNot('invalid_value', self::check('redeem_stable', 11, 1000, 1, self::record(['stable' => 'x'])), 'a record field');
        $this->assertNot('invalid_value', self::check('redeem_stable', 11, 1000, 1, self::record(['stable' => -1])), 'a negative record field');
        $partial = self::RECORD;
        unset($partial['yield_price']);
        $this->assertNot('invalid_value', self::check('redeem_stable', 11, 1000, 1, $partial), 'a record without a field');
        $this->assertNot('invalid_value', self::check('redeem_stable', 11, 1000, 1, self::RECORD, 998, -5), 'a negative height');
        // A number that does not fit 64 bits is not accepted, even as a string.
        $this->assertNot('invalid_value', self::check('redeem_stable', 11, '340282366920938463463374607431768211455'), 'a 128-bit amount');
    }

    // --- the admission conditions of rule version 2 -------------------------------------------

    public function testZeroAmountsAreNotRecomputedForEveryConversionAndAudit(): void
    {
        foreach ([3, 4, 5, 6, 7, 11] as $hf) {
            foreach (['mint_stable', 'redeem_stable', 'mint_reserve', 'redeem_reserve'] as $type) {
                $this->assertNot('invalid_value', self::check($type, $hf, 0, 1), $type . ' hf ' . $hf . ' burnt 0');
                $this->assertNot('invalid_value', self::check($type, $hf, 1000, 0), $type . ' hf ' . $hf . ' minted 0');
                $this->assertNot('invalid_value', self::check($type, $hf, 0, 0), $type . ' hf ' . $hf . ' both 0');
            }
        }
        foreach (['mint_yield', 'redeem_yield'] as $type) {
            foreach ([6, 7, 11] as $hf) {
                $this->assertNot('invalid_value', self::check($type, $hf, 0, 1), $type . ' hf ' . $hf);
                $this->assertNot('invalid_value', self::check($type, $hf, 1000, 0), $type . ' hf ' . $hf);
            }
        }
        foreach ([8, 10] as $hf) {
            $old = Generation::Old;
            $new = Generation::New;
            $this->assertNot('invalid_value', ConversionRule::check('audit', $hf, 481502, 0, 0, 5, null, $old, $new), 'audit burnt 0 hf ' . $hf);
            $this->assertNot('invalid_value', ConversionRule::check('audit', $hf, 481502, 0, 5, 0, null, $old, $new), 'audit minted 0 hf ' . $hf);
            $this->assertNot('invalid_value', ConversionRule::check('audit', $hf, 481502, 0, '0', '0', null, $old, $new), 'audit both 0 hf ' . $hf);
            $this->assertSame('checked', ConversionRule::check('audit', $hf, 481502, 0, 1, 1, null, $old, $new)->reason, 'audit 1 hf ' . $hf);
        }
        // The smallest amounts that are not zero are computed (and here a mismatch).
        $this->assertSame('checked', self::check('redeem_stable', 11, 1, 1)->reason);
        // A burnt amount whose computed amount is 0 is a mismatch against a minted amount of 1,
        // and a minted amount of 0 is refused before that.
        $r = self::check('mint_stable', 11, 1, 1);
        $this->assertSame([ConversionResult::MISMATCH, '0'], [$r->verdict, $r->expected]);
        $this->assertNot('invalid_value', self::check('mint_stable', 11, 1, 0), 'burnt 1 minted 0');
    }

    public function testAnAuditNamesNoPricingRecordHeight(): void
    {
        foreach ([8, 10] as $hf) {
            foreach ([1, 5, 481500, '18446744073709551615'] as $prh) {
                $r = ConversionRule::check('audit', $hf, 481502, $prh, 7, 7, null, Generation::Old, Generation::New);
                $this->assertNot('invalid_value', $r, 'audit hf ' . $hf . ' prh ' . $prh);
            }
            $r = ConversionRule::check('audit', $hf, 481502, '0', 7, 7, null, Generation::Old, Generation::New);
            $this->assertSame('checked', $r->reason, 'audit hf ' . $hf . ' prh 0');
        }
    }

    public function testNamesByHardForkAtTheirBoundaries(): void
    {
        $old = Generation::Old;
        $new = Generation::New;
        $call = static fn (string $type, int $hf, Generation $s, Generation $d): ConversionResult
            => ConversionRule::check($type, $hf, 1000, 998, 1000, 1, self::RECORD, $s, $d);
        // Hard fork 7 and 8: a new name is refused up to 7.
        $this->assertSame('checked', $call('redeem_stable', 7, $old, $old)->reason);
        $this->assertNot('type_not_allowed', $call('redeem_stable', 7, $new, $new), 'new names at hf 7');
        $this->assertNot('type_not_allowed', $call('redeem_stable', 7, $old, $new), 'a new destination at hf 7');
        $this->assertNot('type_not_allowed', $call('redeem_stable', 6, $new, $old), 'a new source at hf 6');
        $this->assertNot('type_not_allowed', $call('mint_yield', 7, $new, $new), 'yield with new names at hf 7');
        // Hard fork 10 and 11: an old name is refused from 11.
        $this->assertSame('checked', $call('redeem_stable', 11, $new, $new)->reason);
        $this->assertNot('type_not_allowed', $call('redeem_stable', 11, $old, $old), 'old names at hf 11');
        $this->assertNot('type_not_allowed', $call('redeem_stable', 11, $old, $new), 'an old source at hf 11');
        $this->assertNot('type_not_allowed', $call('redeem_stable', 11, $new, $old), 'an old destination at hf 11');
        $this->assertNot('type_not_allowed', $call('mint_yield', 11, $old, $old), 'yield with old names at hf 11');
        // Hard forks 8 to 10 allow both spellings, and an audit goes from an old name to a new one.
        foreach ([8, 9, 10] as $hf) {
            $this->assertTrue(ConversionRule::namesAllowed($old, $new, $hf), 'old to new at hf ' . $hf);
            $this->assertTrue(ConversionRule::namesAllowed($old, $old, $hf), 'old at hf ' . $hf);
            $this->assertTrue(ConversionRule::namesAllowed($new, $new, $hf), 'new at hf ' . $hf);
        }
        foreach ([8, 10] as $hf) {
            $this->assertSame('checked', ConversionRule::check('audit', $hf, 481502, 0, 5, 5, null, $old, $new)->reason, 'audit hf ' . $hf);
        }
        // An audit is never allowed from 11, whatever it is called.
        $this->assertNot('type_not_allowed', ConversionRule::check('audit', 11, 481502, 0, 5, 5, null, $old, $new), 'audit hf 11');
        $this->assertNot('type_not_allowed', ConversionRule::check('audit', 7, 481502, 0, 5, 5, null, $old, $new), 'audit hf 7');
        // A name the chain does not know is never allowed.
        $unknown = Generation::Unknown;
        $this->assertNot('type_not_allowed', $call('redeem_stable', 11, $unknown, $new), 'unknown source');
        $this->assertNot('type_not_allowed', $call('redeem_stable', 7, $old, $unknown), 'unknown destination');
    }

    public function testNoHardForkAboveTheHighestKnownOne(): void
    {
        $this->assertSame(11, ConversionRule::MAX_HARD_FORK);
        $this->assertSame('checked', self::check('redeem_stable', 11, 1000, 1)->reason);
        foreach ([12, 13, 100, 255] as $hf) {
            $this->assertNot('type_not_allowed', self::check('redeem_stable', $hf, 1000, 1), 'hf ' . $hf);
            $this->assertNot('type_not_allowed', self::check('mint_yield', $hf, 1000, 1), 'hf ' . $hf);
            $this->assertNot('type_not_allowed', ConversionRule::check('audit', $hf, 1000, 0, 5, 5, null, Generation::Old, Generation::New), 'audit hf ' . $hf);
        }
    }

    public function testBelowBlockHeightTenNoPricingRecordHeightIsAccepted(): void
    {
        $this->assertTrue(ConversionRule::ageValid(10, 9));
        $this->assertTrue(ConversionRule::ageValid(10, 0));
        $this->assertFalse(ConversionRule::ageValid(9, 8));
        $this->assertFalse(ConversionRule::ageValid(9, 0));
        $this->assertFalse(ConversionRule::ageValid(1, 0));
        $this->assertFalse(ConversionRule::ageValid(10, 10));
        $this->assertFalse(ConversionRule::ageValid(21, 10));
        $this->assertTrue(ConversionRule::ageValid(21, 11));
        $this->assertSame('checked', self::check('mint_stable', 3, 1000, 1, self::RECORD, 9, 10)->reason, 'height 10, record 9');
        $this->assertSame('checked', self::check('mint_stable', 3, 1000, 1, self::RECORD, 0, 10)->reason, 'height 10, record 0 (age 10)');
        $this->assertNot('age_out_of_window', self::check('mint_stable', 3, 1000, 1, self::RECORD, 8, 9), 'height 9, record 8 (age 1)');
        $this->assertNot('age_out_of_window', self::check('mint_stable', 3, 1000, 1, self::RECORD, 0, 9), 'height 9, record 0');
        $this->assertNot('age_out_of_window', self::check('mint_stable', 3, 1000, 1, self::RECORD, 0, 1), 'height 1, record 0');
        $this->assertNot('age_out_of_window', self::check('mint_stable', 3, 1000, 1, self::RECORD, 0, 0), 'height 0');
    }

    public function testAnAmountOfTwoToThe64IsInvalidAndSoIsAResultOfTwoToThe64(): void
    {
        $max = '18446744073709551615'; // 2^64 - 1
        $limit = '18446744073709551616'; // 2^64
        $this->assertSame($limit, ConversionRule::UINT64_LIMIT);
        // Inputs.
        $this->assertSame('checked', self::check('redeem_yield', 11, 1000, $max)->reason, 'minted 2^64 - 1');
        $this->assertNot('invalid_value', self::check('redeem_yield', 11, 1000, $limit), 'minted 2^64');
        $this->assertSame('checked', self::check('mint_yield', 11, $max, 1)->reason, 'burnt 2^64 - 1');
        $this->assertNot('invalid_value', self::check('mint_yield', 11, $limit, 1), 'burnt 2^64');
        $this->assertNot('invalid_value', self::check('redeem_yield', 11, 1000, 1, self::RECORD, $limit), 'record height 2^64');
        $this->assertNot('invalid_value', self::check('redeem_yield', 11, 1000, 1, self::record(['yield_price' => $limit])), 'a record field 2^64');
        // The computed amount: redeem_yield at hard fork 11 has the rate 1993238480000 (the
        // price 1995233720000 less the fee and the rounding), so the amount follows burnt * rate /
        // 10^12; around the smallest burnt that gives 2^64 or more the answer flips.
        $rate = gmp_init('1993238480000');
        $edge = gmp_div_q(gmp_mul(gmp_pow(2, 64), gmp_pow(10, 12)), $rate); // rounded down
        for ($d = -2; $d <= 2; $d++) {
            $burnt = gmp_add($edge, $d);
            $exact = gmp_div_q(gmp_mul($burnt, $rate), gmp_pow(10, 12));
            $r = self::check('redeem_yield', 11, gmp_strval($burnt), 1);
            if (gmp_cmp($exact, gmp_pow(2, 64)) >= 0) {
                $this->assertNot('invalid_value', $r, 'burnt ' . gmp_strval($burnt));
            } else {
                $this->assertSame('checked', $r->reason, 'burnt ' . gmp_strval($burnt));
                $this->assertSame(gmp_strval($exact), $r->expected, 'burnt ' . gmp_strval($burnt));
            }
        }
        $this->assertTrue(gmp_cmp(gmp_div_q(gmp_mul(gmp_sub($edge, 2), $rate), gmp_pow(10, 12)), gmp_pow(2, 64)) < 0, 'the loop starts below the edge');
        $this->assertTrue(gmp_cmp(gmp_div_q(gmp_mul(gmp_add($edge, 2), $rate), gmp_pow(10, 12)), gmp_pow(2, 64)) >= 0, 'and ends above it');
    }

    public function testEveryReasonOfTheListIsReachableAndTheListIsFixed(): void
    {
        $this->assertSame(
            ['checked', 'unknown_type', 'transfer_with_conversion_fields', 'type_not_allowed', 'age_out_of_window', 'pr_missing', 'pr_incomplete', 'invalid_value'],
            ConversionRule::REASONS,
        );
        $this->assertSame(2, ConversionRule::VERSION);
        $this->assertSame('1000000000000', ConversionRule::COIN);
        $this->assertSame('1' . str_repeat('0', 24), ConversionRule::RESERVE_NUMERATOR);
    }
}
