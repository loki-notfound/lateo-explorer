<?php

declare(strict_types=1);

namespace Lateo\Explorer\Indexer;

/**
 * The shape of the miner transaction of one block, as the miner_txs table stores it: no
 * amounts, no keys, no unlock time, no extra.
 */
final readonly class MinerTransaction
{
    public function __construct(
        public int $height,
        /** The block's miner_tx_hash, 64 lower-case hex characters. */
        public string $hash,
        public int $version,
        /** 0 for version 2 and later (the daemon's type null) and for version 1 (no rct_signatures). */
        public int $rctType,
        public int $outputs,
        /** Outputs whose target variant is not tagged_key. */
        public int $untaggedOutputs,
    ) {
    }
}
