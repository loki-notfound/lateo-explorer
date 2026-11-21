<?php

declare(strict_types=1);

namespace Lateo\Explorer\Db;

use Lateo\Explorer\Chain\ConversionResult;
use Lateo\Explorer\Chain\ConversionRule;
use Lateo\Explorer\Chain\Generation;

/**
 * Computes the conversion checks of stored transactions and keeps them in conversion_checks.
 *
 * The table is derived data: everything in it follows from transactions, blocks (the hard
 * fork) and pricing_records through ConversionRule, so it can be emptied and refilled.
 * Nothing here opens a transaction: the caller does (the transaction store writes the rows
 * and their checks together; the recompute command works in batches).
 */
final class ConversionCheckStore
{
    /** The SQL form of ConversionRule::needsCheck, for a table alias t. */
    public const NEEDS_CHECK_SQL = "(t.tx_type <> 'transfer' OR t.amount_burnt <> 0 OR t.amount_minted <> 0 OR t.pricing_record_height <> 0)";

    /** @var array<int,int|null> hard fork by height, for the blocks seen by one call */
    private array $forks = [];
    /** @var array<int,array<string,int>|null> record by height */
    private array $records = [];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Check and store the given transactions (those that need a check; others are ignored).
     *
     * @param list<array{hash:string,height:int,type:string,source_gen:Generation,dest_gen:Generation,burnt:int,minted:int,prh:int}> $txs
     * @return array{checks:int,match:int,mismatch:int,not_recomputed:int}
     */
    public function checkAndStore(array $txs): array
    {
        $counts = ['checks' => 0, 'match' => 0, 'mismatch' => 0, 'not_recomputed' => 0];
        $this->forks = [];
        $this->records = [];
        foreach ($txs as $tx) {
            if (!ConversionRule::needsCheck($tx['type'], $tx['burnt'], $tx['minted'], $tx['prh'])) {
                continue;
            }
            $fork = $this->hardFork($tx['height']);
            if ($fork === null) {
                throw new \LogicException('a transaction without a stored block');
            }
            $result = ConversionRule::check(
                $tx['type'],
                $fork,
                $tx['height'],
                $tx['prh'],
                $tx['burnt'],
                $tx['minted'],
                $tx['type'] === 'audit' ? null : $this->record($tx['prh']),
                $tx['source_gen'],
                $tx['dest_gen'],
            );
            $this->insert($tx['hash'], $tx['height'], $fork, $result);
            $counts['checks']++;
            $counts[$result->verdict]++;
        }
        return $counts;
    }

    public function insert(string $hash, int $height, int $hardFork, ConversionResult $r): void
    {
        $this->db->exec(
            'INSERT INTO conversion_checks (tx_hash, height, rule_version, hard_fork, verdict, reason, price_field,
                price, r0, fee, rate, expected, remainder)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $hash, $height, ConversionRule::VERSION, $hardFork, $r->verdict, $r->reason, $r->priceField,
                $r->price, $r->r0, $r->fee, $r->rate, $r->expected, $r->remainder,
            ],
        );
    }

    /** Remove every check (the first step of a recomputation). */
    public function clear(): void
    {
        $this->db->exec('DELETE FROM conversion_checks');
    }

    private function hardFork(int $height): ?int
    {
        if (!array_key_exists($height, $this->forks)) {
            $v = $this->db->value('SELECT major_version FROM blocks WHERE height = ?', [$height]);
            $this->forks[$height] = $v === null ? null : (int) $v;
        }
        return $this->forks[$height];
    }

    /** @return array<string,int>|null the nine fields of the stored record, null when none is stored */
    private function record(int $height): ?array
    {
        if (!array_key_exists($height, $this->records)) {
            $row = $this->db->one(
                'SELECT ' . implode(', ', ConversionRule::RECORD_FIELDS) . ' FROM pricing_records WHERE height = ?',
                [$height],
            );
            $this->records[$height] = $row === null ? null : array_map('intval', $row);
        }
        return $this->records[$height];
    }
}
