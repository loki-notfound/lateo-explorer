<?php

declare(strict_types=1);

namespace Lateo\Explorer\Indexer;

use Lateo\Explorer\Db\SnapshotStore;
use Lateo\Explorer\Rpc\RpcClient;
use Lateo\Explorer\Rpc\RpcException;
use Lateo\Explorer\Rpc\RpcMethod;
use Lateo\Explorer\Support\Clock;

/**
 * Records what a node says about the supply (get_reserve_info, get_circulating_supply,
 * get_audited_supply) together with the height and hash of the top block, from the first run
 * on (review 2026-11-19, rule 2.1).
 *
 * Once per run, and only when the top block differs from the last snapshot's: on ONE node,
 * get_last_block_header, then the three calls, then get_last_block_header again. The three
 * answers are stored only when both headers have the same hash, so that they belong to one
 * block; otherwise the run skips the snapshot and the next run tries again.
 *
 * Only own nodes answer, unless the caller allows the fallback (a bounded sample run).
 */
final class SupplySnapshot
{
    public function __construct(
        private readonly RpcClient $client,
        private readonly SnapshotStore $store,
        private readonly Clock $clock,
        private readonly bool $allowFallback = false,
    ) {
    }

    public function run(): SnapshotOutcome
    {
        try {
            $first = $this->client->call(RpcMethod::GetLastBlockHeader, [], $this->allowFallback);
            $node = $first->node;
            $before = HeaderParser::parse($first->data['block_header'] ?? null);

            $last = $this->store->lastTaken();
            if ($last !== null && $last['height'] === $before->height && $last['hash'] === $before->hash) {
                return SnapshotOutcome::Unchanged;
            }

            $payloads = [];
            foreach ([
                'reserve_info' => RpcMethod::GetReserveInfo,
                'circulating' => RpcMethod::GetCirculatingSupply,
                'audited' => RpcMethod::GetAuditedSupply,
            ] as $kind => $method) {
                $payloads[$kind] = $this->client->call($method, [], $this->allowFallback, $node)->data;
            }

            $second = $this->client->call(RpcMethod::GetLastBlockHeader, [], $this->allowFallback, $node);
            $after = HeaderParser::parse($second->data['block_header'] ?? null);
            if ($after->hash !== $before->hash || $after->height !== $before->height) {
                return SnapshotOutcome::Changed;
            }

            $this->store->store((int) floor($this->clock->now()), $before->height, $before->hash, $node, $payloads);
            return SnapshotOutcome::Stored;
        } catch (RpcException|IndexerException) {
            return SnapshotOutcome::Failed;
        }
    }
}
