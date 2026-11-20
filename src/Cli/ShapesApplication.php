<?php

declare(strict_types=1);

namespace Lateo\Explorer\Cli;

use Lateo\Explorer\Db\Database;
use Lateo\Explorer\Db\Schema;

/**
 * bin/shapes.php: prints counts about the shape of the stored transactions, from the database
 * only (a read-only connection, no node is ever asked, nothing is written).
 *
 * The three questions it answers: which versions and ringct types occur (per version and type,
 * the number of transactions with the lowest and the highest height, for ordinary transactions
 * and for miner transactions separately), whether outputs of another variant than tagged_key
 * occur (the number of transactions and of miner transactions with at least one, with their
 * lowest and highest height), and how many rows were stored by code that did not yet count them
 * (NULL). It prints counts and heights only, never a hash. Exit code 0, or 1 after an error
 * (also when the database is older than schema version 3).
 */
final class ShapesApplication
{
    /**
     * @param \Closure(): Database $openDatabase
     * @param resource $out
     * @param resource $err
     */
    public function __construct(
        private readonly \Closure $openDatabase,
        private $out,
        private $err,
    ) {
    }

    public function run(): int
    {
        try {
            $db = ($this->openDatabase)();
            $version = Schema::currentVersion($db);
            if ($version < 3) {
                fwrite($this->err, 'shapes: the database is at schema version ' . $version . ", not 3; nothing to show\n");
                return 1;
            }
            $this->write($db);
            return 0;
        } catch (\Throwable $e) {
            $own = $e instanceof \RuntimeException && !$e instanceof \PDOException;
            fwrite($this->err, 'shapes: stopped: ' . ($own ? $e->getMessage() : $e::class) . "\n");
            return 1;
        }
    }

    private function write(Database $db): void
    {
        $mark = $db->value("SELECT value FROM meta WHERE key = 'tx_indexed_height'");
        fwrite($this->out, 'shapes: schema version ' . Schema::currentVersion($db) . ', transactions stored up to height ' . ($mark === null ? '-' : (string) $mark) . "\n");

        $this->section($db, 'transactions by version and rct_type', 'transactions', 'version, rct_type', 'version=%d rct_type=%d');
        $this->section($db, 'miner transactions by version and rct_type', 'miner_txs', 'version, rct_type', 'version=%d rct_type=%d');

        $this->line($db, 'transactions with outputs of another variant than tagged_key', 'transactions', 'n_untagged_outputs > 0');
        $this->line($db, 'miner transactions with outputs of another variant than tagged_key', 'miner_txs', 'n_untagged_outputs > 0');
        $this->line($db, 'transactions with n_untagged_outputs NULL (stored before schema 3)', 'transactions', 'n_untagged_outputs IS NULL');
        $this->line($db, 'miner transactions with n_untagged_outputs NULL', 'miner_txs', 'n_untagged_outputs IS NULL');
    }

    /** One line per group: the group's values, the count, the lowest and the highest height. */
    private function section(Database $db, string $title, string $table, string $columns, string $format): void
    {
        fwrite($this->out, $title . ":\n");
        $rows = $db->all(
            'SELECT ' . $columns . ', count(*) AS n, min(height) AS low, max(height) AS high FROM ' . $table
            . ' GROUP BY ' . $columns . ' ORDER BY ' . $columns,
        );
        if ($rows === []) {
            fwrite($this->out, "  none\n");
            return;
        }
        foreach ($rows as $r) {
            $values = [];
            foreach (explode(', ', $columns) as $column) {
                $values[] = (int) $r[$column];
            }
            fwrite($this->out, '  ' . sprintf($format, ...$values) . ': count=' . (int) $r['n'] . ' lowest=' . (int) $r['low'] . ' highest=' . (int) $r['high'] . "\n");
        }
    }

    /** One line: the number of rows that fit $where with their lowest and highest height. */
    private function line(Database $db, string $title, string $table, string $where): void
    {
        $r = $db->one('SELECT count(*) AS n, min(height) AS low, max(height) AS high FROM ' . $table . ' WHERE ' . $where);
        $n = (int) ($r['n'] ?? 0);
        fwrite($this->out, $title . ': count=' . $n);
        if ($n > 0) {
            fwrite($this->out, ' lowest=' . (int) $r['low'] . ' highest=' . (int) $r['high']);
        }
        fwrite($this->out, "\n");
    }
}
