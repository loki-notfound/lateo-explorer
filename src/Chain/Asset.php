<?php

declare(strict_types=1);

namespace Lateo\Explorer\Chain;

/**
 * An asset as named on chain, mapped to the canonical ticker.
 *
 * Asset strings come from transactions and are chosen by whoever made the transaction, so
 * they are untrusted input: a string that is not one of the eight names below becomes
 * "unknown" and is never echoed.
 *
 * The daemon (v2.3.0-sec.2-my.2, src/):
 * - old names "ZEPH", "ZEPHUSD", "ZEPHRSV", "ZYIELD" are rejected from hard fork 11 on:
 *   cryptonote_core/blockchain.cpp:3337-3346 (comment "Disallow all old asset types post audit");
 * - new names "ZPH", "ZSD", "ZRS", "ZYS" are rejected before the audit hard fork:
 *   cryptonote_core/blockchain.cpp:3326-3335 (comment "Disallow all new asset types before audit hardfork");
 * - both generations are accepted side by side in between, for example in
 *   cryptonote_core/cryptonote_tx_utils.cpp:496-526 (get_tx_type: "ZEPHRSV" || "ZRS",
 *   "ZYIELD" || "ZYS", "ZEPH"/"ZPH" and "ZEPHUSD"/"ZSD" pairs) and ringct/rctSigs.cpp:1281-1381;
 * - the ZYIELD string is rejected before hard fork 6: cryptonote_core/blockchain.cpp:3316-3324.
 */
final readonly class Asset
{
    public const UNKNOWN = 'unknown';

    /** The four canonical tickers. */
    public const TICKERS = ['ZPH', 'ZSD', 'ZRS', 'ZYS'];

    public function __construct(
        /** One of TICKERS, or "unknown". */
        public string $ticker,
        public Generation $generation,
    ) {
    }

    /** Map a string from the chain. Matching is exact: the daemon compares exactly too. */
    public static function fromChain(string $raw): self
    {
        return match ($raw) {
            'ZEPH' => new self('ZPH', Generation::Old),
            'ZEPHUSD' => new self('ZSD', Generation::Old),
            'ZEPHRSV' => new self('ZRS', Generation::Old),
            'ZYIELD' => new self('ZYS', Generation::Old),
            'ZPH' => new self('ZPH', Generation::New),
            'ZSD' => new self('ZSD', Generation::New),
            'ZRS' => new self('ZRS', Generation::New),
            'ZYS' => new self('ZYS', Generation::New),
            default => new self(self::UNKNOWN, Generation::Unknown),
        };
    }

    /** The string the chain used for this asset (the inverse of fromChain), null for an unknown one. */
    public function chainName(): ?string
    {
        $old = ['ZPH' => 'ZEPH', 'ZSD' => 'ZEPHUSD', 'ZRS' => 'ZEPHRSV', 'ZYS' => 'ZYIELD'];
        return match ($this->generation) {
            Generation::Old => $old[$this->ticker] ?? null,
            Generation::New => in_array($this->ticker, self::TICKERS, true) ? $this->ticker : null,
            Generation::Unknown => null,
        };
    }

    public function isKnown(): bool
    {
        return $this->ticker !== self::UNKNOWN;
    }
}
