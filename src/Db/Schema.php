<?php

declare(strict_types=1);

namespace Lateo\Explorer\Db;

/**
 * The database schema, with a version in the meta table and forward migrations in code.
 *
 * Every table that holds chain data is keyed by height, so undoing a reorganisation is
 * "delete where height > f" on each of them (ChainStore::rollbackAbove) and a re-run.
 *
 * Integers: a uint64 from a node is stored as INTEGER. A value above the signed 64-bit range
 * is never stored (the indexer stops with an error instead). Difficulties are 128 bit and are
 * stored as decimal TEXT. Times are Unix seconds (UTC).
 *
 * Version 2 adds the transactions table, the derived table conversion_checks and, in meta,
 * the key tx_indexed_height (written by the transaction indexer, lowered by a rollback).
 * Version 3 adds transactions.n_untagged_outputs (NULL on rows stored by version 2 code) and the
 * table miner_txs, one row per block the transaction indexer asked for. Rolling the code back to
 * an older version is not supported: a database of a newer version is refused by older code
 * ("newer than this code").
 */
final class Schema
{
    /** The version this code writes. */
    public const VERSION = 3;

    /**
     * Migrations by version: the statements that bring a database from version N-1 to N.
     * Each version is applied in one transaction, together with the new version number.
     *
     * @return array<int,list<string>>
     */
    public static function migrations(): array
    {
        return [
            1 => [
                // --- chain tables, keyed by height -------------------------------------------
                // Header fields of the daemon's block_header_response. Not stored: depth
                // (changes with every block), orphan_status (always false for the main chain),
                // pow_hash (empty on the restricted RPC), rewards (declared but never filled
                // by the daemon) and the *_top64 and wide_* fields (the difficulties are kept
                // whole as decimal text).
                'CREATE TABLE blocks (
                    height INTEGER PRIMARY KEY,
                    hash TEXT NOT NULL UNIQUE CHECK (length(hash) = 64),
                    prev_hash TEXT NOT NULL CHECK (length(prev_hash) = 64),
                    timestamp INTEGER NOT NULL,
                    major_version INTEGER NOT NULL,
                    minor_version INTEGER NOT NULL,
                    nonce INTEGER NOT NULL,
                    difficulty TEXT NOT NULL,
                    cumulative_difficulty TEXT NOT NULL,
                    reward INTEGER NOT NULL,
                    block_size INTEGER NOT NULL,
                    block_weight INTEGER NOT NULL,
                    long_term_weight INTEGER NOT NULL,
                    num_txes INTEGER NOT NULL,
                    miner_tx_hash TEXT NOT NULL CHECK (length(miner_tx_hash) = 64)
                )',

                // The oracle's pricing record of each block, in the daemon's units (the
                // record fields as integers, 12 decimals). Only non-empty records: blocks
                // before height 89300 carry an all-zero record, which is not stored.
                'CREATE TABLE pricing_records (
                    height INTEGER PRIMARY KEY REFERENCES blocks (height),
                    spot INTEGER NOT NULL,
                    moving_average INTEGER NOT NULL,
                    stable INTEGER NOT NULL,
                    stable_ma INTEGER NOT NULL,
                    reserve INTEGER NOT NULL,
                    reserve_ma INTEGER NOT NULL,
                    reserve_ratio INTEGER NOT NULL,
                    reserve_ratio_ma INTEGER NOT NULL,
                    yield_price INTEGER NOT NULL,
                    timestamp INTEGER NOT NULL,
                    signature TEXT NOT NULL CHECK (length(signature) = 128)
                )',

                // --- records of what the indexer saw -----------------------------------------
                // Blocks removed by a rollback, so that a block page asked for that hash can
                // say "Orphaned". Not deleted on a rollback; a row goes when a block with that
                // hash is stored again (ChainStore::insertBatch).
                'CREATE TABLE orphaned_blocks (
                    hash TEXT PRIMARY KEY CHECK (length(hash) = 64),
                    height INTEGER NOT NULL,
                    detected_at INTEGER NOT NULL
                ) WITHOUT ROWID',
                'CREATE INDEX orphaned_blocks_height ON orphaned_blocks (height)',

                'CREATE TABLE reorgs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    detected_at INTEGER NOT NULL,
                    fork_height INTEGER NOT NULL,
                    old_tip_height INTEGER NOT NULL,
                    old_tip_hash TEXT NOT NULL,
                    depth INTEGER NOT NULL,
                    node TEXT NOT NULL
                )',

                // What a node reported about the supply, with the height and hash of the top
                // block it was taken at. These rows are NOT deleted on a rollback: they record
                // what a node said at the time. A reader must use only rows whose height and
                // hash are in blocks (join on both), so that a snapshot of an orphaned block
                // is never shown as current.
                'CREATE TABLE supply_snapshots (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    taken_at INTEGER NOT NULL,
                    height INTEGER NOT NULL,
                    hash TEXT NOT NULL CHECK (length(hash) = 64),
                    node TEXT NOT NULL,
                    kind TEXT NOT NULL CHECK (kind IN (\'reserve_info\', \'circulating\', \'audited\')),
                    payload TEXT NOT NULL,
                    UNIQUE (height, hash, kind)
                )',

                // One row per indexer run; rows older than 30 days are deleted.
                'CREATE TABLE runs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    started_at INTEGER NOT NULL,
                    finished_at INTEGER NOT NULL,
                    mode TEXT NOT NULL CHECK (mode IN (\'backfill\', \'tip\')),
                    from_height INTEGER,
                    to_height INTEGER,
                    headers INTEGER NOT NULL,
                    node TEXT,
                    outcome TEXT NOT NULL,
                    note TEXT NOT NULL
                )',
                'CREATE INDEX runs_started_at ON runs (started_at)',
            ],
            2 => [
                // One row per non-miner transaction of a stored block, from /get_transactions
                // with prune. The hash is lower-case hex (as everywhere in this database). The
                // assets are stored as the canonical ticker (ZPH, ZSD, ZRS, ZYS or 'unknown',
                // never a string of the chain) and the generation of the string the chain used
                // (old: ZEPH, ZEPHUSD, ZEPHRSV, ZYIELD; new: ZPH, ZSD, ZRS, ZYS; unknown).
                // tx_type is derived as the daemon derives it (cryptonote_tx_utils.cpp
                // get_tx_asset_types and get_tx_type); every audit pair is 'audit'; a shape
                // the daemon would not derive a type for is 'unknown'. fee is txnFee in atomic
                // units of the source asset. unlock_time is not stored (an author chooses it
                // freely). Inputs, outputs and signatures are not stored.
                'CREATE TABLE transactions (
                    hash TEXT PRIMARY KEY CHECK (length(hash) = 64 AND hash NOT GLOB \'*[^0-9a-f]*\'),
                    height INTEGER NOT NULL REFERENCES blocks (height),
                    idx INTEGER NOT NULL CHECK (idx >= 0),
                    version INTEGER NOT NULL CHECK (version >= 0),
                    rct_type INTEGER NOT NULL CHECK (rct_type >= 0),
                    source_asset TEXT NOT NULL CHECK (source_asset IN (\'ZPH\', \'ZSD\', \'ZRS\', \'ZYS\', \'unknown\')),
                    source_gen TEXT NOT NULL CHECK (source_gen IN (\'old\', \'new\', \'unknown\')),
                    dest_asset TEXT NOT NULL CHECK (dest_asset IN (\'ZPH\', \'ZSD\', \'ZRS\', \'ZYS\', \'unknown\')),
                    dest_gen TEXT NOT NULL CHECK (dest_gen IN (\'old\', \'new\', \'unknown\')),
                    tx_type TEXT NOT NULL CHECK (tx_type IN (\'transfer\', \'mint_stable\', \'redeem_stable\',
                        \'mint_reserve\', \'redeem_reserve\', \'mint_yield\', \'redeem_yield\', \'audit\', \'unknown\')),
                    n_inputs INTEGER NOT NULL CHECK (n_inputs >= 0),
                    n_outputs INTEGER NOT NULL CHECK (n_outputs >= 0),
                    fee INTEGER NOT NULL CHECK (fee >= 0),
                    pricing_record_height INTEGER NOT NULL CHECK (pricing_record_height >= 0),
                    amount_burnt INTEGER NOT NULL CHECK (amount_burnt >= 0),
                    amount_minted INTEGER NOT NULL CHECK (amount_minted >= 0),
                    CHECK ((source_asset = \'unknown\') = (source_gen = \'unknown\')),
                    CHECK ((dest_asset = \'unknown\') = (dest_gen = \'unknown\')),
                    UNIQUE (height, idx)
                ) WITHOUT ROWID',
                // No separate index on height: UNIQUE (height, idx) serves every query by height.

                // The recomputation of conversions and audits (ConversionRule), one row per
                // transaction that needs one. The table is derived: it can be emptied and
                // refilled from transactions, blocks and pricing_records (bin/recompute.php).
                // reason is 'checked' for a match and a mismatch, otherwise why nothing was
                // recomputed. The numbers are decimal text (they can pass 64 bits); they are null
                // when nothing was computed. A rollback deletes the rows above the fork first.
                'CREATE TABLE conversion_checks (
                    tx_hash TEXT PRIMARY KEY REFERENCES transactions (hash),
                    height INTEGER NOT NULL,
                    rule_version INTEGER NOT NULL,
                    hard_fork INTEGER NOT NULL,
                    verdict TEXT NOT NULL CHECK (verdict IN (\'match\', \'mismatch\', \'not_recomputed\')),
                    reason TEXT NOT NULL CHECK (reason IN (\'checked\', \'unknown_type\', \'transfer_with_conversion_fields\',
                        \'type_not_allowed\', \'age_out_of_window\', \'pr_missing\', \'pr_incomplete\', \'invalid_value\')),
                    price_field TEXT CHECK (price_field IS NULL OR price_field IN (\'stable\', \'stable_ma\', \'reserve\', \'reserve_ma\', \'yield_price\')),
                    price TEXT,
                    r0 TEXT,
                    fee TEXT,
                    rate TEXT,
                    expected TEXT,
                    remainder TEXT,
                    CHECK ((verdict = \'not_recomputed\') = (reason <> \'checked\')),
                    CHECK ((verdict = \'not_recomputed\') = (expected IS NULL))
                ) WITHOUT ROWID',
                'CREATE INDEX conversion_checks_height ON conversion_checks (height)',
                'CREATE INDEX conversion_checks_verdict ON conversion_checks (verdict, hard_fork)',
            ],
            3 => [
                // The number of outputs of a transaction whose target variant is not
                // tagged_key. NULL only on rows that version 2 code stored; version 3 code
                // always writes it.
                'ALTER TABLE transactions ADD COLUMN n_untagged_outputs INTEGER
                    CHECK (n_untagged_outputs IS NULL OR n_untagged_outputs >= 0)',

                // The shape of the miner transaction of every block whose get_block answer the
                // transaction indexer read (blocks with transactions only: a block without
                // transactions is not asked and has no row). hash is the block's
                // miner_tx_hash. rct_type is 0 for a miner transaction (the daemon writes type 0
                // for version 2 and later and no rct_signatures at all for version 1). No
                // amounts, keys, unlock_time or extra are stored. A rollback deletes the rows
                // above the fork.
                'CREATE TABLE miner_txs (
                    height INTEGER PRIMARY KEY REFERENCES blocks (height),
                    hash TEXT NOT NULL CHECK (length(hash) = 64 AND hash NOT GLOB \'*[^0-9a-f]*\'),
                    version INTEGER NOT NULL CHECK (version >= 0),
                    rct_type INTEGER NOT NULL CHECK (rct_type >= 0),
                    n_outputs INTEGER NOT NULL CHECK (n_outputs >= 0),
                    n_untagged_outputs INTEGER NOT NULL CHECK (n_untagged_outputs >= 0)
                ) WITHOUT ROWID',
            ],
        ];
    }

    /** The version stored in the database, 0 when there is none yet. */
    public static function currentVersion(Database $db): int
    {
        $has = $db->value("SELECT count(*) FROM sqlite_master WHERE type = 'table' AND name = 'meta'");
        if ((int) $has === 0) {
            return 0;
        }
        $v = $db->value("SELECT value FROM meta WHERE key = 'schema_version'");
        return $v === null ? 0 : (int) $v;
    }

    /**
     * Bring the database to the newest version. A database written by newer code is refused.
     *
     * @param array<int,list<string>>|null $migrations only for tests; defaults to migrations()
     */
    public static function migrate(Database $db, ?array $migrations = null): int
    {
        $migrations ??= self::migrations();
        ksort($migrations);
        $target = $migrations === [] ? 0 : (int) array_key_last($migrations);
        $current = self::currentVersion($db);
        if ($current > $target) {
            throw new \RuntimeException('database is newer than this code');
        }
        foreach ($migrations as $version => $statements) {
            if ($version <= $current) {
                continue;
            }
            if ($version !== $current + 1) {
                throw new \LogicException('migration versions must be consecutive');
            }
            $db->transaction(static function () use ($db, $version, $statements): void {
                $db->exec('CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY, value TEXT NOT NULL) WITHOUT ROWID');
                foreach ($statements as $sql) {
                    $db->exec($sql);
                }
                $db->exec(
                    'INSERT INTO meta (key, value) VALUES (\'schema_version\', ?)
                     ON CONFLICT (key) DO UPDATE SET value = excluded.value',
                    [(string) $version],
                );
            });
            $current = $version;
        }
        return $current;
    }
}
