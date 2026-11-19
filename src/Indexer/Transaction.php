<?php

declare(strict_types=1);

namespace Lateo\Explorer\Indexer;

use Lateo\Explorer\Chain\Asset;

/** One validated transaction of a block, as the transactions table stores it. */
final readonly class Transaction
{
    /** The values of the tx_type column. */
    public const TYPES = [
        'transfer', 'mint_stable', 'redeem_stable', 'mint_reserve', 'redeem_reserve',
        'mint_yield', 'redeem_yield', 'audit', 'unknown',
    ];

    public function __construct(
        public string $hash,
        public int $height,
        /** Position in the block's tx_hashes, 0-based. */
        public int $idx,
        public int $version,
        public int $rctType,
        public Asset $source,
        public Asset $destination,
        /** One of TYPES. */
        public string $type,
        public int $inputs,
        public int $outputs,
        /** txnFee, in atomic units of the source asset. */
        public int $fee,
        public int $pricingRecordHeight,
        public int $amountBurnt,
        public int $amountMinted,
    ) {
    }
}
