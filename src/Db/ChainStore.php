<?php

declare(strict_types=1);

namespace Lateo\Explorer\Db;

use Lateo\Explorer\Indexer\Header;
use Lateo\Explorer\Indexer\HeaderParser;

/**
 * Reads and writes the chain tables: blocks, pricing_records, and the records
 * of a rollback (orphaned_blocks, reorgs). Every write is one short transaction.
 */
final class ChainStore
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return array{height:int,hash:string}|null the highest stored block */
    public function top(): ?array
    {
        $row = $this->db->one('SELECT height, hash FROM blocks ORDER BY height DESC LIMIT 1');
        return $row === null ? null : ['height' => (int) $row['height'], 'hash' => (string) $row['hash']];
    }

    /** @return string|null decimal text of the cumulative difficulty of the highest stored block */
    public function topWork(): ?string
    {
        $v = $this->db->value('SELECT cumulative_difficulty FROM blocks ORDER BY height DESC LIMIT 1');
        return $v === null ? null : (string) $v;
    }

    public function lowestHeight(): ?int
    {
        $v = $this->db->value('SELECT min(height) FROM blocks');
        return $v === null ? null : (int) $v;
    }

    public function hashAt(int $height): ?string
    {
        $v = $this->db->value('SELECT hash FROM blocks WHERE height = ?', [$height]);
        return $v === null ? null : (string) $v;
    }

    /** @return array<int,string> stored hashes by height, for the heights from $low to $high */
    public function hashesBetween(int $low, int $high): array
    {
        $out = [];
        foreach ($this->db->all('SELECT height, hash FROM blocks WHERE height BETWEEN ? AND ?', [$low, $high]) as $row) {
            $out[(int) $row['height']] = (string) $row['hash'];
        }
        return $out;
    }

    public function isOrphaned(string $hash): bool
    {
        return $this->db->value('SELECT 1 FROM orphaned_blocks WHERE hash = ?', [$hash]) !== null;
    }

    /**
     * Store a validated batch of consecutive headers in one transaction. The hashes it stores
     * are removed from orphaned_blocks in the same transaction.
     *
     * @param list<Header> $headers
     */
    public function insertBatch(array $headers): void
    {
        $this->db->transaction(function () use ($headers): void {
            foreach ($headers as $h) {
                // A block that was orphaned and is back on the main chain is no longer orphaned.
                $this->db->exec('DELETE FROM orphaned_blocks WHERE hash = ?', [$h->hash]);
                $this->db->exec(
                    'INSERT INTO blocks (height, hash, prev_hash, timestamp, major_version, minor_version, nonce,
                        difficulty, cumulative_difficulty, reward, block_size, block_weight, long_term_weight,
                        num_txes, miner_tx_hash)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $h->height, $h->hash, $h->prevHash, $h->timestamp, $h->majorVersion, $h->minorVersion,
                        $h->nonce, $h->difficulty, $h->cumulativeDifficulty, $h->reward, $h->blockSize,
                        $h->blockWeight, $h->longTermWeight, $h->numTxes, $h->minerTxHash,
                    ],
                );
                if ($h->pricing !== null) {
                    $values = [$h->height];
                    foreach (HeaderParser::PRICING_FIELDS as $field) {
                        $values[] = $h->pricing[$field];
                    }
                    $values[] = $h->pricing['signature'];
                    $this->db->exec(
                        'INSERT INTO pricing_records (height, ' . implode(', ', HeaderParser::PRICING_FIELDS)
                        . ', signature) VALUES (?, ' . implode(', ', array_fill(0, 10, '?')) . ', ?)',
                        $values,
                    );
                }
            }
        });
    }

    /**
     * Undo the chain above $forkHeight in one transaction: copy the hashes above it into
     * orphaned_blocks, delete every chain table above it (the transactions and their checks
     * too, and the transaction indexer's mark is lowered to the fork height when it is
     * higher), and add a reorgs row. Rows of supply_snapshots stay (see Schema).
     *
     * @return int the number of blocks removed
     */
    public function rollbackAbove(int $forkHeight, string $node, int $detectedAt): int
    {
        return $this->db->transaction(function () use ($forkHeight, $node, $detectedAt): int {
            $top = $this->top();
            if ($top === null || $top['height'] <= $forkHeight) {
                return 0;
            }
            $this->db->exec(
                'INSERT OR IGNORE INTO orphaned_blocks (hash, height, detected_at)
                 SELECT hash, height, ? FROM blocks WHERE height > ?',
                [$detectedAt, $forkHeight],
            );
            // The children first: the foreign keys point from pricing_records and transactions
            // to blocks, and from conversion_checks to transactions. The mark of the
            // transaction indexer never stays above the fork.
            $this->db->exec('DELETE FROM conversion_checks WHERE height > ?', [$forkHeight]);
            $this->db->exec('DELETE FROM transactions WHERE height > ?', [$forkHeight]);
            $this->db->exec(
                'UPDATE meta SET value = ? WHERE key = ? AND CAST(value AS INTEGER) > ?',
                [(string) $forkHeight, TransactionStore::MARK_KEY, $forkHeight],
            );
            $this->db->exec('DELETE FROM pricing_records WHERE height > ?', [$forkHeight]);
            $removed = $this->db->exec('DELETE FROM blocks WHERE height > ?', [$forkHeight]);
            $this->db->exec(
                'INSERT INTO reorgs (detected_at, fork_height, old_tip_height, old_tip_hash, depth, node)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$detectedAt, $forkHeight, $top['height'], $top['hash'], $top['height'] - $forkHeight, $node],
            );
            return $removed;
        });
    }
}
