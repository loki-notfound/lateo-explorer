<?php

declare(strict_types=1);

namespace Lateo\Explorer\Web;

use Lateo\Explorer\Db\Database;

/**
 * Read access to what the indexer stored. Pages read here first. Every method is one short
 * query on a read-only connection.
 */
final class Index
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return array{height:int,hash:string,timestamp:int}|null the highest indexed block */
    public function top(): ?array
    {
        $row = $this->db->one('SELECT height, hash, timestamp FROM blocks ORDER BY height DESC LIMIT 1');
        return $row === null ? null : [
            'height' => (int) $row['height'],
            'hash' => (string) $row['hash'],
            'timestamp' => (int) $row['timestamp'],
        ];
    }

    /** @return list<array<string,mixed>> the newest blocks, highest first */
    public function latestBlocks(int $count): array
    {
        return $this->db->all(
            'SELECT height, hash, timestamp, num_txes, block_size FROM blocks ORDER BY height DESC LIMIT ?',
            [$count],
        );
    }

    /** @return array<string,mixed>|null */
    public function blockByHeight(int $height): ?array
    {
        return $this->db->one('SELECT * FROM blocks WHERE height = ?', [$height]);
    }

    /** @return array<string,mixed>|null */
    public function blockByHash(string $hash): ?array
    {
        return $this->db->one('SELECT * FROM blocks WHERE hash = ?', [$hash]);
    }

    public function hashAt(int $height): ?string
    {
        $v = $this->db->value('SELECT hash FROM blocks WHERE height = ?', [$height]);
        return $v === null ? null : (string) $v;
    }

    /** @return array<string,mixed>|null the pricing record stored for a block */
    public function pricingAt(int $height): ?array
    {
        return $this->db->one('SELECT * FROM pricing_records WHERE height = ?', [$height]);
    }

    /** @return array<string,mixed>|null the newest pricing record */
    public function latestPricing(): ?array
    {
        return $this->db->one('SELECT * FROM pricing_records ORDER BY height DESC LIMIT 1');
    }

    /** @return array{height:int,detected_at:int}|null a block that a rollback removed */
    public function orphaned(string $hash): ?array
    {
        $row = $this->db->one('SELECT height, detected_at FROM orphaned_blocks WHERE hash = ?', [$hash]);
        return $row === null ? null : ['height' => (int) $row['height'], 'detected_at' => (int) $row['detected_at']];
    }

    /** @return array<string,mixed>|null the newest row of runs */
    public function lastRun(): ?array
    {
        return $this->db->one('SELECT started_at, finished_at, mode, headers, outcome FROM runs ORDER BY id DESC LIMIT 1');
    }

    /** @return array<string,mixed>|null the newest row of reorgs */
    public function lastReorg(): ?array
    {
        return $this->db->one('SELECT detected_at, fork_height, old_tip_height, depth FROM reorgs ORDER BY id DESC LIMIT 1');
    }

    /** Unix time of the newest supply snapshot, or null when there is none. */
    public function lastSnapshotTime(): ?int
    {
        $v = $this->db->value('SELECT max(taken_at) FROM supply_snapshots');
        return $v === null ? null : (int) $v;
    }
}
