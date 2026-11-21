<?php

declare(strict_types=1);

namespace Lateo\Explorer\Db;

use Lateo\Explorer\Indexer\MinerTransaction;
use Lateo\Explorer\Indexer\Transaction;

/**
 * Reads and writes the transactions table and the mark of the transaction indexer.
 *
 * The mark (meta key tx_indexed_height) says that every block up to and including that height
 * has all its transactions stored. It is advanced only together with the rows of whole blocks,
 * in one transaction, and lowered by ChainStore::rollbackAbove.
 */
final class TransactionStore
{
    public const MARK_KEY = 'tx_indexed_height';

    public function __construct(private readonly Database $db)
    {
    }

    /** The mark, or null when no block has its transactions stored yet. */
    public function indexedHeight(): ?int
    {
        $v = $this->db->value('SELECT value FROM meta WHERE key = ?', [self::MARK_KEY]);
        return $v === null ? null : (int) $v;
    }

    /**
     * Stored blocks above $height (all of them when null), lowest first, with what the
     * transaction indexer needs.
     *
     * @return list<array{height:int,hash:string,num_txes:int,miner_tx_hash:string}>
     */
    public function blocksAbove(?int $height, int $upTo, int $limit): array
    {
        $rows = $this->db->all(
            'SELECT height, hash, num_txes, miner_tx_hash FROM blocks WHERE height > ? AND height <= ? ORDER BY height LIMIT ?',
            [$height ?? -1, $upTo, $limit],
        );
        return array_map(static fn (array $r): array => [
            'height' => (int) $r['height'],
            'hash' => (string) $r['hash'],
            'num_txes' => (int) $r['num_txes'],
            'miner_tx_hash' => (string) $r['miner_tx_hash'],
        ], $rows);
    }

    /**
     * Store the rows of whole blocks and move the mark to $mark in one transaction. $mark is
     * the height of the last block of the group (a block without transactions has no rows).
     * The checks of the rows that need one (ConversionCheckStore) are stored in the same
     * transaction. The record a conversion refers to is at most 10 blocks below it, so it is
     * stored by then; if it is not, the check says pr_missing. The miner transactions of the
     * blocks of the group that were asked for are stored in the same transaction.
     *
     * @param list<Transaction> $rows
     * @param list<MinerTransaction> $miners
     * @return array{checks:int,match:int,mismatch:int,not_recomputed:int}
     */
    public function storeBlocks(array $rows, int $mark, array $miners = []): array
    {
        return $this->db->transaction(function () use ($rows, $mark, $miners): array {
            foreach ($rows as $t) {
                if ($t->height > $mark) {
                    throw new \LogicException('a row above the mark');
                }
                $this->db->exec(
                    'INSERT INTO transactions (hash, height, idx, version, rct_type, source_asset, source_gen,
                        dest_asset, dest_gen, tx_type, n_inputs, n_outputs, fee, pricing_record_height,
                        amount_burnt, amount_minted, n_untagged_outputs)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $t->hash, $t->height, $t->idx, $t->version, $t->rctType,
                        $t->source->ticker, $t->source->generation->value,
                        $t->destination->ticker, $t->destination->generation->value,
                        $t->type, $t->inputs, $t->outputs, $t->fee, $t->pricingRecordHeight,
                        $t->amountBurnt, $t->amountMinted, $t->untaggedOutputs,
                    ],
                );
            }
            foreach ($miners as $m) {
                if ($m->height > $mark) {
                    throw new \LogicException('a miner transaction above the mark');
                }
                $this->db->exec(
                    'INSERT INTO miner_txs (height, hash, version, rct_type, n_outputs, n_untagged_outputs)
                     VALUES (?, ?, ?, ?, ?, ?)',
                    [$m->height, $m->hash, $m->version, $m->rctType, $m->outputs, $m->untaggedOutputs],
                );
            }
            $counts = (new ConversionCheckStore($this->db))->checkAndStore(array_map(
                static fn (Transaction $t): array => [
                    'hash' => $t->hash, 'height' => $t->height, 'type' => $t->type,
                    'source_gen' => $t->source->generation, 'dest_gen' => $t->destination->generation,
                    'burnt' => $t->amountBurnt, 'minted' => $t->amountMinted, 'prh' => $t->pricingRecordHeight,
                ],
                $rows,
            ));
            $this->db->exec(
                'INSERT INTO meta (key, value) VALUES (?, ?)
                 ON CONFLICT (key) DO UPDATE SET value = excluded.value',
                [self::MARK_KEY, (string) $mark],
            );
            return $counts;
        });
    }
}
