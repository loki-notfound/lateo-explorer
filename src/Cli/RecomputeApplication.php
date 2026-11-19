<?php

declare(strict_types=1);

namespace Lateo\Explorer\Cli;

use Lateo\Explorer\Chain\ConversionRule;
use Lateo\Explorer\Config\Config;
use Lateo\Explorer\Db\ConversionCheckStore;
use Lateo\Explorer\Db\Database;
use Lateo\Explorer\Db\Schema;

/**
 * bin/recompute.php: empties conversion_checks and refills it from the database with the
 * current rule, in batches, then prints a summary.
 *
 * It reads only the database (no node is ever asked), takes the indexer's lock file so that
 * it never runs beside the indexer, and prints no transaction hash (the hashes of mismatches
 * are read from the table). The summary has one line per transaction type and fee regime
 * with the counts of match, mismatch and of each reason for not recomputing, and the number
 * of non-zero remainders. Exit code 0 when there is no mismatch, 2 when there is one, 1 after
 * an error (also when the lock is held).
 */
final class RecomputeApplication
{
    public const BATCH = 500;

    /**
     * @param \Closure(): Database $openDatabase
     * @param resource $out
     * @param resource $err
     */
    public function __construct(
        private readonly Config $config,
        private readonly \Closure $openDatabase,
        private $out,
        private $err,
        private readonly ?string $lockFile = null,
    ) {
    }

    public function run(): int
    {
        $lock = @fopen($this->lockFile ?? $this->config->lockFile, 'c');
        if ($lock === false) {
            fwrite($this->err, "recompute: the lock file cannot be opened\n");
            return 1;
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            fwrite($this->err, "recompute: the indexer holds the lock; nothing was changed\n");
            return 1;
        }
        try {
            return $this->locked();
        } catch (\Throwable $e) {
            $own = $e instanceof \RuntimeException && !$e instanceof \PDOException;
            fwrite($this->err, 'recompute: stopped: ' . ($own ? $e->getMessage() : $e::class) . "\n");
            return 1;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function locked(): int
    {
        $db = ($this->openDatabase)();
        Schema::migrate($db);
        $store = new ConversionCheckStore($db);
        $db->transaction(static fn () => $store->clear());

        $needs = ConversionCheckStore::NEEDS_CHECK_SQL;
        $after = [-1, -1];
        while (true) {
            $rows = $db->all(
                "SELECT t.hash, t.height, t.idx, t.tx_type, t.amount_burnt, t.amount_minted, t.pricing_record_height
                 FROM transactions t
                 WHERE $needs AND (t.height > ? OR (t.height = ? AND t.idx > ?))
                 ORDER BY t.height, t.idx LIMIT ?",
                [$after[0], $after[0], $after[1], self::BATCH],
            );
            if ($rows === []) {
                break;
            }
            $txs = [];
            foreach ($rows as $r) {
                $txs[] = [
                    'hash' => (string) $r['hash'], 'height' => (int) $r['height'], 'type' => (string) $r['tx_type'],
                    'burnt' => (int) $r['amount_burnt'], 'minted' => (int) $r['amount_minted'],
                    'prh' => (int) $r['pricing_record_height'],
                ];
            }
            $db->transaction(static fn () => $store->checkAndStore($txs));
            $last = $rows[array_key_last($rows)];
            $after = [(int) $last['height'], (int) $last['idx']];
        }

        return $this->summary($db);
    }

    /** The fee regime a transaction type and hard fork fall in, for the summary. */
    public static function regime(string $type, int $hardFork): string
    {
        if ($type === 'audit') {
            return match ($hardFork) {
                8 => 'audit hf 8',
                10 => 'audit hf 10',
                default => 'audit other',
            };
        }
        return match (true) {
            $hardFork < 3 => 'hf <3',
            $hardFork <= 4 => 'hf 3-4',
            $hardFork === 5 => 'hf 5',
            default => 'hf >=6',
        };
    }

    private function summary(Database $db): int
    {
        $rows = $db->all(
            "SELECT t.tx_type, c.hard_fork, c.verdict, c.reason, count(*) AS n,
                    sum(CASE WHEN c.remainder IS NOT NULL AND c.remainder <> '0' THEN 1 ELSE 0 END) AS nonzero
             FROM conversion_checks c JOIN transactions t ON t.hash = c.tx_hash
             GROUP BY t.tx_type, c.hard_fork, c.verdict, c.reason",
        );
        /** @var array<string,array<string,int>> $groups */
        $groups = [];
        $total = ['checks' => 0, 'match' => 0, 'mismatch' => 0, 'not_recomputed' => 0, 'nonzero' => 0];
        foreach ($rows as $r) {
            $key = sprintf('%-15s %-10s', $r['tx_type'], self::regime((string) $r['tx_type'], (int) $r['hard_fork']));
            $n = (int) $r['n'];
            $counter = $r['verdict'] === 'not_recomputed' ? (string) $r['reason'] : (string) $r['verdict'];
            $groups[$key][$counter] = ($groups[$key][$counter] ?? 0) + $n;
            $groups[$key]['nonzero_remainders'] = ($groups[$key]['nonzero_remainders'] ?? 0) + (int) $r['nonzero'];
            $total['checks'] += $n;
            $total[(string) $r['verdict']] += $n;
            $total['nonzero'] += (int) $r['nonzero'];
        }
        ksort($groups);
        fwrite($this->out, 'recompute: rule version ' . ConversionRule::VERSION . ', ' . $total['checks'] . " checks\n");
        foreach ($groups as $key => $counters) {
            $parts = ['match=' . ($counters['match'] ?? 0), 'mismatch=' . ($counters['mismatch'] ?? 0)];
            foreach (array_slice(ConversionRule::REASONS, 1) as $reason) {
                if (($counters[$reason] ?? 0) > 0) {
                    $parts[] = $reason . '=' . $counters[$reason];
                }
            }
            $parts[] = 'nonzero_remainders=' . ($counters['nonzero_remainders'] ?? 0);
            fwrite($this->out, rtrim($key) . ': ' . implode(' ', $parts) . "\n");
        }
        fwrite($this->out, sprintf(
            "total: checks=%d match=%d mismatch=%d not_recomputed=%d nonzero_remainders=%d\n",
            $total['checks'],
            $total['match'],
            $total['mismatch'],
            $total['not_recomputed'],
            $total['nonzero'],
        ));
        return $total['mismatch'] > 0 ? 2 : 0;
    }
}
