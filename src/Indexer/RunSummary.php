<?php

declare(strict_types=1);

namespace Lateo\Explorer\Indexer;

/** What one header run did, for the runs table and the log line. */
final readonly class RunSummary
{
    public const COMPLETE = 'complete';
    public const TIME = 'time';
    public const ERROR = 'error';
    public const WAITING = 'waiting';

    public function __construct(
        public RunMode $mode,
        public int $startedAt,
        public int $finishedAt,
        /** First and last height stored in this run, null when nothing was stored. */
        public ?int $fromHeight,
        public ?int $toHeight,
        public int $headers,
        /** Label of the node that answered last, null when none did. */
        public ?string $node,
        /** One of COMPLETE (caught up), TIME (run_seconds used up), WAITING (two blocks of equal work), ERROR. */
        public string $outcome,
        /** A short note without addresses: empty, why the run ended in an error, or why it waits. */
        public string $note,
        public int $reorgs,
        public int $requests,
        /** Label of the node that gave the run's tip (the supply snapshot asks it first). */
        public ?string $tipNode = null,
    ) {
    }
}
