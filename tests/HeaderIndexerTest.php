<?php

declare(strict_types=1);

namespace Lateo\Explorer\Tests;

use Lateo\Explorer\Indexer\HeaderIndexer;
use Lateo\Explorer\Indexer\RunMode;
use Lateo\Explorer\Indexer\RunSummary;
use Lateo\Explorer\Rpc\ErrorCategory;
use Lateo\Explorer\Rpc\TransportFailure;
use Lateo\Explorer\Tests\Support\FakeChain;
use Lateo\Explorer\Tests\Support\IndexerTestBase;

final class HeaderIndexerTest extends IndexerTestBase
{
    // --- recorded fixtures --------------------------------------------------------------------

    public function testStoresTheFirstPricingRecordsExactly(): void
    {
        $this->serve(FakeChain::fromFixture('get_block_headers_range__end_height-89302__start_height-89298'));
        $summary = $this->indexer($this->config(), 89298)->run();

        $this->assertSame(RunSummary::COMPLETE, $summary->outcome, $summary->note);
        $this->assertSame(RunMode::Tip, $summary->mode);
        $this->assertSame(5, $summary->headers);
        $this->assertSame(89298, $summary->fromHeight);
        $this->assertSame(89302, $summary->toHeight);
        $this->assertSame('own-a', $summary->node);
        $this->assertSame(3, $summary->requests, 'the tip of each own node and one range');

        $this->assertSame(5, $this->count('blocks'));
        $this->assertSame(3, $this->count('pricing_records'));
        $this->assertSame(0, (int) $this->db->value('SELECT count(*) FROM pricing_records WHERE height IN (89298, 89299)'), 'empty records are not stored');

        $b = $this->db->one('SELECT * FROM blocks WHERE height = 89298');
        $this->assertSame('f2763be914de7e5f7365ba1654b8d185b1d85b70bb1e44461b26e2d6a2fb6a3e', $b['hash']);
        $this->assertSame('f3dce843dabc82b30c43fd6ae56c3290ad8c542de73ebbcf063792b73727b972', $b['prev_hash']);
        $this->assertSame('83925787525', $b['difficulty'], 'decimal, from wide_difficulty');
        $this->assertSame('6079916834675091', $b['cumulative_difficulty']);
        $this->assertSame(15718138653615, $b['reward']);
        $this->assertSame(1696152293, $b['timestamp']);
        $this->assertSame(191, $b['block_weight']);
        $this->assertSame(2, $b['major_version']);
        $this->assertSame(3, $b['minor_version']);
        $this->assertSame(6997934, $b['nonce']);
        $this->assertSame('6b033dde4021c2e9caaae2cd3b35934665747b1174961c93a4e0891baa56dea4', $b['miner_tx_hash']);
        $this->assertSame(4, (int) $this->db->value('SELECT num_txes FROM blocks WHERE height = 89299'));
        $this->assertSame('integer', $this->db->value('SELECT typeof(reward) FROM blocks WHERE height = 89298'));
        $this->assertSame('text', $this->db->value('SELECT typeof(difficulty) FROM blocks WHERE height = 89298'));

        $p = $this->db->one('SELECT * FROM pricing_records WHERE height = 89300');
        $this->assertSame(3057468230000, $p['spot']);
        $this->assertSame(3064057300000, $p['moving_average']);
        $this->assertSame(327067990000, $p['stable']);
        $this->assertSame(326364650000, $p['stable_ma']);
        $this->assertSame(500000000000, $p['reserve']);
        $this->assertSame(500000000000, $p['reserve_ma']);
        $this->assertSame(0, $p['reserve_ratio']);
        $this->assertSame(0, $p['reserve_ratio_ma']);
        $this->assertSame(0, $p['yield_price']);
        $this->assertSame(1696152427, $p['timestamp']);
        $this->assertSame('abe7546762a54073d406ce7336af31de20711337152da4d703ec8b5dc0cf4b98afe8c3da770d685be42482a992c68151be6d6ca9e27578f1089732fdc7af7f45', $p['signature']);

        // Run again: nothing new, only the tips are asked for.
        $again = $this->indexer($this->config(), 89298)->run();
        $this->assertSame(RunSummary::COMPLETE, $again->outcome);
        $this->assertSame(0, $again->headers);
        $this->assertNull($again->fromHeight);
        $this->assertSame(2, $again->requests);
        $this->assertSame(5, $this->count('blocks'));
    }

    public function testStoresTheBlocksAroundHardFork11(): void
    {
        $this->serve(FakeChain::fromFixture('get_block_headers_range__end_height-536002__start_height-535998'));
        $summary = $this->indexer($this->config(), 535998)->run();
        $this->assertSame(RunSummary::COMPLETE, $summary->outcome, $summary->note);
        $this->assertSame(5, $this->count('blocks'));
        $this->assertSame(5, $this->count('pricing_records'));
        $this->assertSame([10, 10, 11, 11, 11], array_map('intval', array_column($this->db->all('SELECT major_version FROM blocks ORDER BY height'), 'major_version')));
        $this->assertSame(1921656672716101217, $this->db->value('SELECT reward FROM blocks WHERE height = 536000'));
        $this->assertSame('16533433182', $this->db->value('SELECT difficulty FROM blocks WHERE height = 536000'));
        $this->assertSame(1243287400000, $this->db->value('SELECT spot FROM pricing_records WHERE height = 536000'));
    }

    public function testARewardsMemberIsIgnored(): void
    {
        // Declared by the daemon, never filled: whatever it holds is not read and not stored.
        $chain = FakeChain::synthetic(1, 4);
        $h = $chain->at(2);
        $h['rewards'] = [['asset_type' => 'ZNEW', 'amount' => 70]];
        $chain->set(2, $h);
        $this->serve($chain);
        $summary = $this->indexer($this->config(), 1)->run();
        $this->assertSame(RunSummary::COMPLETE, $summary->outcome, $summary->note);
        $this->assertSame(4, $this->count('blocks'));
    }

    // --- validation: nothing of a bad batch is stored -------------------------------------------

    private function assertRunFails(FakeChain $chain, string $needle, string $kind = 'invalid-data'): RunSummary
    {
        $this->serve($chain);
        $summary = $this->indexer($this->config(), 1)->run();
        $this->assertSame(RunSummary::ERROR, $summary->outcome);
        $this->assertStringContains($kind, $summary->note);
        $this->assertStringContains($needle, $summary->note);
        $this->assertSame(0, $this->count('blocks'), 'a bad batch is not stored at all');
        $this->assertSame(0, $this->count('pricing_records'));
        $this->assertSame(0, $summary->headers);
        return $summary;
    }

    public function testRunFailsOnAShortBatch(): void
    {
        $chain = FakeChain::synthetic(1, 10);
        $chain->mutateHeaders(static fn (array $list): array => array_slice($list, 0, -1));
        $this->assertRunFails($chain, 'sent 9 headers, not 10');
    }

    public function testRunFailsOnABrokenLinkInsideABatch(): void
    {
        $chain = FakeChain::synthetic(1, 10);
        $chain->mutateHeaders(static function (array $list): array {
            $list[6]['prev_hash'] = FakeChain::hashOf('other', 6);
            return $list;
        });
        $this->assertRunFails($chain, 'does not link');
    }

    public function testRunFailsOnNonConsecutiveHeights(): void
    {
        $chain = FakeChain::synthetic(1, 10);
        $chain->mutateHeaders(static function (array $list): array {
            $list[3]['height'] = 99;
            return $list;
        });
        $this->assertRunFails($chain, 'not consecutive');
    }

    public function testRunFailsOnABadHash(): void
    {
        $chain = FakeChain::synthetic(1, 10);
        $chain->mutateHeaders(static function (array $list): array {
            $list[2]['hash'] = strtoupper($list[2]['hash']);
            return $list;
        });
        $this->assertRunFails($chain, 'hash is not 64 lower-case hex');
    }

    public function testRunFailsOnAUint64AboveTheSignedRange(): void
    {
        $chain = FakeChain::synthetic(1, 10);
        // A bare JSON number above PHP_INT_MAX, as a node sends a uint64: it decodes to a string.
        $chain->mutateBody(static fn (string $body): string => (string) preg_replace('/"num_txes":\d+/', '"num_txes":18446744073709551615', $body, 1));
        $this->assertRunFails($chain, 'num_txes does not fit a signed 64-bit integer');
    }

    public function testTheLargestSignedValueIsStoredExactly(): void
    {
        $chain = FakeChain::synthetic(1, 3);
        $chain->mutateBody(static fn (string $body): string => (string) preg_replace('/"reward":\d+/', '"reward":9223372036854775807', $body, 1));
        $this->serve($chain);
        $summary = $this->indexer($this->config(), 1)->run();
        $this->assertSame(RunSummary::COMPLETE, $summary->outcome, $summary->note);
        $this->assertSame(9223372036854775807, $this->db->value('SELECT reward FROM blocks WHERE height = 1'));
    }

    public function testRunFailsOnABadSignatureAndAnOrphanHeader(): void
    {
        $chain = FakeChain::synthetic(1, 5);
        $chain->mutateHeaders(static function (array $list): array {
            $list[1]['pricing_record']['signature'] = 'xyz';
            return $list;
        });
        $this->assertRunFails($chain, 'signature');

        $chain = FakeChain::synthetic(1, 5);
        $chain->mutateHeaders(static function (array $list): array {
            $list[0]['orphan_status'] = true;
            return $list;
        });
        $this->assertRunFails($chain, 'orphaned');
    }

    public function testRunFailsWhenTheFirstHeaderDoesNotLinkAndTheForkIsTooDeep(): void
    {
        // Stored chain from seed a; the node's chain differs from the very first block.
        $this->serve(FakeChain::synthetic(1, 10, 'a'));
        $this->indexer($this->config(['reorg_max_depth' => 5]), 1)->run();
        $this->serve(FakeChain::synthetic(1, 12, 'z'));
        $summary = $this->indexer($this->config(['reorg_max_depth' => 5]), 1)->run();
        $this->assertSame(RunSummary::ERROR, $summary->outcome);
        $this->assertStringContains('reorg-too-deep', $summary->note);
        $this->assertSame(10, $this->count('blocks'));
    }

    public function testRpcErrorsEndTheRunWithoutWritingAnything(): void
    {
        // The tip says 50, but a range request past the node's real chain gets a JSON-RPC error.
        $long = FakeChain::synthetic(1, 50);
        $short = FakeChain::synthetic(1, 5);
        foreach ([self::A, self::B, self::F] as $host) {
            $this->transport->serve($host, static fn ($c) => $c->method === 'get_last_block_header' ? $long->respond($c) : $short->respond($c));
        }
        $s = $this->indexer($this->config(), 1)->run();
        $this->assertSame(RunSummary::ERROR, $s->outcome);
        $this->assertStringContains('node: rpc rpc-error', $s->note);
        $this->assertSame(0, $this->count('blocks'));
    }

    // --- reorganisations ------------------------------------------------------------------------

    /**
     * Index chain A up to $storedTop, then let the nodes serve a chain that forks from it after
     * $fork and ends at $newTop, and run again.
     *
     * @param array<string,mixed> $limits
     */
    private function reorg(int $storedTop, int $fork, int $newTop, array $limits = [], int $extraWork = 1): RunSummary
    {
        $config = $this->config($limits);
        $a = FakeChain::synthetic(1, $storedTop, 'a');
        $this->serve($a);
        $first = $this->indexer($config, 1)->run();
        $this->assertSame(RunSummary::COMPLETE, $first->outcome, $first->note);
        $this->assertSame($storedTop, $this->count('blocks'));

        $this->serve(FakeChain::forked($a, $fork, 'b', $newTop, $extraWork));
        return $this->indexer($config, 1)->run();
    }

    private function assertReorged(RunSummary $summary, int $storedTop, int $fork, int $newTop): void
    {
        $b = FakeChain::forked(FakeChain::synthetic(1, $storedTop, 'a'), $fork, 'b', $newTop);
        $this->assertSame(RunSummary::COMPLETE, $summary->outcome, $summary->note);
        $this->assertSame(1, $summary->reorgs);
        $this->assertSame($newTop, $this->count('blocks'));
        $this->assertSame($newTop, $this->count('pricing_records'));
        $this->assertSame(['height' => $newTop, 'hash' => $b->hashAt($newTop)], $this->store->top());
        for ($h = 1; $h <= $newTop; $h++) {
            $this->assertSame($b->hashAt($h), $this->store->hashAt($h), 'hash at ' . $h);
        }
        $this->assertSame($storedTop - $fork, $this->count('orphaned_blocks'));
        for ($h = $fork + 1; $h <= $storedTop; $h++) {
            $this->assertTrue($this->store->isOrphaned(FakeChain::hashOf('a', $h)), 'orphaned ' . $h);
        }
        $this->assertSame(1, $this->count('reorgs'));
        $row = $this->db->one('SELECT * FROM reorgs');
        $this->assertSame($fork, (int) $row['fork_height']);
        $this->assertSame($storedTop, (int) $row['old_tip_height']);
        $this->assertSame(FakeChain::hashOf('a', $storedTop), $row['old_tip_hash']);
        $this->assertSame($storedTop - $fork, (int) $row['depth']);
        $this->assertSame('own-a', $row['node']);
        $this->assertSame($newTop - $fork, $summary->headers, 'the new blocks above the fork were fetched');
    }

    public function testReorgOfDepthOneWithANewerTip(): void
    {
        $s = $this->reorg(20, 19, 21);
        $this->assertReorged($s, 20, 19, 21);
    }

    public function testReorgOfDepthOneAtTheSameHeight(): void
    {
        // Same height, other hash: found because the tip differs from the stored block.
        $s = $this->reorg(20, 19, 20);
        $this->assertReorged($s, 20, 19, 20);
    }

    public function testReorgOfDepthThree(): void
    {
        $s = $this->reorg(20, 17, 22);
        $this->assertReorged($s, 20, 17, 22);
    }

    public function testReorgToAShorterChain(): void
    {
        // Two blocks shorter but with more work.
        $s = $this->reorg(20, 17, 19, [], 1500);
        $this->assertReorged($s, 20, 17, 19);
    }

    public function testReorgDeeperThanTheWindowIsFoundInSeveralWindows(): void
    {
        $s = $this->reorg(150, 60, 152, ['reorg_max_depth' => 100]);
        $this->assertReorged($s, 150, 60, 152);
        $this->assertTrue(count($this->hostsOf('get_block_headers_range')) >= 4, 'two windows of fork search and the new blocks');
    }

    public function testReorgExactlyAtTheMaximumDepthIsUndone(): void
    {
        $s = $this->reorg(30, 25, 31, ['reorg_max_depth' => 5]);
        $this->assertReorged($s, 30, 25, 31);
    }

    public function testReorgOverTheMaximumDepthStopsAndChangesNothing(): void
    {
        $s = $this->reorg(30, 24, 31, ['reorg_max_depth' => 5]);
        $this->assertSame(RunSummary::ERROR, $s->outcome);
        $this->assertStringContains('reorg-too-deep', $s->note);
        $this->assertSame(30, $this->count('blocks'));
        $this->assertSame(30, $this->count('pricing_records'));
        $this->assertSame(0, $this->count('orphaned_blocks'));
        $this->assertSame(0, $this->count('reorgs'));
        $this->assertSame(FakeChain::hashOf('a', 30), $this->store->hashAt(30));
    }

    public function testRerunAfterAReorgFindsNothingMoreToDo(): void
    {
        $this->reorg(20, 17, 22);
        $again = $this->indexer($this->config(), 1)->run();
        $this->assertSame(RunSummary::COMPLETE, $again->outcome);
        $this->assertSame(0, $again->reorgs);
        $this->assertSame(0, $again->headers);
        $this->assertSame(22, $this->count('blocks'));
        $this->assertSame(1, $this->count('reorgs'));
    }

    public function testALaggingNodeIsNotAReorg(): void
    {
        $a = FakeChain::synthetic(1, 20, 'a');
        $this->serve($a);
        $this->indexer($this->config(), 1)->run();
        // The node is two blocks behind, on the same chain.
        $this->serve(FakeChain::synthetic(1, 18, 'a'));
        $s = $this->indexer($this->config(), 1)->run();
        $this->assertSame(RunSummary::COMPLETE, $s->outcome, $s->note);
        $this->assertSame(0, $s->reorgs);
        $this->assertSame(20, $this->count('blocks'));
        $this->assertSame(0, $this->count('orphaned_blocks'));
    }

    // --- the tip of several nodes ----------------------------------------------------------------

    public function testALaggingFirstOwnNodeDoesNotHideTheTip(): void
    {
        $this->serve(FakeChain::synthetic(1, 20), [self::A]);
        $this->serve(FakeChain::synthetic(1, 30), [self::B]);
        $s = $this->indexer($this->config(), 1)->run();
        $this->assertSame(RunSummary::COMPLETE, $s->outcome, $s->note);
        $this->assertSame(30, $this->count('blocks'));
        $this->assertSame('own-b', $s->node);
        $this->assertSame([self::A, self::B], $this->hostsOf('get_last_block_header'));
        $this->assertSame([self::B], $this->hostsOf('get_block_headers_range'), 'the ranges come from the node that gave the tip');
        $this->assertSame(3, $s->requests);
    }

    public function testALeadingFirstOwnNodeGivesTheTipAndTheRanges(): void
    {
        $this->serve(FakeChain::synthetic(1, 30), [self::A]);
        $this->serve(FakeChain::synthetic(1, 20), [self::B]);
        $s = $this->indexer($this->config(['header_batch' => 10]), 1)->run();
        $this->assertSame(RunSummary::COMPLETE, $s->outcome, $s->note);
        $this->assertSame(30, $this->count('blocks'));
        $this->assertSame('own-a', $s->node);
        $this->assertSame([self::A, self::A, self::A], $this->hostsOf('get_block_headers_range'));
        $this->assertSame([self::A, self::B], $this->hostsOf('get_last_block_header'));
    }

    public function testTheTipIsTheMostWorkAndNotTheMostBlocks(): void
    {
        // A is longer but B has more cumulative difficulty (a heavier chain of fewer blocks).
        $this->serve(FakeChain::synthetic(1, 30)->addWork(1, -20000), [self::A]);
        $this->serve(FakeChain::synthetic(1, 25), [self::B]);
        $s = $this->indexer($this->config(), 1)->run();
        $this->assertSame(RunSummary::COMPLETE, $s->outcome, $s->note);
        $this->assertSame('own-b', $s->node);
        $this->assertSame(25, $this->count('blocks'));
    }

    public function testAnOwnNodeThatIsDownIsSkippedForTheTip(): void
    {
        $this->down(self::A);
        $this->serve(FakeChain::synthetic(1, 20), [self::B]);
        $s = $this->indexer($this->config(), 1)->run();
        $this->assertSame(RunSummary::COMPLETE, $s->outcome, $s->note);
        $this->assertSame('own-b', $s->node);
        $this->assertCount(0, array_filter($this->transport->hosts(), static fn (string $h): bool => $h === self::F));
    }

    public function testWhenTheLeadingNodeFailsOnARangeTheUsualOrderTakesOver(): void
    {
        $leader = FakeChain::synthetic(1, 30);
        $this->transport->serve(self::B, static function ($c) use ($leader) {
            if ($c->method === 'get_last_block_header') {
                return $leader->respond($c);
            }
            throw new TransportFailure(ErrorCategory::Connect);
        });
        // A has the same blocks with a little less work, so B leads.
        $this->serve(FakeChain::synthetic(1, 30)->addWork(1, -1), [self::A]);
        $s = $this->indexer($this->config(), 1)->run();
        $this->assertSame(RunSummary::COMPLETE, $s->outcome, $s->note);
        $this->assertSame(30, $this->count('blocks'));
        $this->assertSame('own-a', $s->node);
        $ranges = $this->hostsOf('get_block_headers_range');
        $this->assertSame(self::B, $ranges[0], 'the node that gave the tip is asked first');
        $this->assertSame(self::A, $ranges[count($ranges) - 1]);
    }

    // --- the work check -------------------------------------------------------------------------

    /** @return array<int,array<string,mixed>> every stored block, for "nothing changed" checks */
    private function snapshotOfChain(): array
    {
        return [
            $this->db->all('SELECT * FROM blocks ORDER BY height'),
            $this->db->all('SELECT * FROM pricing_records ORDER BY height'),
            $this->db->all('SELECT * FROM orphaned_blocks ORDER BY hash'),
            $this->db->all('SELECT * FROM reorgs ORDER BY id'),
        ];
    }

    private function assertLessWork(RunSummary $s, string $label, array $before): void
    {
        $this->assertSame(RunSummary::ERROR, $s->outcome);
        $this->assertStringContains('less-work', $s->note);
        $this->assertStringContains('node ' . $label . ' is on a branch with less work', $s->note);
        $this->assertStringNotContains('example.invalid', $s->note);
        $this->assertSame(0, $s->reorgs);
        $this->assertSame($before, $this->snapshotOfChain(), 'the database is unchanged');
    }

    public function testANodeOnABranchWithLessWorkDoesNotRollBackABatchThatDoesNotLink(): void
    {
        $a = FakeChain::synthetic(1, 20, 'a');
        $this->serve($a);
        $this->indexer($this->config(), 1)->run();
        $before = $this->snapshotOfChain();

        // The nodes now offer a longer branch (tip 22) with less cumulative difficulty.
        $this->serve(FakeChain::forked($a, 17, 'b', 22, -5000));
        $s = $this->indexer($this->config(), 1)->run();
        $this->assertLessWork($s, 'own-a', $before);
        $this->assertSame(20, $this->count('blocks'));
        $this->assertSame(FakeChain::hashOf('a', 20), $this->store->hashAt(20));
    }

    public function testANodeOnABranchWithLessWorkDoesNotRollBackWhenCaughtUp(): void
    {
        $a = FakeChain::synthetic(1, 20, 'a');
        $this->serve($a);
        $this->indexer($this->config(), 1)->run();
        $before = $this->snapshotOfChain();

        // Same height, another hash, a little less work.
        $this->serve(FakeChain::forked($a, 19, 'b', 20, -1));
        $s = $this->indexer($this->config(), 1)->run();
        $this->assertLessWork($s, 'own-a', $before);
    }

    public function testANodeWithEqualWorkDoesNotRollBack(): void
    {
        $a = FakeChain::synthetic(1, 20, 'a');
        $this->serve($a);
        $this->indexer($this->config(), 1)->run();
        $before = $this->snapshotOfChain();

        $this->serve(FakeChain::forked($a, 19, 'b', 20, 0));
        $s = $this->indexer($this->config(), 1)->run();
        $this->assertLessWork($s, 'own-a', $before);
    }

    public function testANodeWithMoreWorkRollsBackToTheForkPoint(): void
    {
        $s = $this->reorg(20, 17, 22, [], 1);
        $this->assertReorged($s, 20, 17, 22);
    }

    public function testTheHeavierNodeWinsWhenTheOwnNodesDisagree(): void
    {
        $a = FakeChain::synthetic(1, 20, 'a');
        $this->serve($a);
        $this->indexer($this->config(), 1)->run();

        // A still offers the stored chain (stale), B has the heavier branch.
        $b = FakeChain::forked($a, 17, 'b', 22);
        $this->serve($a, [self::A]);
        $this->serve($b, [self::B]);
        $s = $this->indexer($this->config(), 1)->run();
        $this->assertSame(RunSummary::COMPLETE, $s->outcome, $s->note);
        $this->assertSame(1, $s->reorgs);
        $this->assertSame('own-b', $s->node);
        $this->assertSame($b->hashAt(22), $this->store->hashAt(22));
        $this->assertSame($b->hashAt(18), $this->store->hashAt(18));
        $this->assertSame('own-b', $this->db->value('SELECT node FROM reorgs'));
    }

    public function testAStaleOwnNodeBehindTheStoredChainDoesNotRollBackAnything(): void
    {
        $a = FakeChain::synthetic(1, 20, 'a');
        $this->serve($a);
        $this->indexer($this->config(), 1)->run();
        $before = $this->snapshotOfChain();

        // Both own nodes are stuck on a branch that left the stored chain at 17 and stopped at 19.
        $this->serve(FakeChain::forked($a, 17, 'b', 19, 0));
        $s = $this->indexer($this->config(), 1)->run();
        $this->assertLessWork($s, 'own-a', $before);
    }

    public function testTheFallbackOnAStaleBranchDoesNotRollBackInTipMode(): void
    {
        $a = FakeChain::synthetic(1, 20, 'a');
        $this->serve($a);
        $this->indexer($this->config(), 1)->run();
        $before = $this->snapshotOfChain();

        $this->down(self::A);
        $this->down(self::B);
        // Caught up, with another hash at the same height and less work.
        $this->serve(FakeChain::forked($a, 18, 'b', 20, -3), [self::F]);
        $s = $this->indexer($this->config(), 1)->run();
        $this->assertLessWork($s, 'fallback', $before);

        // Ahead of the stored top, a batch that does not link, again with less work.
        $this->serve(FakeChain::forked($a, 17, 'b', 23, -9000), [self::F]);
        $s = $this->indexer($this->config(), 1)->run();
        $this->assertLessWork($s, 'fallback', $before);
    }

    public function testABlockThatWasOrphanedAndComesBackIsNoLongerOrphaned(): void
    {
        $a = FakeChain::synthetic(1, 20, 'a');
        $this->serve($a);
        $this->indexer($this->config(), 1)->run();
        $b = FakeChain::forked($a, 17, 'b', 22);
        $this->serve($b);
        $s = $this->indexer($this->config(), 1)->run();
        $this->assertSame(1, $s->reorgs, $s->note);
        $this->assertTrue($this->store->isOrphaned(FakeChain::hashOf('a', 19)));
        $this->assertSame(3, $this->count('orphaned_blocks'));

        // The a-chain grows past the b branch and wins again: its blocks 18 to 20 return.
        $this->serve(FakeChain::synthetic(1, 25, 'a'));
        $s = $this->indexer($this->config(), 1)->run();
        $this->assertSame(RunSummary::COMPLETE, $s->outcome, $s->note);
        $this->assertSame(1, $s->reorgs);
        $this->assertSame(25, $this->count('blocks'));
        for ($h = 18; $h <= 20; $h++) {
            $this->assertFalse($this->store->isOrphaned(FakeChain::hashOf('a', $h)), 'back on the chain: ' . $h);
        }
        for ($h = 18; $h <= 22; $h++) {
            $this->assertTrue($this->store->isOrphaned(FakeChain::hashOf('b', $h)), 'now orphaned: ' . $h);
        }
        $this->assertSame(5, $this->count('orphaned_blocks'));
        $this->assertSame(2, $this->count('reorgs'));
    }

    // --- modes ----------------------------------------------------------------------------------

    public function testBackfillNeverAsksTheFallbackAndPausesBetweenCalls(): void
    {
        $config = $this->config(['header_batch' => 10, 'backfill_pause_seconds' => 2.0, 'run_seconds' => 600]);
        $this->serve(FakeChain::synthetic(1, 350));
        $s = $this->indexer($config, 1)->run();
        $this->assertSame(RunSummary::COMPLETE, $s->outcome, $s->note);
        $this->assertSame(RunMode::Backfill, $s->mode);
        $this->assertSame(350, $s->headers);
        $this->assertSame(350, $this->count('blocks'));
        $this->assertCount(0, array_filter($this->transport->hosts(), static fn (string $h): bool => $h === self::F), 'the fallback is never asked');
        // 25 batches are fetched while more than 100 blocks are missing; each is followed by a pause.
        $this->assertSame(array_fill(0, 25, 2.0), $this->clock->sleeps);
    }

    public function testBackfillWithBothOwnNodesDownDoesNotUseTheFallbackForHeaders(): void
    {
        $this->down(self::A);
        $this->down(self::B);
        $this->serve(FakeChain::synthetic(1, 350), [self::F]);
        $s = $this->indexer($this->config(), 1)->run();
        $this->assertSame(RunSummary::ERROR, $s->outcome);
        $this->assertSame(RunMode::Backfill, $s->mode);
        $this->assertStringContains('node:', $s->note);
        $this->assertSame(0, $this->count('blocks'));
        // Only the tip lookup (one call) reached the fallback; no header range did.
        $this->assertSame(1, count(array_filter($this->hostsOf('get_last_block_header'), static fn (string $h): bool => $h === self::F)));
        $this->assertSame(0, count(array_filter($this->hostsOf('get_block_headers_range'), static fn (string $h): bool => $h === self::F)));
    }

    public function testForcedTipModeAllowsTheFallbackFarFromTheTipWithoutPauses(): void
    {
        $this->down(self::A);
        $this->down(self::B);
        $this->serve(FakeChain::synthetic(1, 350), [self::F]);
        $config = $this->config(['header_batch' => 100]);
        $indexer = new HeaderIndexer($this->client($config), $this->store, $config->limits, $this->clock, 1, RunMode::Tip);
        $s = $indexer->run();
        $this->assertSame(RunSummary::COMPLETE, $s->outcome, $s->note);
        $this->assertSame(RunMode::Tip, $s->mode);
        $this->assertSame(350, $this->count('blocks'));
        $this->assertSame('fallback', $s->node);
        $this->assertSame([], array_values(array_filter($this->clock->sleeps, static fn (float $x): bool => $x >= 2.0)), 'no backfill pauses');
    }

    public function testTipModeUsesTheFallbackWhenBothOwnNodesFail(): void
    {
        $this->down(self::A);
        $this->down(self::B);
        $this->serve(FakeChain::synthetic(1, 20), [self::F]);
        $s = $this->indexer($this->config(), 1)->run();
        $this->assertSame(RunSummary::COMPLETE, $s->outcome, $s->note);
        $this->assertSame(RunMode::Tip, $s->mode);
        $this->assertSame('fallback', $s->node);
        $this->assertSame(20, $this->count('blocks'));
        // Each own node got three tries (the first and two retries) for the tip. The ranges
        // then came from the node that gave the tip, the fallback, without trying the others.
        $hosts = $this->transport->hosts();
        $this->assertSame(3, count(array_filter($hosts, static fn (string $h): bool => $h === self::A)));
        $this->assertSame(3, count(array_filter($hosts, static fn (string $h): bool => $h === self::B)));
        $this->assertSame(2, count(array_filter($hosts, static fn (string $h): bool => $h === self::F)));
    }

    public function testTipModeFailsOverToTheSecondOwnNodeBeforeTheFallback(): void
    {
        $this->down(self::A);
        $this->serve(FakeChain::synthetic(1, 20), [self::B, self::F]);
        $s = $this->indexer($this->config(), 1)->run();
        $this->assertSame('own-b', $s->node);
        $this->assertCount(0, array_filter($this->transport->hosts(), static fn (string $h): bool => $h === self::F));
    }

    public function testTheRunStopsWhenRunSecondsIsUsedUp(): void
    {
        $config = $this->config(['header_batch' => 10, 'backfill_pause_seconds' => 2.0, 'run_seconds' => 5]);
        $this->serve(FakeChain::synthetic(1, 1000));
        $s = $this->indexer($config, 1)->run();
        $this->assertSame(RunSummary::TIME, $s->outcome);
        // Batches start at 0 s, 2 s and 4 s; at 6 s the time is up (checked between batches).
        $this->assertSame(30, $s->headers);
        $this->assertSame(30, $this->count('blocks'));
        $this->assertSame(1, $s->fromHeight);
        $this->assertSame(30, $s->toHeight);

        // The next run resumes after the stored top.
        $s2 = $this->indexer($config, 1)->run();
        $this->assertSame(31, $s2->fromHeight);
        $this->assertSame(60, $this->count('blocks'));
    }

    public function testBatchesStayWithinTheTipAndTheBatchSize(): void
    {
        $this->serve(FakeChain::synthetic(1, 25));
        $this->indexer($this->config(['header_batch' => 10]), 1)->run();
        $ranges = [];
        foreach ($this->transport->calls as $c) {
            if ($c->method === 'get_block_headers_range') {
                $ranges[] = $c->params['start_height'] . '-' . $c->params['end_height'];
            }
        }
        $this->assertSame(['1-10', '11-20', '21-25'], $ranges);
    }

    public function testResumesAfterTheHighestStoredHeightAndIgnoresTheStartHeightThen(): void
    {
        $this->serve(FakeChain::synthetic(1, 10));
        $this->indexer($this->config(), 1)->run();
        $this->serve(FakeChain::synthetic(1, 20));
        $s = $this->indexer($this->config(), 500)->run();
        $this->assertSame(11, $s->fromHeight);
        $this->assertSame(20, $this->count('blocks'));
        $this->assertSame(3, $s->requests, 'two tips and one range');
    }

    public function testNegativeStartHeightIsRefused(): void
    {
        $this->assertThrows(\InvalidArgumentException::class, fn () => new HeaderIndexer(
            $this->client($this->config()),
            $this->store,
            $this->config()->limits,
            $this->clock,
            -1,
        ));
    }
}
