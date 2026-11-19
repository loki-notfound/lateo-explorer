<?php

declare(strict_types=1);

namespace Lateo\Explorer\Indexer;

/**
 * A block header from get_block_headers_range or get_last_block_header, validated and ready
 * to store. Build it with HeaderParser::parse().
 */
final readonly class Header
{
    /**
     * @param string $difficulty decimal text of the 128-bit difficulty
     * @param string $cumulativeDifficulty decimal text of the 128-bit cumulative difficulty
     * @param array<string,int|string>|null $pricing the pricing record (the ten integer fields
     *        and "signature" as hex), or null for an all-zero record
     */
    public function __construct(
        public int $height,
        public string $hash,
        public string $prevHash,
        public int $timestamp,
        public int $majorVersion,
        public int $minorVersion,
        public int $nonce,
        public string $difficulty,
        public string $cumulativeDifficulty,
        public int $reward,
        public int $blockSize,
        public int $blockWeight,
        public int $longTermWeight,
        public int $numTxes,
        public string $minerTxHash,
        public ?array $pricing,
    ) {
    }
}
