<?php

declare(strict_types=1);

namespace Lateo\Explorer\Indexer;

/** What the transaction indexer did in one run, for the log line. */
final readonly class TxSummary
{
    /** The switch is off: nothing was asked, nothing changed. */
    public const OFF = 'off';
    /** The run did not qualify (the header run failed, or it was a backfill run). */
    public const SKIPPED = 'skipped';
    /** Every stored block has its transactions. */
    public const COMPLETE = 'complete';
    /** The run's time was used up. */
    public const TIME = 'time';
    /** The chain moved under the run (another hash, a pool entry); the next run goes on. */
    public const STOPPED = 'stopped';
    public const ERROR = 'error';

    public function __construct(
        /** One of the constants above. */
        public string $outcome,
        /** First and last height the mark covered in this run, null when it did not move. */
        public ?int $fromHeight = null,
        public ?int $toHeight = null,
        /** Blocks that had transactions and were stored. */
        public int $blocks = 0,
        public int $transactions = 0,
        /** Calls that reached a node (all of them to own nodes). */
        public int $calls = 0,
        /** A short note without addresses: why the run stopped or failed. */
        public string $note = '',
    ) {
    }

    public static function off(): self
    {
        return new self(self::OFF);
    }

    public static function skipped(): self
    {
        return new self(self::SKIPPED);
    }

    /** The words of the log line. */
    public function logFields(): string
    {
        if ($this->outcome === self::OFF || $this->outcome === self::SKIPPED) {
            return 'tx=' . $this->outcome;
        }
        return sprintf(
            'tx=%s txfrom=%s txto=%s txblocks=%d txs=%d txcalls=%d',
            $this->outcome,
            $this->fromHeight ?? '-',
            $this->toHeight ?? '-',
            $this->blocks,
            $this->transactions,
            $this->calls,
        );
    }
}
