<?php

declare(strict_types=1);

namespace Lateo\Explorer\Web\Page;

use Lateo\Explorer\Web\LiveState;
use Lateo\Explorer\Web\Response;
use Lateo\Explorer\Web\Services;

/**
 * /status: what the nodes report against what the index holds, and what the indexer did last.
 * Nodes appear by name only ("Own node 1", "Own node 2", "node.lateo.dev"), never by address.
 */
final class StatusPage
{
    public function respond(Services $s): Response
    {
        $index = $s->index();
        $live = $s->live()->nodes();

        $nodes = [];
        if ($live->ok()) {
            foreach ($live->value as $row) {
                $match = ['state' => 'unknown', 'label' => 'Not compared'];
                if ($row['reachable']) {
                    $stored = $index->hashAt((int) $row['height']);
                    $match = match (true) {
                        $stored === null => ['state' => 'unknown', 'label' => 'Not indexed yet'],
                        $stored === $row['hash'] => ['state' => 'online', 'label' => 'Matches the index'],
                        default => ['state' => 'degraded', 'label' => 'Differs from the index'],
                    };
                }
                $nodes[] = [
                    'name' => (string) $row['name'],
                    'reachable' => (bool) $row['reachable'],
                    'skipped' => (bool) $row['skipped'],
                    'limited' => (bool) ($row['limited'] ?? false),
                    'height' => $row['height'],
                    'match' => $match,
                ];
            }
        }

        $run = $index->lastRun();
        $html = $s->view->render('status', [
            'now' => $s->now(),
            'nodes' => $nodes,
            'nodesMessage' => $live->state === LiveState::Ok ? '' : $live->state->message(),
            'nodesStale' => $live->stale,
            'top' => $index->top(),
            'run' => $run,
            'outcomeWords' => $run === null ? '' : self::outcomeWords((string) $run['outcome']),
            'reorg' => $index->lastReorg(),
            'snapshot' => $index->lastSnapshotTime(),
        ]);
        return $s->layout->page(
            200,
            'Status',
            'Which nodes the explorer reads, whether they agree with the index, and what the indexer did last.',
            '/status',
            $html,
            'public, max-age=5',
        );
    }

    /**
     * The words for the outcome of the last run, from a fixed list: the stored text itself is
     * never shown (it comes from the database, which the indexer fills from node data).
     */
    public static function outcomeWords(string $outcome): string
    {
        return match ($outcome) {
            'complete' => 'Caught up',
            'time' => 'Stopped at its time limit; the next run goes on',
            'waiting' => 'Waiting for the next block (two blocks of equal work at the tip)',
            'error' => 'Ended with an error',
            default => 'Unknown',
        };
    }
}
