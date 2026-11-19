<?php

declare(strict_types=1);

namespace Lateo\Explorer\Db;

/**
 * The supply snapshots: what a node reported, with the height and hash of the top block it
 * was taken at. See Schema: the rows survive a rollback, so a reader must join on both height
 * and hash in blocks (latestOnChain()).
 */
final class SnapshotStore
{
    public const KINDS = ['reserve_info', 'circulating', 'audited'];

    public function __construct(private readonly Database $db)
    {
    }

    /** @return array{height:int,hash:string}|null the top block of the newest snapshot, on or off the chain */
    public function lastTaken(): ?array
    {
        $row = $this->db->one('SELECT height, hash FROM supply_snapshots ORDER BY id DESC LIMIT 1');
        return $row === null ? null : ['height' => (int) $row['height'], 'hash' => (string) $row['hash']];
    }

    /**
     * Store the three payloads of one snapshot in one transaction. A snapshot of the same
     * block and kind that exists already is left as it is.
     *
     * @param array<string,array<string,mixed>> $payloads by kind, the results as received
     */
    public function store(int $takenAt, int $height, string $hash, string $node, array $payloads): void
    {
        $this->db->transaction(function () use ($takenAt, $height, $hash, $node, $payloads): void {
            foreach ($payloads as $kind => $payload) {
                if (!in_array($kind, self::KINDS, true)) {
                    throw new \InvalidArgumentException('unknown snapshot kind');
                }
                $this->db->exec(
                    'INSERT OR IGNORE INTO supply_snapshots (taken_at, height, hash, node, kind, payload)
                     VALUES (?, ?, ?, ?, ?, ?)',
                    [
                        $takenAt, $height, $hash, $node, $kind,
                        json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    ],
                );
            }
        });
    }

    /**
     * The newest snapshot of a kind whose top block is in blocks (same height and hash).
     *
     * @return array{height:int,hash:string,taken_at:int,node:string,payload:string}|null
     */
    public function latestOnChain(string $kind): ?array
    {
        $row = $this->db->one(
            'SELECT s.height, s.hash, s.taken_at, s.node, s.payload
             FROM supply_snapshots s JOIN blocks b ON b.height = s.height AND b.hash = s.hash
             WHERE s.kind = ? ORDER BY s.height DESC LIMIT 1',
            [$kind],
        );
        if ($row === null) {
            return null;
        }
        return [
            'height' => (int) $row['height'],
            'hash' => (string) $row['hash'],
            'taken_at' => (int) $row['taken_at'],
            'node' => (string) $row['node'],
            'payload' => (string) $row['payload'],
        ];
    }
}
