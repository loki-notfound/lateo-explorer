<?php

declare(strict_types=1);

namespace Lateo\Explorer\Indexer;

use Lateo\Explorer\Chain\Asset;

/**
 * Turns one entry of /get_transactions (asked with prune and decode_as_json) into a
 * Transaction. Anything that does not fit stops the indexer (IndexerException, kind
 * invalid-data); nothing is repaired. A well-formed transaction whose assets the daemon
 * would not accept as a pair gets the type "unknown", never a guess.
 *
 * The as_json member is a string that holds the daemon's own JSON of the transaction. It is
 * decoded with JSON_BIGINT_AS_STRING, like every answer of a node: a uint64 above the signed
 * 64-bit range stays an exact decimal string and is refused here (the indexer's rule of 2^63-1),
 * so a value is never rounded to a float.
 *
 * The type follows the daemon (cryptonote_core/cryptonote_tx_utils.cpp, v2.3.0):
 * - get_tx_asset_types (:384-476): the asset strings of all inputs must be one string, the
 *   source; the outputs carry one or two strings, one of them the source (then the other is
 *   the destination, or with one string the destination is the source); both must be one of
 *   the eight names;
 * - get_tx_type (:478-535): the pair gives the type, matched on the exact strings. Two
 *   generations are not mixed, except in the four audit pairs (an old name to the new name of
 *   the same asset), which are all typed "audit".
 *
 * Whether a transfer carries zero in pricing_record_height, amount_burnt and amount_minted
 * (the daemon's rule) is not enforced here: the values are stored as the chain has them.
 */
final class TransactionParser
{
    /** The pairs (source, destination) of the daemon that are not transfers, by type. */
    private const PAIRS = [
        'ZEPH>ZPH' => 'audit', 'ZEPHUSD>ZSD' => 'audit', 'ZEPHRSV>ZRS' => 'audit', 'ZYIELD>ZYS' => 'audit',
        'ZEPH>ZEPHUSD' => 'mint_stable', 'ZPH>ZSD' => 'mint_stable',
        'ZEPHUSD>ZEPH' => 'redeem_stable', 'ZSD>ZPH' => 'redeem_stable',
        'ZEPH>ZEPHRSV' => 'mint_reserve', 'ZPH>ZRS' => 'mint_reserve',
        'ZEPHRSV>ZEPH' => 'redeem_reserve', 'ZRS>ZPH' => 'redeem_reserve',
        'ZEPHUSD>ZYIELD' => 'mint_yield', 'ZSD>ZYS' => 'mint_yield',
        'ZYIELD>ZEPHUSD' => 'redeem_yield', 'ZYS>ZSD' => 'redeem_yield',
    ];

    /** Names of the same asset (a transfer is a pair of the same name). */
    private const KNOWN = ['ZEPH', 'ZEPHUSD', 'ZEPHRSV', 'ZYIELD', 'ZPH', 'ZSD', 'ZRS', 'ZYS'];

    /**
     * @param mixed $entry one member of the "txs" list of the answer
     * @param string $expectedHash the hash that was asked for
     * @throws IndexerException
     */
    public static function parse(mixed $entry, string $expectedHash, int $height, int $idx): Transaction
    {
        if (!is_array($entry) || array_is_list($entry)) {
            throw self::bad('a transaction entry is not an object');
        }
        if (($entry['tx_hash'] ?? null) !== $expectedHash) {
            throw self::bad('a transaction entry has another hash than the one asked for');
        }
        $asJson = $entry['as_json'] ?? null;
        if (!is_string($asJson) || $asJson === '') {
            throw self::bad('as_json is not a string');
        }
        try {
            $tx = json_decode($asJson, true, 64, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw self::bad('as_json is not valid JSON');
        }
        if (!is_array($tx) || array_is_list($tx)) {
            throw self::bad('as_json is not an object');
        }

        $vin = $tx['vin'] ?? null;
        $vout = $tx['vout'] ?? null;
        if (!is_array($vin) || !array_is_list($vin) || $vin === []) {
            throw self::bad('vin is not a non-empty list');
        }
        if (!is_array($vout) || !array_is_list($vout) || $vout === []) {
            throw self::bad('vout is not a non-empty list');
        }
        $rct = $tx['rct_signatures'] ?? null;
        if (!is_array($rct) || array_is_list($rct)) {
            throw self::bad('rct_signatures is not an object');
        }

        [$source, $destination] = self::assets($vin, $vout);
        $type = self::type($source, $destination);

        return new Transaction(
            hash: $expectedHash,
            height: $height,
            idx: $idx,
            version: self::uint($tx, 'version', 255),
            rctType: self::uint($rct, 'type', 255, 'rct_signatures.type'),
            source: Asset::fromChain($source ?? ''),
            destination: Asset::fromChain($destination ?? ''),
            type: $type,
            inputs: count($vin),
            outputs: count($vout),
            fee: self::uint($rct, 'txnFee', label: 'rct_signatures.txnFee'),
            pricingRecordHeight: self::uint($tx, 'pricing_record_height'),
            amountBurnt: self::uint($tx, 'amount_burnt'),
            amountMinted: self::uint($tx, 'amount_minted'),
        );
    }

    /**
     * The source and destination strings the daemon derives, or null for one that cannot be
     * derived (several source strings, an input of another kind, three output strings, no
     * source among the outputs, a conversion without change). The strings are untrusted and
     * go through Asset::fromChain by the caller; an unsupported string maps to "unknown".
     *
     * @param list<mixed> $vin
     * @param list<mixed> $vout
     * @return array{0:?string,1:?string}
     */
    private static function assets(array $vin, array $vout): array
    {
        $sources = [];
        $sourceOk = true;
        foreach ($vin as $input) {
            if (!is_array($input) || array_is_list($input) || count($input) !== 1) {
                throw self::bad('an input is not an object with one variant');
            }
            $variant = array_key_first($input);
            $body = $input[$variant];
            if (!is_array($body) || array_is_list($body)) {
                throw self::bad('an input variant is not an object');
            }
            if ($variant !== 'key') {
                $sourceOk = false; // a miner input or a script input in a regular transaction
                continue;
            }
            self::uint($body, 'amount', label: 'input amount');
            $asset = $body['asset_type'] ?? null;
            if (!is_string($asset)) {
                $sourceOk = false;
                continue;
            }
            $sources[$asset] = true;
        }

        $outputs = [];
        $outputOk = true;
        foreach ($vout as $output) {
            if (!is_array($output) || array_is_list($output)) {
                throw self::bad('an output is not an object');
            }
            self::uint($output, 'amount', label: 'output amount');
            $target = $output['target'] ?? null;
            if (!is_array($target) || array_is_list($target) || count($target) !== 1) {
                throw self::bad('an output target is not an object with one variant');
            }
            $body = $target[array_key_first($target)];
            if (!is_array($body) || array_is_list($body)) {
                throw self::bad('an output target variant is not an object');
            }
            $asset = $body['asset_type'] ?? null;
            if (array_key_first($target) !== 'tagged_key' || !is_string($asset)) {
                $outputOk = false;
                continue;
            }
            $outputs[$asset] = true;
        }

        $source = ($sourceOk && count($sources) === 1) ? (string) array_key_first($sources) : null;
        if ($source === null || !$outputOk) {
            return [$source, null];
        }
        $names = array_map('strval', array_keys($outputs));
        $destination = match (count($names)) {
            1 => $names[0] === $source ? $source : null,
            2 => $names[0] === $source ? $names[1] : ($names[1] === $source ? $names[0] : null),
            default => null,
        };
        return [$source, $destination];
    }

    /** The tx_type of a pair of strings, "unknown" when the daemon has no type for it. */
    private static function type(?string $source, ?string $destination): string
    {
        if ($source === null || $destination === null
            || !in_array($source, self::KNOWN, true) || !in_array($destination, self::KNOWN, true)) {
            return 'unknown';
        }
        if ($source === $destination) {
            return 'transfer';
        }
        return self::PAIRS[$source . '>' . $destination] ?? 'unknown';
    }

    /** @param array<string,mixed> $o */
    private static function uint(array $o, string $key, int $max = PHP_INT_MAX, ?string $label = null): int
    {
        $label ??= $key;
        $v = $o[$key] ?? null;
        if (is_string($v)) {
            throw self::bad($label . ' does not fit a signed 64-bit integer');
        }
        if (!is_int($v) || $v < 0 || $v > $max) {
            throw self::bad($label . ' is missing or out of range');
        }
        return $v;
    }

    private static function bad(string $detail): IndexerException
    {
        return new IndexerException(FailureKind::InvalidData, $detail);
    }
}
