<?php

declare(strict_types=1);

namespace Lateo\Explorer\Web;

use Lateo\Explorer\Cache\CacheStatus;
use Lateo\Explorer\Cache\FileCache;
use Lateo\Explorer\Config\Config;
use Lateo\Explorer\Indexer\IndexerException;
use Lateo\Explorer\Rpc\ErrorCategory;
use Lateo\Explorer\Rpc\RpcClient;
use Lateo\Explorer\Rpc\RpcException;
use Lateo\Explorer\Rpc\RpcMethod;
use Lateo\Explorer\Rpc\RpcResult;
use Lateo\Explorer\Indexer\HeaderParser;

/**
 * The few things a page reads live from a node (rule: the database first, live data only
 * through the shared cache and the web profile's budget). One instance serves one page
 * request and makes one RPC client, lazily.
 *
 * - Every live value is read through FileCache::remember(), so concurrent requests share it
 *   and a failing node never makes a page fail: the result says Unreachable, Busy or Mismatch
 *   and the page words it. A limit on live calls (the page budget, the fallback cap or the
 *   own-node cap, RpcException category Budget) is Busy, "Temporarily unavailable".
 * - A node that fails to connect or times out gets a marker in the cache for SKIP_SECONDS;
 *   page requests do not ask it again meanwhile. The indexer never reads these markers.
 * - Nodes are asked by label, own nodes first (settings order), then the fallback. The labels
 *   never leave this class: results carry the names "Own node 1", "Own node 2" and
 *   "node.lateo.dev".
 */
final class LiveData
{
    public const TIP_TTL = 10;
    public const STATUS_TTL = 15;
    public const SKIP_SECONDS = 30;
    public const TXS_UNSETTLED_TTL = 30;
    public const TXS_SETTLED_TTL = 86400;

    public const FALLBACK_NAME = 'node.lateo.dev';

    private ?RpcClient $client = null;

    public function __construct(
        private readonly Config $config,
        private readonly LiveClientFactory $factory,
        /** Null when the cache directory cannot be used: then nothing is read live. */
        private readonly ?FileCache $cache,
    ) {
    }

    /** RPC attempts this request has made so far. */
    public function attempts(): int
    {
        return $this->client?->attempts() ?? 0;
    }

    /**
     * The tip of the chain as a node reports it (cached 10 s): height, hash and time of the
     * last block. One call to the first node that answers.
     *
     * @return LiveResult value: array{height:int,hash:string,timestamp:int}
     */
    public function tip(): LiveResult
    {
        return $this->cached('tip', self::TIP_TTL, function (): array {
            $header = HeaderParser::parse($this->callAny(RpcMethod::GetLastBlockHeader)->data['block_header'] ?? null);
            return ['height' => $header->height, 'hash' => $header->hash, 'timestamp' => $header->timestamp];
        });
    }

    /**
     * The hashes of the transactions of one block, from one get_block by height. The answer is
     * accepted only when its block hash equals the indexed one and it lists as many
     * transactions as the index counted. Cached 30 s while the block is unsettled, one day
     * after.
     *
     * @return LiveResult value: list<string>
     */
    public function blockTransactions(int $height, string $hash, int $numTxes, bool $settled): LiveResult
    {
        return $this->cached(
            'txs:' . $hash,
            $settled ? self::TXS_SETTLED_TTL : self::TXS_UNSETTLED_TTL,
            function () use ($height, $hash, $numTxes): array {
                $data = $this->callAny(RpcMethod::GetBlock, ['height' => $height])->data;
                $header = $data['block_header'] ?? null;
                if (!is_array($header) || ($header['hash'] ?? null) !== $hash) {
                    throw new LiveMismatch('the node answered about another block');
                }
                $txs = $data['tx_hashes'] ?? [];
                if (!is_array($txs) || !array_is_list($txs) || count($txs) !== $numTxes) {
                    throw new LiveMismatch('the node lists another number of transactions');
                }
                foreach ($txs as $tx) {
                    if (!is_string($tx) || preg_match('/\A[0-9a-f]{64}\z/', $tx) !== 1) {
                        throw new LiveMismatch('a transaction hash is malformed');
                    }
                }
                return $txs;
            },
        );
    }

    /**
     * The top block of every node, one call each, by label (cached 15 s as one entry). A node
     * that fails, or is skipped after a recent failure, is listed as not reachable. A node that
     * was not asked because a limit on live calls was reached (the page budget or a shared cap)
     * is flagged "limited" instead: it is not marked as failed and gets no skip marker.
     *
     * @return LiveResult value: list<array{name:string,reachable:bool,height:?int,hash:?string,skipped:bool,limited:bool}>
     */
    public function nodes(): LiveResult
    {
        return $this->cached('status', self::STATUS_TTL, function (): array {
            $rows = [];
            $client = $this->client();
            $own = 0;
            foreach ($this->labels() as $label => $isFallback) {
                $name = $isFallback ? self::FALLBACK_NAME : 'Own node ' . (++$own);
                $row = [
                    'name' => $name,
                    'reachable' => false,
                    'height' => null,
                    'hash' => null,
                    'skipped' => false,
                    'limited' => false,
                ];
                if ($this->skipped($label)) {
                    $row['skipped'] = true;
                    $rows[] = $row;
                    continue;
                }
                try {
                    $header = HeaderParser::parse($client->call(RpcMethod::GetLastBlockHeader, [], true, $label)->data['block_header'] ?? null);
                    $row['reachable'] = true;
                    $row['height'] = $header->height;
                    $row['hash'] = $header->hash;
                } catch (RpcException $e) {
                    if ($e->category === ErrorCategory::Budget) {
                        // A limit of ours, not a failure of the node: no skip marker, and the
                        // node is not called unreachable.
                        $row['limited'] = true;
                    } else {
                        $this->noteFailure($label, $e);
                    }
                } catch (IndexerException) {
                    // An answer that does not parse counts as not reachable.
                }
                $rows[] = $row;
            }
            return $rows;
        });
    }

    /** Whether page requests currently skip the node with this label. */
    public function skipped(string $label): bool
    {
        try {
            return $this->cache?->get('skip:' . $label) !== null;
        } catch (\RuntimeException) {
            return false;
        }
    }

    private function client(): RpcClient
    {
        return $this->client ??= $this->factory->make();
    }

    /** @return array<string,bool> node labels in the order they are asked, mapped to "is the fallback" */
    private function labels(): array
    {
        $out = [];
        foreach ($this->config->ownNodes() as $n) {
            $out[$n->label] = false;
        }
        foreach ($this->config->fallbackNodes() as $n) {
            $out[$n->label] = true;
        }
        return $out;
    }

    /**
     * One call, to the first node that is not skipped and answers. A failed connection or
     * timeout leaves a marker; the budget ending stops the loop.
     *
     * @param array<string,mixed> $params
     * @throws RpcException
     */
    private function callAny(RpcMethod $method, array $params = []): RpcResult
    {
        $client = $this->client();
        $last = null;
        foreach (array_keys($this->labels()) as $label) {
            if ($this->skipped($label)) {
                continue;
            }
            try {
                return $client->call($method, $params, true, $label);
            } catch (RpcException $e) {
                if ($e->category === ErrorCategory::Budget) {
                    throw $e;
                }
                $this->noteFailure($label, $e);
                $last = $e;
            }
        }
        throw $last ?? new RpcException(ErrorCategory::Missing, null, 'every node is skipped');
    }

    private function noteFailure(string $label, RpcException $e): void
    {
        if ($e->category !== ErrorCategory::Connect && $e->category !== ErrorCategory::Timeout) {
            return;
        }
        try {
            $this->cache?->set('skip:' . $label, 1, self::SKIP_SECONDS);
        } catch (\RuntimeException) {
            // Without a marker the node is simply asked again by the next request.
        }
    }

    /** @param \Closure():mixed $producer */
    private function cached(string $key, int $ttl, \Closure $producer): LiveResult
    {
        if ($this->cache === null) {
            return new LiveResult(LiveState::Busy);
        }
        try {
            $r = $this->cache->remember($key, $ttl, $producer);
        } catch (LiveMismatch) {
            return new LiveResult(LiveState::Mismatch);
        } catch (RpcException $e) {
            // A limit of ours (page budget, fallback cap, own-node cap) is "temporarily
            // unavailable", not an unreachable node.
            return new LiveResult($e->category === ErrorCategory::Budget ? LiveState::Busy : LiveState::Unreachable);
        } catch (IndexerException | \RuntimeException) {
            return new LiveResult(LiveState::Unreachable);
        }
        return match ($r->status) {
            CacheStatus::Busy => new LiveResult(LiveState::Busy),
            CacheStatus::Stale => new LiveResult(LiveState::Ok, $r->value, true),
            default => new LiveResult(LiveState::Ok, $r->value),
        };
    }
}
