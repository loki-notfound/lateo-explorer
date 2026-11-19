<?php

declare(strict_types=1);

namespace Lateo\Explorer\Chain;

/**
 * The recomputation of a conversion from its pricing record, without keys and without I/O.
 *
 * All arithmetic is exact (GMP): COIN = 10^12, R = 10^24, nothing is limited to a fixed width.
 * Given the type of the transaction, the hard fork of its block (the block's major_version),
 * the height of that block, the pricing_record_height and the burnt and minted amounts of the
 * transaction and the stored pricing record at that height (nine fields, or null), check()
 * gives a verdict:
 *
 *   r0 (before the fee) by type, with the record's fields:
 *     mint_stable    R / max(stable, stable_ma)        redeem_stable   min(stable, stable_ma)
 *     mint_reserve   R / max(reserve, reserve_ma)      redeem_reserve  min(reserve, reserve_ma)
 *     mint_yield     R / yield_price                   redeem_yield    yield_price
 *     audit          COIN, fee 0, no record
 *   fee by hard fork:
 *     hf 3 and 4  stable mint and redeem (r0 * 2) / 100; reserve mint 0;
 *                 reserve redeem (r0 * 2) / 100
 *     hf 5        stable r0 / 1000; reserve r0 / 100
 *     hf >= 6     stable r0 / 1000; reserve r0 / 100; yield r0 / 1000
 *   rate = r0 - fee; rate = rate - (rate mod 10000)
 *   expected = (burnt * rate) / COIN (rounded down), remainder = (burnt * rate) mod COIN
 *   match <=> expected == minted
 *
 * Conditions of admission, none of them a mismatch (verdict not_recomputed with a reason):
 * the type is unknown, a transfer carries conversion fields, the type is not allowed in that
 * hard fork, the age (height - pricing_record_height) is not 1 to 10, the record is missing,
 * the record lacks a rate the hard fork needs (pricing_record.cpp has_missing_rates), or a
 * value cannot be handled at all. They are tested in that order. check() never throws on
 * values from the chain.
 *
 * The rule was read against the daemon 2.3.0 (ringct/rctSigs.cpp: verRctSemanticsZeph from
 * hard fork 6, validateMintedAmount for hard forks 3 to 5; oracle/pricing_record.cpp
 * has_missing_rates; cryptonote_core/cryptonote_tx_utils.cpp tx_pr_height_valid for the age
 * window). The result is never worded as a finding; a mismatch is only recorded.
 */
final class ConversionRule
{
    /** Bumped when the rule or the list of reasons changes in meaning. */
    public const VERSION = 1;

    public const COIN = '1000000000000';
    public const RESERVE_NUMERATOR = '1000000000000000000000000'; // 10^24
    public const ROUNDING_MODULUS = '10000';
    public const MIN_AGE = 1;
    public const MAX_AGE = 10;

    public const CHECKED = 'checked';
    public const REASONS = [
        self::CHECKED,
        'unknown_type',
        'transfer_with_conversion_fields',
        'type_not_allowed',
        'age_out_of_window',
        'pr_missing',
        'pr_incomplete',
        'invalid_value',
    ];

    public const PRICE_FIELDS = ['stable', 'stable_ma', 'reserve', 'reserve_ma', 'yield_price'];

    /** The nine integer fields of a record the rule reads. */
    public const RECORD_FIELDS = [
        'spot', 'moving_average', 'stable', 'stable_ma', 'reserve', 'reserve_ma',
        'reserve_ratio', 'reserve_ratio_ma', 'yield_price',
    ];

    /** The types the rule computes (audits separately). */
    public const CONVERSIONS = [
        'mint_stable', 'redeem_stable', 'mint_reserve', 'redeem_reserve', 'mint_yield', 'redeem_yield',
    ];

    /**
     * Whether a stored transaction needs a check at all: everything but an ordinary transfer
     * (a transfer with nothing in the conversion fields).
     */
    public static function needsCheck(string $type, int|string $burnt, int|string $minted, int|string $pricingRecordHeight): bool
    {
        if ($type !== 'transfer') {
            return true;
        }
        return (string) $burnt !== '0' || (string) $minted !== '0' || (string) $pricingRecordHeight !== '0';
    }

    /** Whether a type may appear in a block of this hard fork. */
    public static function allowed(string $type, int $hardFork): bool
    {
        if ($type === 'audit') {
            return $hardFork === 8 || $hardFork === 10;
        }
        if (!in_array($type, self::CONVERSIONS, true)) {
            return false;
        }
        if ($hardFork < 3 || ($hardFork >= 8 && $hardFork <= 10)) {
            return false;
        }
        if (($type === 'mint_yield' || $type === 'redeem_yield') && $hardFork < 6) {
            return false;
        }
        return true;
    }

    /**
     * Whether the record has every rate the hard fork needs (has_missing_rates is false).
     *
     * @param array<string,int|string> $record
     */
    public static function recordComplete(array $record, int $hardFork): bool
    {
        $needed = ['spot', 'moving_average', 'stable', 'stable_ma', 'reserve', 'reserve_ma'];
        if ($hardFork >= 4) {
            $needed[] = 'reserve_ratio';
        }
        if ($hardFork >= 5) {
            $needed[] = 'reserve_ratio_ma';
        }
        if ($hardFork >= 6) {
            $needed[] = 'yield_price';
        }
        foreach ($needed as $field) {
            $v = self::number($record[$field] ?? null);
            if ($v === null || gmp_cmp($v, 0) === 0) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string,int|string>|null $record the nine fields of the record at $pricingRecordHeight, null when none is stored
     */
    public static function check(
        string $type,
        int $hardFork,
        int $height,
        int|string $pricingRecordHeight,
        int|string $amountBurnt,
        int|string $amountMinted,
        ?array $record,
    ): ConversionResult {
        $burnt = self::number($amountBurnt);
        $minted = self::number($amountMinted);
        $prh = self::number($pricingRecordHeight);
        if ($burnt === null || $minted === null || $prh === null || $height < 0) {
            return self::not('invalid_value');
        }
        if ($type === 'unknown' || !in_array($type, [...self::CONVERSIONS, 'audit', 'transfer'], true)) {
            return self::not('unknown_type');
        }
        if ($type === 'transfer') {
            // Only a transfer with something in the conversion fields gets here.
            return self::not('transfer_with_conversion_fields');
        }
        if (!self::allowed($type, $hardFork)) {
            return self::not('type_not_allowed');
        }
        if ($type === 'audit') {
            return self::finish($burnt, $minted, gmp_init(self::COIN), gmp_init(0), null, null);
        }

        $age = gmp_sub($height, $prh);
        if (gmp_cmp($age, self::MIN_AGE) < 0 || gmp_cmp($age, self::MAX_AGE) > 0) {
            return self::not('age_out_of_window');
        }
        if ($record === null) {
            return self::not('pr_missing');
        }
        $values = [];
        foreach (self::RECORD_FIELDS as $field) {
            $v = self::number($record[$field] ?? null);
            if ($v === null) {
                return self::not('invalid_value');
            }
            $values[$field] = $v;
        }
        if (!self::recordComplete($record, $hardFork)) {
            return self::not('pr_incomplete');
        }

        $reserveNumerator = gmp_init(self::RESERVE_NUMERATOR);
        switch ($type) {
            case 'mint_stable':
                [$field, $price] = self::pick($values, 'stable', 'stable_ma', true);
                $r0 = gmp_div_q($reserveNumerator, $price);
                break;
            case 'redeem_stable':
                [$field, $price] = self::pick($values, 'stable', 'stable_ma', false);
                $r0 = $price;
                break;
            case 'mint_reserve':
                [$field, $price] = self::pick($values, 'reserve', 'reserve_ma', true);
                $r0 = gmp_div_q($reserveNumerator, $price);
                break;
            case 'redeem_reserve':
                [$field, $price] = self::pick($values, 'reserve', 'reserve_ma', false);
                $r0 = $price;
                break;
            case 'mint_yield':
                $field = 'yield_price';
                $price = $values['yield_price'];
                $r0 = gmp_div_q($reserveNumerator, $price);
                break;
            default: // redeem_yield
                $field = 'yield_price';
                $price = $values['yield_price'];
                $r0 = $price;
                break;
        }
        return self::finish($burnt, $minted, $r0, self::fee($type, $hardFork, $r0), $field, $price);
    }

    /** The fee of the rule's table, for a type that is allowed in that hard fork. */
    private static function fee(string $type, int $hardFork, \GMP $r0): \GMP
    {
        $family = match ($type) {
            'mint_stable', 'redeem_stable' => 'stable',
            'mint_reserve', 'redeem_reserve' => 'reserve',
            default => 'yield',
        };
        if ($hardFork <= 4) {
            if ($type === 'mint_reserve') {
                return gmp_init(0);
            }
            return gmp_div_q(gmp_mul($r0, 2), 100);
        }
        return match ($family) {
            'reserve' => gmp_div_q($r0, 100),
            default => gmp_div_q($r0, 1000),
        };
    }

    /**
     * The larger (or smaller) of two fields and the name of the one it came from; on a tie the
     * first (the spot-derived one, not the moving average).
     *
     * @param array<string,\GMP> $values
     * @return array{0:string,1:\GMP}
     */
    private static function pick(array $values, string $first, string $second, bool $max): array
    {
        $cmp = gmp_cmp($values[$first], $values[$second]);
        $useFirst = $max ? $cmp >= 0 : $cmp <= 0;
        return $useFirst ? [$first, $values[$first]] : [$second, $values[$second]];
    }

    private static function finish(\GMP $burnt, \GMP $minted, \GMP $r0, \GMP $fee, ?string $field, ?\GMP $price): ConversionResult
    {
        $rate = gmp_sub($r0, $fee);
        $rate = gmp_sub($rate, gmp_mod($rate, self::ROUNDING_MODULUS));
        $product = gmp_mul($burnt, $rate);
        [$expected, $remainder] = gmp_div_qr($product, self::COIN);
        return new ConversionResult(
            gmp_cmp($expected, $minted) === 0 ? ConversionResult::MATCH : ConversionResult::MISMATCH,
            self::CHECKED,
            $field,
            $price === null ? null : gmp_strval($price),
            gmp_strval($r0),
            gmp_strval($fee),
            gmp_strval($rate),
            gmp_strval($expected),
            gmp_strval($remainder),
        );
    }

    private static function not(string $reason): ConversionResult
    {
        return new ConversionResult(ConversionResult::NOT_RECOMPUTED, $reason);
    }

    /** A non-negative integer as a GMP number, or null for anything else. */
    private static function number(mixed $value): ?\GMP
    {
        if (is_int($value)) {
            return $value >= 0 ? gmp_init($value) : null;
        }
        if (is_string($value) && preg_match('/\A(0|[1-9][0-9]{0,40})\z/', $value) === 1) {
            return gmp_init($value);
        }
        return null;
    }
}
