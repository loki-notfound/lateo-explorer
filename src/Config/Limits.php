<?php

declare(strict_types=1);

namespace Lateo\Explorer\Config;

/** The bounds on how much work the explorer asks of the nodes. */
final readonly class Limits
{
    public function __construct(
        /** RPC attempts one page request may make, over all nodes. */
        public int $pageMaxCalls = 4,
        /** Calls to the fallback node per minute, over all processes. */
        public int $fallbackCallsPerMinute = 30,
        /** Headers per get_block_headers_range call of the indexer. */
        public int $headerBatch = 1000,
        /** Pause between two backfill calls, in seconds. */
        public float $backfillPauseSeconds = 2.0,
        /** Seconds one indexer run may work before it stops. */
        public int $runSeconds = 45,
        /** Deepest reorganisation the indexer undoes. */
        public int $reorgMaxDepth = 100,
        /** Blocks at the tip that are shown as not yet settled. */
        public int $unsettledDepth = 10,
        /** Web calls to the own nodes per minute, over all processes and page requests. */
        public int $webOwnCallsPerMinute = 60,
    ) {
    }
}
