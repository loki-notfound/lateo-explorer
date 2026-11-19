<?php

declare(strict_types=1);

namespace Lateo\Explorer\Tests;

use Lateo\Explorer\Chain\Generation;
use Lateo\Explorer\Indexer\FailureKind;
use Lateo\Explorer\Indexer\IndexerException;
use Lateo\Explorer\Indexer\Transaction;
use Lateo\Explorer\Indexer\TransactionParser;
use Lateo\Explorer\Tests\Support\FixtureTransport;
use Lateo\Explorer\Tests\Support\TestCase;
use Lateo\Explorer\Tests\Support\TxServer;

/**
 * The transaction parser, on the transactions recorded from node.lateo.dev (tests/fixtures/rpc,
 * recorded 2026-11-19, nothing edited) and on synthetic shapes.
 */
final class TransactionParserTest extends TestCase
{
    /** @return array<string,mixed> */
    private function fixture(string $name): array
    {
        $file = __DIR__ . '/fixtures/rpc/' . $name . '.json';
        $d = json_decode((string) file_get_contents($file), true, 512, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
        return $d;
    }

    /**
     * The parsed transactions of the block at $height, as recorded.
     *
     * @return list<Transaction>
     */
    private function recorded(int $height): array
    {
        $block = $this->fixture('get_block__height-' . $height)['result'];
        $hashes = $block['tx_hashes'];
        $name = FixtureTransport::fixtureName('/get_transactions', ['txs_hashes' => $hashes, 'decode_as_json' => true, 'prune' => true]);
        $txs = $this->fixture($name)['txs'];
        $this->assertSame($block['block_header']['num_txes'], count($txs));
        $out = [];
        foreach ($txs as $i => $entry) {
            $out[] = TransactionParser::parse($entry, $hashes[$i], $height, $i);
        }
        return $out;
    }

    /** @param array<string,mixed> $o */
    private function synthetic(array $o = []): Transaction
    {
        $hash = str_repeat('a1', 32);
        return TransactionParser::parse(TxServer::entry($hash, 7, TxServer::asJson($o)), $hash, 7, 3);
    }

    private function invalid(string $json, string $expected, ?string $hash = null): void
    {
        $hash ??= str_repeat('a1', 32);
        $e = $this->assertThrows(IndexerException::class, fn () => TransactionParser::parse(TxServer::entry($hash, 7, $json), $hash, 7, 0));
        $this->assertSame(FailureKind::InvalidData, $e->kind);
        $this->assertStringContains($expected, $e->getMessage());
    }

    // --- recorded transactions ----------------------------------------------------------------

    public function testATransferBeforeHardFork3(): void
    {
        $txs = $this->recorded(40010);
        $this->assertCount(1, $txs);
        $t = $txs[0];
        $this->assertSame('02380419f0265982d716028db673698302b63d48187db77079defec4f240b961', $t->hash);
        $this->assertSame(40010, $t->height);
        $this->assertSame(0, $t->idx);
        $this->assertSame(2, $t->version);
        $this->assertSame(6, $t->rctType);
        $this->assertSame('transfer', $t->type);
        $this->assertSame('ZPH', $t->source->ticker);
        $this->assertSame(Generation::Old, $t->source->generation, 'the chain said ZEPH');
        $this->assertSame('ZPH', $t->destination->ticker);
        $this->assertSame(Generation::Old, $t->destination->generation);
        $this->assertSame(35, $t->inputs);
        $this->assertSame(2, $t->outputs);
        $this->assertSame(13580600000, $t->fee);
        $this->assertSame([0, 0, 0], [$t->pricingRecordHeight, $t->amountBurnt, $t->amountMinted]);
    }

    public function testARedeemOfReserveOfHardFork3(): void
    {
        $txs = $this->recorded(120974);
        $this->assertCount(19, $txs);
        $types = array_count_values(array_map(static fn (Transaction $t): string => $t->type, $txs));
        $this->assertSame(['transfer' => 18, 'redeem_reserve' => 1], $types);
        $t = $txs[1];
        $this->assertSame('4452814b97df58f31e64900582b2824ac0614663fa14202251b5e1de6f06ad77', $t->hash);
        $this->assertSame(1, $t->idx);
        $this->assertSame('redeem_reserve', $t->type);
        $this->assertSame(['ZRS', Generation::Old, 'ZPH', Generation::Old], [$t->source->ticker, $t->source->generation, $t->destination->ticker, $t->destination->generation]);
        $this->assertSame([2, 4, 1688642164], [$t->inputs, $t->outputs, $t->fee]);
        $this->assertSame([120972, 9464329000000000, 8612217981387160], [$t->pricingRecordHeight, $t->amountBurnt, $t->amountMinted]);
        // The 145-input transfers are read whole.
        $this->assertSame([145, 2], [$txs[5]->inputs, $txs[5]->outputs]);
        foreach ($txs as $i => $x) {
            $this->assertSame($i, $x->idx);
            $this->assertSame(3, $x->version);
        }
    }

    public function testMintsOfReserveOfHardFork5(): void
    {
        $txs = $this->recorded(295006);
        $this->assertCount(22, $txs);
        $types = array_count_values(array_map(static fn (Transaction $t): string => $t->type, $txs));
        $this->assertSame(['transfer' => 20, 'mint_reserve' => 2], $types);
        $a = $txs[1];
        $this->assertSame('14c5892aedea6cc8f7e689982fc547240b158b8ab8509817a1e85bf594a0d4b7', $a->hash);
        $this->assertSame(['ZPH', 'ZRS'], [$a->source->ticker, $a->destination->ticker]);
        $this->assertSame([295004, 536800000000, 402263104320, 1040000000], [$a->pricingRecordHeight, $a->amountBurnt, $a->amountMinted, $a->fee]);
        $b = $txs[21];
        $this->assertSame([295004, 10530000000000000, 7890891372000000, 1012880000], [$b->pricingRecordHeight, $b->amountBurnt, $b->amountMinted, $b->fee]);
        $this->assertSame([1, 4], [$b->inputs, $b->outputs]);
    }

    public function testAMintOfYieldOfHardFork6(): void
    {
        $txs = $this->recorded(400878);
        $this->assertCount(20, $txs);
        $types = array_count_values(array_map(static fn (Transaction $t): string => $t->type, $txs));
        $this->assertSame(['transfer' => 19, 'mint_yield' => 1], $types);
        $t = $txs[19];
        $this->assertSame('2778cd5023a559249a3113bcff55b23cad41d4c64b090513ebfb917c6b96f161', $t->hash);
        $this->assertSame(['ZSD', Generation::Old, 'ZYS', Generation::Old], [$t->source->ticker, $t->source->generation, $t->destination->ticker, $t->destination->generation]);
        $this->assertSame([400876, 3200000000000, 2737064512000, 3638031123], [$t->pricingRecordHeight, $t->amountBurnt, $t->amountMinted, $t->fee]);
    }

    public function testAuditsOfHardFork8AreOneTypeAndKeepTheGenerations(): void
    {
        $txs = $this->recorded(481502);
        $this->assertCount(2, $txs);
        foreach ($txs as $t) {
            $this->assertSame('audit', $t->type);
            $this->assertSame(['ZPH', Generation::Old, 'ZPH', Generation::New], [$t->source->ticker, $t->source->generation, $t->destination->ticker, $t->destination->generation]);
            $this->assertSame(0, $t->pricingRecordHeight, 'an audit has no pricing record');
            $this->assertSame($t->amountBurnt, $t->amountMinted, 'at a rate of exactly 1');
        }
        $this->assertSame([300000000000000, 1104450000, 2, 4], [$txs[0]->amountBurnt, $txs[0]->fee, $txs[0]->inputs, $txs[0]->outputs]);
        $this->assertSame([2012245392460000, 33167540000, 128, 4], [$txs[1]->amountBurnt, $txs[1]->fee, $txs[1]->inputs, $txs[1]->outputs]);
    }

    public function testAConversionOfHardFork11InTheNewNamesAlsoParsesWithoutPrune(): void
    {
        // The earlier fixture of block 905998 was recorded without prune (it has rctsig_prunable).
        $hash = '64b3f44e2521';
        $d = $this->fixture('get_transactions__decode_as_json-true__txs_hashes-' . $hash);
        $entry = $d['txs'][0];
        $t = TransactionParser::parse($entry, $entry['tx_hash'], 905998, 0);
        $this->assertSame('redeem_stable', $t->type);
        $this->assertSame(['ZSD', Generation::New, 'ZPH', Generation::New], [$t->source->ticker, $t->source->generation, $t->destination->ticker, $t->destination->generation]);
        $this->assertSame([905996, 1000000000000000, 8566240310000000, 49844876], [$t->pricingRecordHeight, $t->amountBurnt, $t->amountMinted, $t->fee]);
    }

    // --- the pairs of the daemon --------------------------------------------------------------

    public function testEveryPairOfTheDaemonGivesItsType(): void
    {
        $pairs = [
            ['ZEPH', 'ZEPH', 'transfer'], ['ZEPHUSD', 'ZEPHUSD', 'transfer'], ['ZEPHRSV', 'ZEPHRSV', 'transfer'], ['ZYIELD', 'ZYIELD', 'transfer'],
            ['ZPH', 'ZPH', 'transfer'], ['ZSD', 'ZSD', 'transfer'], ['ZRS', 'ZRS', 'transfer'], ['ZYS', 'ZYS', 'transfer'],
            ['ZEPH', 'ZPH', 'audit'], ['ZEPHUSD', 'ZSD', 'audit'], ['ZEPHRSV', 'ZRS', 'audit'], ['ZYIELD', 'ZYS', 'audit'],
            ['ZEPH', 'ZEPHUSD', 'mint_stable'], ['ZPH', 'ZSD', 'mint_stable'],
            ['ZEPHUSD', 'ZEPH', 'redeem_stable'], ['ZSD', 'ZPH', 'redeem_stable'],
            ['ZEPH', 'ZEPHRSV', 'mint_reserve'], ['ZPH', 'ZRS', 'mint_reserve'],
            ['ZEPHRSV', 'ZEPH', 'redeem_reserve'], ['ZRS', 'ZPH', 'redeem_reserve'],
            ['ZEPHUSD', 'ZYIELD', 'mint_yield'], ['ZSD', 'ZYS', 'mint_yield'],
            ['ZYIELD', 'ZEPHUSD', 'redeem_yield'], ['ZYS', 'ZSD', 'redeem_yield'],
        ];
        foreach ($pairs as [$from, $to, $type]) {
            $t = $this->synthetic([
                'vin' => [TxServer::input($from)],
                'vout' => $from === $to ? [TxServer::output($from), TxServer::output($from)] : [TxServer::output($from), TxServer::output($to)],
            ]);
            $this->assertSame($type, $t->type, $from . ' to ' . $to);
            $this->assertTrue($t->source->isKnown() && $t->destination->isKnown(), $from . ' to ' . $to);
        }
    }

    public function testPairsTheDaemonRejectsAreUnknownNeverAGuess(): void
    {
        $rejected = [
            ['ZEPH', 'ZSD'], ['ZPH', 'ZEPHUSD'], ['ZEPHUSD', 'ZPH'], ['ZSD', 'ZEPH'], // generations are not mixed
            ['ZEPH', 'ZYIELD'], ['ZPH', 'ZYS'], ['ZEPHRSV', 'ZYIELD'], ['ZRS', 'ZSD'], ['ZSD', 'ZRS'],
            ['ZPH', 'ZEPH'], ['ZSD', 'ZEPHUSD'], // the new name back to the old one is not an audit
            ['ZYS', 'ZPH'], ['ZPH', 'ZYS'],
        ];
        foreach ($rejected as [$from, $to]) {
            $t = $this->synthetic(['vin' => [TxServer::input($from)], 'vout' => [TxServer::output($from), TxServer::output($to)]]);
            $this->assertSame('unknown', $t->type, $from . ' to ' . $to);
            $this->assertTrue($t->source->isKnown(), 'both names are on the list, so the assets stay known: ' . $from);
            $this->assertTrue($t->destination->isKnown(), $to);
        }
    }

    public function testShapesTheDaemonWouldNotDeriveAnAssetPairForAreUnknown(): void
    {
        $o = static fn (string ...$a): array => array_map(static fn (string $x): array => TxServer::output($x), $a);

        // Two source assets.
        $t = $this->synthetic(['vin' => [TxServer::input('ZPH'), TxServer::input('ZSD')], 'vout' => $o('ZPH', 'ZSD')]);
        $this->assertSame(['unknown', 'unknown', 'unknown'], [$t->type, $t->source->ticker, $t->destination->ticker]);
        // Three output assets.
        $t = $this->synthetic(['vin' => [TxServer::input('ZPH')], 'vout' => $o('ZPH', 'ZSD', 'ZRS')]);
        $this->assertSame(['unknown', 'ZPH', 'unknown'], [$t->type, $t->source->ticker, $t->destination->ticker]);
        // No output of the source asset.
        $t = $this->synthetic(['vin' => [TxServer::input('ZPH')], 'vout' => $o('ZSD', 'ZRS')]);
        $this->assertSame(['unknown', 'ZPH', 'unknown'], [$t->type, $t->source->ticker, $t->destination->ticker]);
        // One output asset that is not the source (a conversion without change).
        $t = $this->synthetic(['vin' => [TxServer::input('ZPH')], 'vout' => $o('ZSD', 'ZSD')]);
        $this->assertSame(['unknown', 'ZPH', 'unknown'], [$t->type, $t->source->ticker, $t->destination->ticker]);
    }

    public function testAnUnknownAssetStringIsUnknownAndNeverKept(): void
    {
        $evil = '<script>alert(1)</script>';
        $t = $this->synthetic(['vin' => [TxServer::input($evil)], 'vout' => [TxServer::output($evil), TxServer::output($evil)]]);
        $this->assertSame(['unknown', 'unknown', 'unknown'], [$t->type, $t->source->ticker, $t->destination->ticker]);
        $this->assertSame(Generation::Unknown, $t->source->generation);
        $this->assertStringNotContains('script', serialize($t));

        // A known source and a destination string the chain does not know.
        $t = $this->synthetic(['vin' => [TxServer::input('ZPH')], 'vout' => [TxServer::output('ZPH'), TxServer::output('ZPHX')]]);
        $this->assertSame(['unknown', 'ZPH', 'unknown'], [$t->type, $t->source->ticker, $t->destination->ticker]);
        // Names are matched exactly: lower case is not a name.
        $t = $this->synthetic(['vin' => [TxServer::input('zph')], 'vout' => [TxServer::output('zph'), TxServer::output('zph')]]);
        $this->assertSame('unknown', $t->type);
    }

    public function testAnInputOfAnotherVariantAndAnOutputOfAnotherVariantAreUnknown(): void
    {
        $t = $this->synthetic(['vin' => [['gen' => ['height' => 5]]], 'vout' => [TxServer::output('ZPH'), TxServer::output('ZPH')]]);
        $this->assertSame(['unknown', 'unknown', 'unknown'], [$t->type, $t->source->ticker, $t->destination->ticker]);
        $this->assertSame([1, 2], [$t->inputs, $t->outputs]);

        $script = ['amount' => 0, 'target' => ['to_script' => ['script' => []]]];
        $t = $this->synthetic(['vin' => [TxServer::input('ZPH')], 'vout' => [TxServer::output('ZPH'), $script]]);
        $this->assertSame(['unknown', 'ZPH', 'unknown'], [$t->type, $t->source->ticker, $t->destination->ticker]);
    }

    public function testAValueIsStoredAsTheChainHasItEvenWhenTheDaemonWouldRefuseIt(): void
    {
        // A transfer with a pricing record height and amounts: not the daemon's rule, but recorded as it is.
        $t = $this->synthetic(['pricing_record_height' => 5, 'amount_burnt' => 7, 'amount_minted' => 9]);
        $this->assertSame('transfer', $t->type);
        $this->assertSame([5, 7, 9], [$t->pricingRecordHeight, $t->amountBurnt, $t->amountMinted]);
    }

    // --- exact numbers ------------------------------------------------------------------------

    public function testNumbersAbove2To53StayExact(): void
    {
        $t = $this->synthetic([
            'amount_burnt' => 9007199254740993, // 2^53 + 1
            'amount_minted' => 9223372036854775807, // 2^63 - 1
            'rct_signatures' => ['type' => 6, 'txnFee' => 9007199254740995],
        ]);
        $this->assertSame(9007199254740993, $t->amountBurnt);
        $this->assertSame(PHP_INT_MAX, $t->amountMinted);
        $this->assertSame(9007199254740995, $t->fee);
    }

    public function testNumbersAbove2To63AreRefusedNotRounded(): void
    {
        foreach (['amount_burnt', 'amount_minted', 'pricing_record_height'] as $field) {
            $json = TxServer::asJson([$field => '#9223372036854775808']);
            $this->assertStringContains(':9223372036854775808', $json);
            $this->invalid($json, $field . ' does not fit');
        }
        $this->invalid(TxServer::asJson(['rct_signatures' => ['type' => 6, 'txnFee' => '#18446744073709551615']]), 'txnFee does not fit');
        $this->invalid(TxServer::asJson(['amount_minted' => '#18446744073709551615']), 'amount_minted does not fit');
    }

    // --- malformed data -----------------------------------------------------------------------

    public function testAnEntryThatIsNotAnObjectOrHasAnotherHashIsRefused(): void
    {
        $hash = str_repeat('a1', 32);
        foreach ([null, 'x', 5, [1, 2]] as $entry) {
            $e = $this->assertThrows(IndexerException::class, fn () => TransactionParser::parse($entry, $hash, 7, 0));
            $this->assertSame(FailureKind::InvalidData, $e->kind);
        }
        $e = $this->assertThrows(IndexerException::class, fn () => TransactionParser::parse(TxServer::entry(str_repeat('b2', 32), 7), $hash, 7, 0));
        $this->assertStringContains('another hash', $e->getMessage());
        $entry = TxServer::entry($hash, 7);
        unset($entry['tx_hash']);
        $this->assertThrows(IndexerException::class, fn () => TransactionParser::parse($entry, $hash, 7, 0));
    }

    public function testAsJsonMustBeAStringOfAnObject(): void
    {
        $hash = str_repeat('a1', 32);
        foreach ([null, '', 5, ['version' => 3], '[1,2]', '"x"', '{', '{"version":3} extra', 'null'] as $bad) {
            $entry = TxServer::entry($hash, 7);
            $entry['as_json'] = $bad;
            $e = $this->assertThrows(IndexerException::class, fn () => TransactionParser::parse($entry, $hash, 7, 0));
            $this->assertSame(FailureKind::InvalidData, $e->kind);
        }
        $entry = TxServer::entry($hash, 7);
        unset($entry['as_json']);
        $this->assertThrows(IndexerException::class, fn () => TransactionParser::parse($entry, $hash, 7, 0));
    }

    public function testMissingOrMalformedFieldsAreRefused(): void
    {
        $good = json_decode(TxServer::asJson(), true, 512, JSON_THROW_ON_ERROR);
        foreach (['version', 'vin', 'vout', 'rct_signatures', 'pricing_record_height', 'amount_burnt', 'amount_minted'] as $field) {
            $tx = $good;
            unset($tx[$field]);
            $this->invalid((string) json_encode($tx), $field);
        }
        $tx = $good;
        unset($tx['rct_signatures']['txnFee']);
        $this->invalid((string) json_encode($tx), 'txnFee');
        $tx = $good;
        unset($tx['rct_signatures']['type']);
        $this->invalid((string) json_encode($tx), 'rct_signatures.type');
    }

    public function testValuesOfTheWrongTypeOrRangeAreRefused(): void
    {
        $cases = [
            ['version', 256, 'version'], ['version', -1, 'version'], ['version', 1.5, 'version'], ['version', '3', 'version'], ['version', null, 'version'],
            ['amount_burnt', -1, 'amount_burnt'], ['amount_burnt', 1.0, 'amount_burnt'], ['amount_burnt', true, 'amount_burnt'],
            ['amount_minted', 1e3, 'amount_minted'], ['pricing_record_height', 'x', 'pricing_record_height'],
            ['vin', [], 'vin'], ['vin', 'x', 'vin'], ['vin', ['key' => []], 'vin'],
            ['vout', [], 'vout'], ['vout', ['a' => 1], 'vout'], ['rct_signatures', [], 'rct_signatures'], ['rct_signatures', 5, 'rct_signatures'],
            ['rct_signatures', ['type' => 256, 'txnFee' => 1], 'rct_signatures.type'],
            ['rct_signatures', ['type' => 6, 'txnFee' => -5], 'txnFee'],
            ['rct_signatures', ['type' => 6, 'txnFee' => 1.5], 'txnFee'],
        ];
        foreach ($cases as [$field, $value, $expected]) {
            $this->invalid(TxServer::asJson([$field => $value]), $expected);
        }
    }

    public function testInputsAndOutputsOfTheWrongShapeAreRefused(): void
    {
        $this->invalid(TxServer::asJson(['vin' => ['x']]), 'input');
        $this->invalid(TxServer::asJson(['vin' => [['key' => 5]]]), 'input');
        $this->invalid(TxServer::asJson(['vin' => [['key' => ['amount' => 0], 'gen' => []]]]), 'input');
        $this->invalid(TxServer::asJson(['vin' => [['key' => ['amount' => -1, 'asset_type' => 'ZPH']]]]), 'input amount');
        $this->invalid(TxServer::asJson(['vout' => ['x']]), 'output');
        $this->invalid(TxServer::asJson(['vout' => [['amount' => 0]]]), 'output target');
        $this->invalid(TxServer::asJson(['vout' => [['amount' => 0, 'target' => ['tagged_key' => 5]]]]), 'output target');
        $this->invalid(TxServer::asJson(['vout' => [['amount' => 'x', 'target' => ['tagged_key' => ['asset_type' => 'ZPH']]]]]), 'output amount');
        $this->invalid(TxServer::asJson(['vout' => [['target' => ['tagged_key' => ['asset_type' => 'ZPH']]]]]), 'output amount');
        // A plaintext output amount that does not fit is refused too (a miner output would carry one).
        $this->invalid(TxServer::asJson(['vout' => [['amount' => '#9223372036854775808', 'target' => ['tagged_key' => ['asset_type' => 'ZPH']]]]]), 'output amount');
    }

    public function testNothingOfTheChainEntersAMessage(): void
    {
        $e = $this->assertThrows(IndexerException::class, fn () => TransactionParser::parse(
            TxServer::entry(str_repeat('a1', 32), 7, '{"version":"SECRET-VALUE"}'),
            str_repeat('a1', 32),
            7,
            0,
        ));
        $this->assertStringNotContains('SECRET', $e->getMessage());
    }
}
