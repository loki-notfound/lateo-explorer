<?php

declare(strict_types=1);

namespace Lateo\Explorer\Rpc;

/**
 * The fixed allow-list of calls. Nothing outside this enum is ever sent: the URL path and the
 * JSON-RPC method name come from the case, never from the caller. All of them read; none
 * changes state on a node (no relay, no admin call).
 *
 * The value of a plain-path case starts with a slash.
 */
enum RpcMethod: string
{
    // JSON-RPC, POSTed to /json_rpc.
    case GetInfo = 'get_info';
    case GetVersion = 'get_version';
    case GetLastBlockHeader = 'get_last_block_header';
    case GetBlockHeaderByHeight = 'get_block_header_by_height';
    case GetBlockHeaderByHash = 'get_block_header_by_hash';
    case GetBlockHeadersRange = 'get_block_headers_range';
    case GetBlock = 'get_block';
    case HardForkInfo = 'hard_fork_info';
    case GetFeeEstimate = 'get_fee_estimate';
    case GetReserveInfo = 'get_reserve_info';
    case GetCirculatingSupply = 'get_circulating_supply';
    case GetAuditedSupply = 'get_audited_supply';

    // Plain paths, POSTed with a JSON body.
    case GetTransactions = '/get_transactions';
    case GetTransactionPoolHashes = '/get_transaction_pool_hashes';
    case GetTransactionPoolStats = '/get_transaction_pool_stats';

    public function isPlain(): bool
    {
        return str_starts_with($this->value, '/');
    }

    /** The path below the node's base url. */
    public function path(): string
    {
        return $this->isPlain() ? $this->value : '/json_rpc';
    }
}
