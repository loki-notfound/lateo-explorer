<?php

declare(strict_types=1);

namespace Lateo\Explorer\Chain;

/**
 * What ConversionRule::check found for one transaction. Every number is a decimal string.
 * The numbers are null when the rule did not get as far as computing them (not_recomputed).
 */
final readonly class ConversionResult
{
    public const MATCH = 'match';
    public const MISMATCH = 'mismatch';
    public const NOT_RECOMPUTED = 'not_recomputed';

    public function __construct(
        /** match, mismatch or not_recomputed. */
        public string $verdict,
        /** 'checked' for match and mismatch, otherwise one of ConversionRule::REASONS. */
        public string $reason,
        /** stable, stable_ma, reserve, reserve_ma or yield_price; null for audits and not_recomputed. */
        public ?string $priceField = null,
        /** The value of that field. */
        public ?string $price = null,
        /** The rate before the fee. */
        public ?string $r0 = null,
        public ?string $fee = null,
        /** r0 - fee, rounded down to a multiple of 10^4. */
        public ?string $rate = null,
        /** floor(amount_burnt * rate / 10^12): what the chain should have minted. */
        public ?string $expected = null,
        /** (amount_burnt * rate) mod 10^12. */
        public ?string $remainder = null,
    ) {
    }

    public function isChecked(): bool
    {
        return $this->verdict !== self::NOT_RECOMPUTED;
    }
}
