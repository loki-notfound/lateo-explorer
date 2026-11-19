<?php

declare(strict_types=1);

namespace Lateo\Explorer\Web;

use Lateo\Explorer\Config\Config;
use Lateo\Explorer\Db\Database;
use Lateo\Explorer\Support\Clock;

/**
 * What the pages of one request use: the settings, the clock, the views and the layout, the
 * database (opened on first use) and the live data (made on first use). One instance serves
 * one request, so the RPC budget of its client is the request's budget.
 */
final class Services
{
    private ?Index $index = null;
    private ?LiveData $live = null;

    /**
     * @param \Closure():Database $openDatabase opens the read-only connection
     * @param \Closure():LiveData $makeLive
     */
    public function __construct(
        public readonly Config $config,
        public readonly Clock $clock,
        public readonly View $view,
        public readonly Layout $layout,
        private readonly \Closure $openDatabase,
        private readonly \Closure $makeLive,
    ) {
    }

    /** @throws IndexUnavailable */
    public function index(): Index
    {
        if ($this->index === null) {
            try {
                $db = ($this->openDatabase)();
                // A file the indexer has not filled yet has no tables: that is "not available", not an error.
                if ((int) $db->value("SELECT count(*) FROM sqlite_master WHERE type = 'table' AND name = 'blocks'") === 0) {
                    throw new IndexUnavailable('the index has no tables yet');
                }
                $this->index = new Index($db);
            } catch (\RuntimeException | \PDOException) {
                throw new IndexUnavailable('the index cannot be opened');
            }
        }
        return $this->index;
    }

    public function live(): LiveData
    {
        return $this->live ??= ($this->makeLive)();
    }

    public function now(): int
    {
        return (int) floor($this->clock->now());
    }
}
