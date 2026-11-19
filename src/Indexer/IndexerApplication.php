<?php

declare(strict_types=1);

namespace Lateo\Explorer\Indexer;

use Lateo\Explorer\Cache\FileCache;
use Lateo\Explorer\Config\Config;
use Lateo\Explorer\Config\ConfigException;
use Lateo\Explorer\Db\ChainStore;
use Lateo\Explorer\Db\Database;
use Lateo\Explorer\Db\RunStore;
use Lateo\Explorer\Db\Schema;
use Lateo\Explorer\Db\SnapshotStore;
use Lateo\Explorer\Db\TransactionStore;
use Lateo\Explorer\Rpc\CallLimiter;
use Lateo\Explorer\Rpc\Profile;
use Lateo\Explorer\Rpc\RpcClient;
use Lateo\Explorer\Rpc\RpcException;
use Lateo\Explorer\Rpc\Transport;
use Lateo\Explorer\Support\Clock;

/**
 * One cron run of the indexer (bin/indexer.php).
 *
 * It takes the lock file with flock (LOCK_EX | LOCK_NB); when another run holds it, it ends
 * with exit code 0 and no output. Then: open and migrate the database, run the header indexer
 * and record the run, take the supply snapshot, run the transaction indexer when the settings
 * switch it on, sweep the cache and prune old runs. One log line per run goes to $out (time in
 * UTC, mode, heights, headers, node label, outcome, the transaction fields; never an address),
 * errors go to $err. The exit code is 0, or 1 after an error.
 *
 * The streams, the database opener, the lock and cache paths and the start height can be set
 * from outside so that tests need no real files, nodes or time.
 */
final class IndexerApplication
{
    /** Files the cache sweep keeps at most, and how long an expired entry is kept. */
    public const CACHE_MAX_FILES = 2000;
    public const CACHE_KEEP_STALE_SECONDS = 3600;

    /**
     * @param \Closure(): Database $openDatabase
     * @param resource $out
     * @param resource $err
     */
    public function __construct(
        private readonly Config $config,
        private readonly Transport $transport,
        private readonly Clock $clock,
        private readonly \Closure $openDatabase,
        private $out,
        private $err,
        private readonly ?string $lockFile = null,
        private readonly ?string $cacheDir = null,
        private readonly int $startHeight = 0,
        private readonly bool $snapshotMayUseFallback = false,
    ) {
    }

    public function run(): int
    {
        $lock = @fopen($this->lockFile ?? $this->config->lockFile, 'c');
        if ($lock === false) {
            $this->error('the lock file cannot be opened');
            return 1;
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return 0; // another run is working
        }
        try {
            return $this->locked();
        } catch (\Throwable $e) {
            $this->error('stopped: ' . $this->describe($e));
            return 1;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function locked(): int
    {
        $startedAt = $this->clock->now();
        $db = ($this->openDatabase)();
        Schema::migrate($db);

        $cacheDir = $this->cacheDir ?? $this->config->cacheDir;
        $client = new RpcClient(
            $this->config,
            $this->transport,
            Profile::Indexer,
            CallLimiter::fallback($cacheDir, $this->config->limits->fallbackCallsPerMinute, $this->clock),
            $this->clock,
        );
        $chain = new ChainStore($db);
        $runs = new RunStore($db);

        $summary = (new HeaderIndexer($client, $chain, $this->config->limits, $this->clock, $this->startHeight))->run();
        $runs->record(
            $summary->startedAt,
            $summary->finishedAt,
            $summary->mode->value,
            $summary->fromHeight,
            $summary->toHeight,
            $summary->headers,
            $summary->node,
            $summary->outcome,
            $summary->note,
        );

        $snapshot = 'skipped';
        if ($summary->outcome !== RunSummary::ERROR) {
            $snapshot = (new SupplySnapshot(
                $client,
                new SnapshotStore($db),
                $this->clock,
                $this->snapshotMayUseFallback,
            ))->run($summary->tipNode)->value;
        }

        $tx = $this->transactions($client, $chain, $db, $summary, $startedAt);

        (new FileCache($cacheDir, $this->clock))->sweep(self::CACHE_MAX_FILES, self::CACHE_KEEP_STALE_SECONDS);
        $runs->prune((int) floor($this->clock->now()));

        fwrite($this->out, sprintf(
            "%s mode=%s from=%s to=%s headers=%d node=%s outcome=%s reorgs=%d requests=%d snapshot=%s %s\n",
            gmdate('Y-m-d\TH:i:s\Z', $summary->finishedAt),
            $summary->mode->value,
            $summary->fromHeight ?? '-',
            $summary->toHeight ?? '-',
            $summary->headers,
            $summary->node ?? '-',
            $summary->outcome,
            $summary->reorgs,
            $summary->requests,
            $snapshot,
            $tx->logFields(),
        ));

        if ($summary->outcome === RunSummary::ERROR) {
            $this->error($summary->note);
            return 1;
        }
        if ($tx->outcome === TxSummary::ERROR) {
            $this->error('transactions: ' . $tx->note);
            return 1;
        }
        return 0;
    }

    /**
     * The transaction indexer: off unless the settings switch it on (then nothing at all is
     * asked or changed), skipped after a header run that ended in an error or that was a
     * backfill run (far behind the tip), otherwise run on the own nodes only.
     */
    private function transactions(RpcClient $client, ChainStore $chain, Database $db, RunSummary $summary, float $startedAt): TxSummary
    {
        if (!$this->config->limits->txBackfillEnabled) {
            return TxSummary::off();
        }
        if ($summary->outcome === RunSummary::ERROR || $summary->mode !== RunMode::Tip) {
            return TxSummary::skipped();
        }
        $preferred = $summary->tipNode !== null && $summary->tipNode !== $client->fallbackLabel() ? $summary->tipNode : null;
        return (new TransactionIndexer(
            $client,
            $chain,
            new TransactionStore($db),
            $this->config->limits,
            $this->clock,
            $startedAt,
            $preferred,
        ))->run();
    }

    private function error(string $message): void
    {
        fwrite($this->err, 'indexer: ' . $message . "\n");
    }

    /** Messages of our own exception types are safe to print; anything else only by class. */
    private function describe(\Throwable $e): string
    {
        $own = $e instanceof IndexerException || $e instanceof RpcException
            || $e instanceof ConfigException || $e instanceof \RuntimeException && !$e instanceof \PDOException;
        return $own ? $e->getMessage() : $e::class;
    }
}
