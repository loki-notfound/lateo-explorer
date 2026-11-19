<?php

declare(strict_types=1);

namespace Lateo\Explorer\Tests;

use Lateo\Explorer\Config\Config;
use Lateo\Explorer\Rpc\CallLimiter;
use Lateo\Explorer\Rpc\Profile;
use Lateo\Explorer\Rpc\RequestLimits;
use Lateo\Explorer\Rpc\RpcClient;
use Lateo\Explorer\Tests\Support\FakeClock;
use Lateo\Explorer\Tests\Support\FixtureTransport;
use Lateo\Explorer\Tests\Support\TestCase;

/**
 * The responses of the public fallback node recorded on 2026-11-19 (tests/fixtures/rpc), read
 * through the real client. They pin down the shapes the explorer will rely on.
 */
final class RecordedFixturesTest extends TestCase
{
    private FixtureTransport $transport;
    private RpcClient $client;

    public function setUp(): void
    {
        $config = Config::load(__DIR__ . '/../settings.example.php');
        $this->transport = new FixtureTransport();
        $this->client = new RpcClient(
            $config,
            $this->transport,
            Profile::Indexer,
            CallLimiter::fallback($this->scratch(), 30, new FakeClock()),
            new FakeClock(),
        );
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    private function data(string $method, array $params = []): array
    {
        return $this->client->call($method, $params)->data;
    }

    public function testGetInfo(): void
    {
        $d = $this->data('get_info');
        $this->assertSame(910671, $d['height']);
        $this->assertSame(true, $d['restricted']);
        $this->assertSame('mainnet', $d['nettype']);
        $this->assertSame(120, $d['target']);
        $this->assertSame('18446744073709551615', $d['free_space']);
        $this->assertSame('64fc1da25acfdb419672897b86ba78cfb79e4175ad2c58e725a85acefd439c91', $d['top_block_hash']);
    }

    public function testGetVersionAndHardForkInfo(): void
    {
        $v = $this->data('get_version');
        $this->assertSame(196621, $v['version']);
        $f = $this->data('hard_fork_info');
        $this->assertSame(11, $f['version']);
        $this->assertSame(536000, $f['earliest_height']);
        $this->assertSame(true, $f['enabled']);
    }

    public function testTheFirstPricingRecordsAreAtHeight89300(): void
    {
        $d = $this->data('get_block_headers_range', ['start_height' => 89298, 'end_height' => 89302]);
        $headers = $d['headers'];
        $this->assertCount(5, $headers);
        $this->assertSame([89298, 89299, 89300, 89301, 89302], array_column($headers, 'height'));
        $this->assertSame([2, 2, 3, 3, 3], array_column($headers, 'major_version'));
        $this->assertSame(0, $headers[0]['pricing_record']['spot']);
        $this->assertSame(0, $headers[1]['pricing_record']['spot'], 'the record at 89299 is empty');
        $this->assertSame(3057468230000, $headers[2]['pricing_record']['spot'], 'the first record');
        $this->assertSame(1696152427, $headers[2]['pricing_record']['timestamp'], '2023-10-01 09:27:07 UTC');
        for ($i = 1; $i < 5; $i++) {
            $this->assertSame($headers[$i - 1]['hash'], $headers[$i]['prev_hash'], 'hash chain at ' . $headers[$i]['height']);
        }
    }

    public function testHardFork11StartsAtHeight536000(): void
    {
        $d = $this->data('get_block_headers_range', ['start_height' => 535998, 'end_height' => 536002]);
        $this->assertSame([10, 10, 11, 11, 11], array_column($d['headers'], 'major_version'));
        $this->assertSame(1921656672716101217, $d['headers'][2]['reward'], 'the one-time mint of the fork block');
        $block = $this->data('get_block', ['height' => 536000]);
        $this->assertSame(536000, $block['block_header']['height']);
        $this->assertSame(0, $block['block_header']['num_txes']);
        $this->assertFalse(array_key_exists('tx_hashes', $block), 'a block without transactions has no tx_hashes');
        $this->assertSame('33ee82a6ee048d66355eac94d61e86471f4f1ff703cf7769fcada0bd36b28d6f', $block['block_header']['hash']);
    }

    public function testBlockHeaderByHeightAndByHashAgree(): void
    {
        $byHeight = $this->data('get_block_header_by_height', ['height' => 360000])['block_header'];
        $this->assertSame(6, $byHeight['major_version'], 'hard fork 6 starts here');
        $this->assertSame(1000000000000, $byHeight['pricing_record']['yield_price']);
        $byHash = $this->data('get_block_header_by_hash', ['hash' => $byHeight['hash']])['block_header'];
        $this->assertSame($byHeight, $byHash);
    }

    public function testLastBlockHeaderIsOneBelowTheInfoHeight(): void
    {
        $header = $this->data('get_last_block_header')['block_header'];
        $this->assertSame($this->data('get_info')['height'] - 1, $header['height']);
        $this->assertFalse($header['orphan_status']);
    }

    public function testABlockWithTransactionsListsTheirHashes(): void
    {
        $d = $this->data('get_block', ['height' => 910657]);
        $this->assertSame(6, $d['block_header']['num_txes']);
        $this->assertCount(6, $d['tx_hashes']);
        $this->assertSame('9cfd6c88ec9bd3aff3cbbd2e3693bd1bad80eef6baf122df1a89f26549834c13', $d['tx_hashes'][0]);
    }

    public function testAConversionTransaction(): void
    {
        $hash = '64b3f44e2521b677a24eaaa6bcc3b795e0637f92e8b2b1b12e13982aca95d8c8';
        $block = $this->data('get_block', ['height' => 905998]);
        $this->assertSame([$hash], $block['tx_hashes']);

        $d = $this->data('/get_transactions', ['txs_hashes' => [$hash], 'decode_as_json' => true]);
        $this->assertCount(1, $d['txs']);
        $tx = $d['txs'][0];
        $this->assertSame($hash, $tx['tx_hash']);
        $this->assertSame(905998, $tx['block_height']);
        $this->assertFalse($tx['in_pool']);
        $json = json_decode($d['txs_as_json'][0], true, 512, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
        $this->assertSame(3, $json['version']);
        $this->assertSame(905996, $json['pricing_record_height']);
        $this->assertSame(1000000000000000, $json['amount_burnt'], '1000 ZSD burnt');
        $this->assertSame(8566240310000000, $json['amount_minted']);
        $this->assertSame('ZSD', $json['vin'][0]['key']['asset_type']);
        $assets = array_map(static fn (array $o): string => $o['target']['tagged_key']['asset_type'], $json['vout']);
        $this->assertSame(['ZPH', 'ZPH', 'ZSD', 'ZSD'], $assets);
        $this->assertSame(6, $json['rct_signatures']['type']);
        $this->assertSame(49844876, $json['rct_signatures']['txnFee']);
    }

    public function testReserveAndSupply(): void
    {
        $r = $this->data('get_reserve_info');
        $this->assertSame(910671, $r['height']);
        $this->assertSame(11, $r['hf_version']);
        $this->assertSame('342946999946683133', $r['liabilities']);
        $this->assertSame(1971054120000, $r['pr']['reserve_ratio']);

        foreach (['get_circulating_supply', 'get_audited_supply'] as $method) {
            $tally = $this->data($method)['supply_tally'];
            $this->assertSame(['ZPH', 'ZSD', 'ZRS', 'ZYS', 'DJED', 'YIELD'], array_column($tally, 'currency_label'));
            $this->assertSame('12406707323459691262', $tally[0]['amount'], 'above PHP_INT_MAX and exact');
        }
    }

    public function testMempoolAndFee(): void
    {
        $hashes = $this->data('/get_transaction_pool_hashes');
        $this->assertFalse(array_key_exists('tx_hashes', $hashes), 'an empty pool has no tx_hashes');
        $stats = $this->data('/get_transaction_pool_stats')['pool_stats'];
        $this->assertSame(0, $stats['txs_total']);
        $fee = $this->data('get_fee_estimate');
        $this->assertSame([200000, 790000, 3200000, 40000000], $fee['fees']);
        $this->assertSame(10000, $fee['quantization_mask']);
    }

    public function testAGatedRouteAnswers404(): void
    {
        $r = $this->transport->post('https://node.lateo.dev/get_transaction_pool', '{}', new RequestLimits(1.0, 3.0, 1024));
        $this->assertSame(404, $r->status);
        $this->assertSame('', $r->body);
    }

    public function testAMissingFixtureIsATestError(): void
    {
        $this->assertThrows(\LogicException::class, fn () => $this->data('get_block', ['height' => 1]));
    }
}
