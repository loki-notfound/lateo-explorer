<?php

declare(strict_types=1);

namespace Lateo\Explorer\Indexer;

use Lateo\Explorer\Db\SnapshotStore;
use Lateo\Explorer\Rpc\RpcClient;
use Lateo\Explorer\Rpc\RpcException;
use Lateo\Explorer\Rpc\RpcMethod;
use Lateo\Explorer\Rpc\RpcResult;
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
 * The node that gave the header run's tip is asked first (see run()). Only own nodes
 * answer, unless the caller allows the fallback (a bounded sample run).
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

    /**
     * @param string|null $preferred the label of the node that gave the header run's tip: it is
     *                               asked first, so that the snapshot is of the chain the run
     *                               stored; when it fails (or is the fallback and the fallback
     *                               is not allowed) the usual order applies
     */
    public function run(?string $preferred = null): SnapshotOutcome
    {
        try {
            $first = $this->firstHeader($preferred);
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

    /** @throws RpcException */
    private function firstHeader(?string $preferred): RpcResult
    {
        if ($preferred !== null && ($this->allowFallback || $preferred !== $this->client->fallbackLabel())) {
            try {
                return $this->client->call(RpcMethod::GetLastBlockHeader, [], $this->allowFallback, $preferred);
            } catch (RpcException) {
                // Fall through to the usual order.
            }
        }
        return $this->client->call(RpcMethod::GetLastBlockHeader, [], $this->allowFallback);
    }
}
