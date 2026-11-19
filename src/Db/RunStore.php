<?php

declare(strict_types=1);

namespace Lateo\Explorer\Db;

/** One row per indexer run; rows older than 30 days are deleted. */
final class RunStore
{
    public const KEEP_DAYS = 30;

    public function __construct(private readonly Database $db)
    {
    }

    public function record(
        int $startedAt,
        int $finishedAt,
        string $mode,
        ?int $fromHeight,
        ?int $toHeight,
        int $headers,
        ?string $node,
        string $outcome,
        string $note,
    ): void {
        $this->db->transaction(function () use ($startedAt, $finishedAt, $mode, $fromHeight, $toHeight, $headers, $node, $outcome, $note): void {
            $this->db->exec(
                'INSERT INTO runs (started_at, finished_at, mode, from_height, to_height, headers, node, outcome, note)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$startedAt, $finishedAt, $mode, $fromHeight, $toHeight, $headers, $node, $outcome, $note],
            );
        });
    }

    /** Delete the rows older than KEEP_DAYS days; returns how many. */
    public function prune(int $now): int
    {
        return $this->db->transaction(
            fn (): int => $this->db->exec('DELETE FROM runs WHERE started_at < ?', [$now - self::KEEP_DAYS * 86400]),
        );
    }
}
