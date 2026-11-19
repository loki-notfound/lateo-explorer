<?php

declare(strict_types=1);

namespace Lateo\Explorer\Web;

/**
 * The text formats of the house style (guide/WRITING.md, guide/DATA.md), as plain text. Nothing
 * here returns HTML and nothing uses floating point: amounts, ratios and prices are computed
 * from integers with GMP, so a figure is never rounded by a float.
 */
final class Format
{
    /** One whole unit of an asset or of the oracle's price scale: 10^12. */
    public const SCALE = 1_000_000_000_000;

    /** Beyond this many seconds the age is left out and the date alone stands. */
    public const AGE_LIMIT_SECONDS = 30 * 86400;

    /**
     * An integer with commas from five digits on ("910,657", "1024"), as the house style asks for
     * the interface. Copyable values (hashes, URLs) are never grouped.
     */
    public static function int(int|string $n): string
    {
        $s = (string) $n;
        $sign = '';
        if (str_starts_with($s, '-')) {
            $sign = '−'; // U+2212, the house style's minus
            $s = substr($s, 1);
        }
        if (preg_match('/\A[0-9]+\z/', $s) !== 1) {
            throw new \InvalidArgumentException('not an integer');
        }
        return $sign . (strlen($s) >= 5 ? self::group($s) : $s);
    }

    /**
     * An amount in atomic units (10^-12) with its ticker after the number, one space between.
     *
     * Detail (full): always 12 decimals. Compact: trailing zeros trimmed to at least 2 and at
     * most 4 decimals, rounded half up; a value that is not zero but would show as 0.00 keeps
     * its significant digits (12 decimals, trailing zeros trimmed, at least 2).
     */
    public static function amount(int|string $atomic, string $ticker, bool $full = true): string
    {
        return self::decimal($atomic, $full) . ' ' . $ticker;
    }

    /** The number part of amount(), without a ticker. */
    public static function decimal(int|string $atomic, bool $full = true): string
    {
        $v = self::nonNegative($atomic);
        if ($full) {
            return self::withDecimals($v, 12);
        }
        $step = gmp_pow(10, 8); // 10^-4 of a unit, in atomic units
        $rounded = gmp_mul(gmp_div_q(gmp_add($v, gmp_div_q($step, 2)), $step), $step);
        $dust = gmp_cmp($v, 0) !== 0 && gmp_cmp($rounded, 0) === 0;
        // Digits after the point: four, or all twelve for a value that four would show as zero.
        $text = self::withDecimals($dust ? $v : $rounded, 12);
        $dot = (int) strpos($text, '.');
        if (!$dust) {
            $text = substr($text, 0, $dot + 5);
        }
        $text = rtrim($text, '0');
        $missing = 2 - (strlen($text) - $dot - 1);
        return $missing > 0 ? $text . str_repeat('0', $missing) : $text;
    }

    /**
     * A ratio of the oracle's scale (10^12 is 100%) as a percentage with two decimals, rounded
     * half up: 1971054120000 becomes "197.11%".
     */
    public static function ratio(int|string $scaled): string
    {
        $hundredths = gmp_div_q(gmp_add(self::nonNegative($scaled), gmp_div_q(gmp_pow(10, 8), 2)), gmp_pow(10, 8));
        $s = gmp_strval($hundredths);
        $s = str_pad($s, 3, '0', STR_PAD_LEFT);
        return self::group(substr($s, 0, -2)) . '.' . substr($s, -2) . '%';
    }

    /**
     * A price of the oracle's scale in USD, the number part: 4 decimals compact, 12 in detail.
     * See decimal() for the rules of the compact form.
     */
    public static function price(int|string $scaled, bool $full = true): string
    {
        return self::decimal($scaled, $full);
    }

    /** `YYYY-MM-DD HH:MM:SS UTC` */
    public static function time(int $unix): string
    {
        return gmdate('Y-m-d H:i:s', $unix) . ' UTC';
    }

    /** The value of a time element's datetime attribute. */
    public static function isoTime(int $unix): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $unix);
    }

    /**
     * The age of $then at $now: "just now" (under 10 s), "45 s ago", "3 min ago", "2 h ago",
     * "5 d ago". Beyond 30 days it is the empty string: only the date stands. A time in the
     * future (clocks differ by a few seconds) is "just now".
     */
    public static function age(int $then, int $now): string
    {
        $d = $now - $then;
        if ($d < 10) {
            return 'just now';
        }
        if ($d < 60) {
            return $d . ' s ago';
        }
        if ($d < 3600) {
            return intdiv($d, 60) . ' min ago';
        }
        if ($d < 86400) {
            return intdiv($d, 3600) . ' h ago';
        }
        if ($d <= self::AGE_LIMIT_SECONDS) {
            return intdiv($d, 86400) . ' d ago';
        }
        return '';
    }

    /** A hash shortened to its first 8 and last 8 characters with an ellipsis between. */
    public static function hashShort(string $hash): string
    {
        return strlen($hash) <= 17 ? $hash : substr($hash, 0, 8) . '…' . substr($hash, -8);
    }

    /** Plain text cut to $max bytes of printable ASCII, other bytes shown as "?", then "…". */
    public static function shorten(string $text, int $max): string
    {
        $clean = (string) preg_replace('/[^\x20-\x7e]/', '?', $text);
        return strlen($clean) > $max ? substr($clean, 0, $max) . '…' : $clean;
    }

    /** Bytes with a unit-less count, grouped: "60,818 bytes". */
    public static function bytes(int $n): string
    {
        return self::int($n) . ' bytes';
    }

    /** Digits grouped by three with commas. */
    private static function group(string $digits): string
    {
        return strrev(implode(',', str_split(strrev($digits), 3)));
    }

    private static function withDecimals(\GMP $v, int $decimals): string
    {
        [$whole, $frac] = gmp_div_qr($v, gmp_pow(10, $decimals));
        $f = str_pad(gmp_strval($frac), $decimals, '0', STR_PAD_LEFT);
        return self::group(gmp_strval($whole)) . '.' . $f;
    }

    private static function nonNegative(int|string $n): \GMP
    {
        if (is_string($n) && preg_match('/\A(?:0|[1-9][0-9]*)\z/', $n) !== 1) {
            throw new \InvalidArgumentException('not a non-negative integer');
        }
        $v = gmp_init($n);
        if (gmp_cmp($v, 0) < 0) {
            throw new \InvalidArgumentException('negative amount');
        }
        return $v;
    }
}
