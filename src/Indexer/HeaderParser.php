<?php

declare(strict_types=1);

namespace Lateo\Explorer\Indexer;

/**
 * Checks one block header of a node's answer and turns it into a Header. Anything that does
 * not fit stops the indexer (IndexerException, kind invalid-data); nothing is repaired.
 *
 * The `rewards` member of a block header is ignored: the daemon declares it
 * (block_header_response, core_rpc_server_commands_defs.h) but never fills it
 * (core_rpc_server.cpp), so there is nothing to store. `reward` is the sum of the outputs of
 * the miner transaction.
 *
 * The answer was decoded with JSON_BIGINT_AS_STRING, so an integer above the signed 64-bit
 * range arrives as a string and is refused here, rather than stored wrongly.
 */
final class HeaderParser
{
    /** The fields of a pricing record that are integers (oracle::pricing_record). */
    public const PRICING_FIELDS = [
        'spot', 'moving_average', 'stable', 'stable_ma', 'reserve', 'reserve_ma',
        'reserve_ratio', 'reserve_ratio_ma', 'yield_price', 'timestamp',
    ];

    private const HASH = '/\A[0-9a-f]{64}\z/';
    private const SIGNATURE = '/\A[0-9a-f]{128}\z/';
    private const WIDE = '/\A0x[0-9a-f]{1,32}\z/';

    /** @throws IndexerException */
    public static function parse(mixed $h): Header
    {
        if (!is_array($h) || array_is_list($h)) {
            throw self::bad('a header is not an object');
        }
        if (($h['orphan_status'] ?? false) !== false) {
            throw self::bad('a header of an orphaned block');
        }
        $difficulty = self::wide($h, 'wide_difficulty', 'difficulty', 'difficulty_top64');
        $cumulative = self::wide($h, 'wide_cumulative_difficulty', 'cumulative_difficulty', 'cumulative_difficulty_top64');

        return new Header(
            height: self::uint($h, 'height'),
            hash: self::hash($h, 'hash'),
            prevHash: self::hash($h, 'prev_hash'),
            timestamp: self::uint($h, 'timestamp'),
            majorVersion: self::uint($h, 'major_version', 255),
            minorVersion: self::uint($h, 'minor_version', 255),
            nonce: self::uint($h, 'nonce', 4294967295),
            difficulty: $difficulty,
            cumulativeDifficulty: $cumulative,
            reward: self::uint($h, 'reward'),
            blockSize: self::uint($h, 'block_size'),
            blockWeight: self::uint($h, 'block_weight'),
            longTermWeight: self::uint($h, 'long_term_weight'),
            numTxes: self::uint($h, 'num_txes'),
            minerTxHash: self::hash($h, 'miner_tx_hash'),
            pricing: self::pricing($h['pricing_record'] ?? null),
        );
    }

    /**
     * Decimal text of a "0x..." hex number of up to 128 bits (no GMP needed).
     */
    public static function hexToDecimal(string $hex): string
    {
        if (preg_match('/\A(?:0x)?([0-9a-f]+)\z/', $hex, $m) !== 1) {
            throw new \InvalidArgumentException('not a hex number');
        }
        $digits = [0]; // decimal digits, least significant first
        foreach (str_split($m[1]) as $c) {
            $carry = hexdec($c);
            foreach ($digits as $i => $d) {
                $v = $d * 16 + $carry;
                $digits[$i] = $v % 10;
                $carry = intdiv($v, 10);
            }
            while ($carry > 0) {
                $digits[] = $carry % 10;
                $carry = intdiv($carry, 10);
            }
        }
        return implode('', array_reverse($digits));
    }

    /** @return array<string,int|string>|null */
    private static function pricing(mixed $p): ?array
    {
        if (!is_array($p) || array_is_list($p)) {
            throw self::bad('pricing_record is not an object');
        }
        $out = [];
        $allZero = true;
        foreach (self::PRICING_FIELDS as $field) {
            $out[$field] = self::uint($p, $field, label: 'pricing_record.' . $field);
            $allZero = $allZero && $out[$field] === 0;
        }
        $sig = $p['signature'] ?? null;
        if (!is_string($sig) || preg_match(self::SIGNATURE, $sig) !== 1) {
            throw self::bad('pricing_record.signature is not 128 lower-case hex characters');
        }
        $out['signature'] = $sig;
        // An all-zero record (blocks before height 89300) is not stored.
        return $allZero && trim($sig, '0') === '' ? null : $out;
    }

    /**
     * A 128-bit number sent twice: as "0x" hex (wide) and as two uint64 (low and top). They
     * must agree. The result is the decimal text.
     *
     * @param array<string,mixed> $h
     */
    private static function wide(array $h, string $wideKey, string $lowKey, string $topKey): string
    {
        $wide = $h[$wideKey] ?? null;
        if (!is_string($wide) || preg_match(self::WIDE, $wide) !== 1) {
            throw self::bad($wideKey . ' is not a hex number of at most 128 bits');
        }
        $hex = str_pad(substr($wide, 2), 32, '0', STR_PAD_LEFT);
        $low = self::decimalOrNull($h[$lowKey] ?? null);
        $top = self::decimalOrNull($h[$topKey] ?? null);
        if ($low === null || $top === null
            || $low !== self::hexToDecimal(substr($hex, 16))
            || $top !== self::hexToDecimal(substr($hex, 0, 16))) {
            throw self::bad($wideKey . ' does not match ' . $lowKey . ' and ' . $topKey);
        }
        return self::hexToDecimal($hex);
    }

    /** A uint64 as decimal text (it may have arrived as an integer or as a big string). */
    private static function decimalOrNull(mixed $v): ?string
    {
        if (is_int($v) && $v >= 0) {
            return (string) $v;
        }
        if (is_string($v) && preg_match('/\A(?:0|[1-9][0-9]{0,19})\z/', $v) === 1) {
            return $v;
        }
        return null;
    }

    /**
     * @param array<string,mixed> $h
     */
    private static function uint(array $h, string $key, int $max = PHP_INT_MAX, ?string $label = null): int
    {
        $label ??= $key;
        $v = $h[$key] ?? null;
        if (is_string($v)) {
            throw self::bad($label . ' does not fit a signed 64-bit integer');
        }
        if (!is_int($v) || $v < 0 || $v > $max) {
            throw self::bad($label . ' is missing or out of range');
        }
        return $v;
    }

    /** @param array<string,mixed> $h */
    private static function hash(array $h, string $key): string
    {
        $v = $h[$key] ?? null;
        if (!is_string($v) || preg_match(self::HASH, $v) !== 1) {
            throw self::bad($key . ' is not 64 lower-case hex characters');
        }
        return $v;
    }

    private static function bad(string $detail): IndexerException
    {
        return new IndexerException(FailureKind::InvalidData, $detail);
    }
}
