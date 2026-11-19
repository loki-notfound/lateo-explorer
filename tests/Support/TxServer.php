<?php

declare(strict_types=1);

namespace Lateo\Explorer\Tests\Support;

use Lateo\Explorer\Rpc\TransportResponse;

/**
 * Serves get_block and /get_transactions for the blocks of a FakeChain, besides what the
 * chain itself serves (headers). The transactions are synthetic: the number in a block is the
 * header's num_txes, the hash is hash('sha256', "tx:<height>:<idx>"), and each is a plain
 * transfer unless a test replaces the entry with $entryFor.
 *
 * Tests change what is served with the public closures; every call is counted.
 */
final class TxServer
{
    /** @var (\Closure(int, array<string,mixed>): array<string,mixed>)|null changes the result of get_block */
    public ?\Closure $mutateBlock = null;
    /** @var (\Closure(list<string>, array<string,mixed>): array<string,mixed>)|null changes the answer of /get_transactions */
    public ?\Closure $mutateTransactions = null;
    /** @var (\Closure(string, int, int): array<string,mixed>)|null builds the entry of one transaction (hash, height, idx) */
    public ?\Closure $entryFor = null;

    /** @var array<string,int> */
    public array $served = [];
    /** Sizes of the hash lists asked of /get_transactions, in order. @var list<int> */
    public array $batches = [];
    /** @var array<string,array{0:int,1:int}> position of each hash */
    private array $position = [];

    public function __construct(private readonly FakeChain $chain)
    {
        for ($h = 0; $h <= $chain->topHeight(); $h++) {
            if (!$chain->has($h)) {
                continue;
            }
            $n = (int) $chain->at($h)['num_txes'];
            for ($i = 0; $i < $n; $i++) {
                $this->position[self::hashOf($h, $i)] = [$h, $i];
            }
        }
    }

    public static function hashOf(int $height, int $idx): string
    {
        return hash('sha256', 'tx:' . $height . ':' . $idx);
    }

    /** The as_json string of a transaction; $o replaces members of the default (a plain ZPH transfer). @param array<string,mixed> $o */
    public static function asJson(array $o = []): string
    {
        $key = static fn (string $asset): array => ['key' => [
            'amount' => 0, 'asset_type' => $asset, 'key_offsets' => [1, 2, 3], 'k_image' => str_repeat('cd', 32),
        ]];
        $out = static fn (string $asset): array => ['amount' => 0, 'target' => ['tagged_key' => [
            'key' => str_repeat('ef', 32), 'asset_type' => $asset, 'view_tag' => '03',
        ]]];
        $tx = $o + [
            'version' => 3,
            'unlock_time' => 0,
            'vin' => [$key('ZPH')],
            'vout' => [$out('ZPH'), $out('ZPH')],
            'extra' => [1, 2, 3],
            'pricing_record_height' => 0,
            'amount_burnt' => 0,
            'amount_minted' => 0,
            'rct_signatures' => ['type' => 6, 'txnFee' => 1000, 'ecdhInfo' => [], 'outPk' => []],
        ];
        $json = json_encode($tx, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        // A value written as "#123" is a number that PHP's integers cannot hold.
        return (string) preg_replace('/"#(\d+)"/', '$1', $json);
    }

    /** One input of the "key" variant. @return array<string,mixed> */
    public static function input(string $asset): array
    {
        return ['key' => ['amount' => 0, 'asset_type' => $asset, 'key_offsets' => [1], 'k_image' => str_repeat('cd', 32)]];
    }

    /** One output of the "tagged_key" variant. @return array<string,mixed> */
    public static function output(string $asset): array
    {
        return ['amount' => 0, 'target' => ['tagged_key' => ['key' => str_repeat('ef', 32), 'asset_type' => $asset, 'view_tag' => '03']]];
    }

    /** One entry of "txs", as the daemon sends it with prune. @return array<string,mixed> */
    public static function entry(string $hash, int $height, ?string $asJson = null): array
    {
        return [
            'tx_hash' => $hash,
            'as_json' => $asJson ?? self::asJson(),
            'as_hex' => '',
            'pruned_as_hex' => '',
            'prunable_as_hex' => '',
            'prunable_hash' => str_repeat('00', 32),
            'in_pool' => false,
            'double_spend_seen' => false,
            'block_height' => $height,
            'block_timestamp' => 1_700_000_000,
            'confirmations' => 5,
            'output_indices' => [],
            'asset_type_output_indices' => [],
        ];
    }

    public function respond(RecordedCall $call): TransportResponse
    {
        if ($call->method === 'get_block') {
            $this->served['get_block'] = ($this->served['get_block'] ?? 0) + 1;
            $height = (int) $call->params['height'];
            $header = $this->chain->at($height);
            $hashes = [];
            for ($i = 0; $i < (int) $header['num_txes']; $i++) {
                $hashes[] = self::hashOf($height, $i);
            }
            $result = ['block_header' => $header, 'miner_tx_hash' => $header['miner_tx_hash'], 'tx_hashes' => $hashes];
            if ($this->mutateBlock !== null) {
                $result = ($this->mutateBlock)($height, $result);
            }
            return new TransportResponse(200, self::ok($result));
        }
        if ($call->method === '/get_transactions') {
            $this->served['/get_transactions'] = ($this->served['/get_transactions'] ?? 0) + 1;
            /** @var list<string> $asked */
            $asked = $call->params['txs_hashes'];
            $this->batches[] = count($asked);
            $txs = [];
            foreach ($asked as $hash) {
                [$height, $idx] = $this->position[$hash] ?? [0, 0];
                $txs[] = $this->entryFor !== null
                    ? ($this->entryFor)($hash, $height, $idx)
                    : self::entry($hash, $height);
            }
            $body = ['status' => 'OK', 'untrusted' => false, 'txs' => $txs, 'missed_tx' => []];
            if ($this->mutateTransactions !== null) {
                $body = ($this->mutateTransactions)($asked, $body);
            }
            return new TransportResponse(200, json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }
        return $this->chain->respond($call);
    }

    /** @param array<string,mixed> $result */
    private static function ok(array $result): string
    {
        return json_encode(
            ['id' => '0', 'jsonrpc' => '2.0', 'result' => ['status' => 'OK', 'untrusted' => false] + $result],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
    }
}
