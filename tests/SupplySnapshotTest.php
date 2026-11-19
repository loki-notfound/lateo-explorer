<?php

declare(strict_types=1);

namespace Lateo\Explorer\Tests;

use Lateo\Explorer\Db\SnapshotStore;
use Lateo\Explorer\Indexer\SnapshotOutcome;
use Lateo\Explorer\Indexer\SupplySnapshot;
use Lateo\Explorer\Rpc\TransportResponse;
use Lateo\Explorer\Tests\Support\FakeChain;
use Lateo\Explorer\Tests\Support\IndexerTestBase;
use Lateo\Explorer\Tests\Support\RecordedCall;

final class SupplySnapshotTest extends IndexerTestBase
{
    private function snapshot(bool $allowFallback = false): SupplySnapshot
    {
        return new SupplySnapshot($this->client($this->config()), new SnapshotStore($this->db), $this->clock, $allowFallback);
    }

    public function testStoredWhenBothHeadersAgree(): void
    {
        $chain = FakeChain::synthetic(1, 20);
        $this->serveWithSupply($chain, self::A);
        $this->assertSame(SnapshotOutcome::Stored, $this->snapshot()->run());

        // The order of the calls: header, the three supply calls, header again; all on one node.
        $methods = array_map(static fn (RecordedCall $c): string => $c->method, $this->transport->calls);
        $this->assertSame(['get_last_block_header', 'get_reserve_info', 'get_circulating_supply', 'get_audited_supply', 'get_last_block_header'], $methods);
        $this->assertSame([self::A], array_values(array_unique($this->transport->hosts())));

        $rows = $this->db->all('SELECT * FROM supply_snapshots ORDER BY kind');
        $this->assertSame(['audited', 'circulating', 'reserve_info'], array_column($rows, 'kind'));
        foreach ($rows as $row) {
            $this->assertSame(20, (int) $row['height']);
            $this->assertSame($chain->hashAt(20), $row['hash']);
            $this->assertSame('own-a', $row['node']);
            $this->assertSame(1_800_000_000, (int) $row['taken_at']);
        }
        $circ = json_decode((string) $this->db->value("SELECT payload FROM supply_snapshots WHERE kind = 'circulating'"), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('12406707323459691262', $circ['supply_tally'][0]['amount'], 'amounts stay exact strings');
        $this->assertSame('ZPH', $circ['supply_tally'][0]['currency_label']);
        $reserve = json_decode((string) $this->db->value("SELECT payload FROM supply_snapshots WHERE kind = 'reserve_info'"), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('675967368661860429', $reserve['assets']);
    }

    public function testSkippedWhenTheTopBlockChangesDuringTheCalls(): void
    {
        $a = FakeChain::synthetic(1, 20, 'a');
        $b = FakeChain::synthetic(1, 21, 'a');
        $headerCalls = 0;
        $this->transport->serve(self::A, static function (RecordedCall $c) use ($a, $b, &$headerCalls): TransportResponse {
            if (in_array($c->method, ['get_reserve_info', 'get_circulating_supply', 'get_audited_supply'], true)) {
                return new TransportResponse(200, (string) file_get_contents(__DIR__ . '/fixtures/rpc/' . $c->method . '.json'));
            }
            return (++$headerCalls === 1 ? $a : $b)->respond($c);
        });
        $this->assertSame(SnapshotOutcome::Changed, $this->snapshot()->run());
        $this->assertSame(0, $this->count('supply_snapshots'));

        // A different hash at the same height counts as changed too.
        $c1 = FakeChain::synthetic(1, 20, 'x');
        $headerCalls = 0;
        $this->transport->serve(self::A, static function (RecordedCall $c) use ($a, $c1, &$headerCalls): TransportResponse {
            if (in_array($c->method, ['get_reserve_info', 'get_circulating_supply', 'get_audited_supply'], true)) {
                return new TransportResponse(200, (string) file_get_contents(__DIR__ . '/fixtures/rpc/' . $c->method . '.json'));
            }
            return (++$headerCalls === 1 ? $a : $c1)->respond($c);
        });
        $this->assertSame(SnapshotOutcome::Changed, $this->snapshot()->run());
        $this->assertSame(0, $this->count('supply_snapshots'));
    }

    public function testNothingIsDoneWhenTheTopBlockHasASnapshotAlready(): void
    {
        $this->serveWithSupply(FakeChain::synthetic(1, 20), self::A);
        $this->assertSame(SnapshotOutcome::Stored, $this->snapshot()->run());
        $before = count($this->transport->calls);
        $this->assertSame(SnapshotOutcome::Unchanged, $this->snapshot()->run());
        $this->assertSame($before + 1, count($this->transport->calls), 'only the first header call');
        $this->assertSame(3, $this->count('supply_snapshots'));

        // A new top block gets its own snapshot.
        $this->serveWithSupply(FakeChain::synthetic(1, 21), self::A);
        $this->assertSame(SnapshotOutcome::Stored, $this->snapshot()->run());
        $this->assertSame(6, $this->count('supply_snapshots'));
    }

    public function testAllCallsGoToTheNodeThatAnsweredFirst(): void
    {
        $this->down(self::A);
        $this->serveWithSupply(FakeChain::synthetic(1, 20), self::B);
        $this->serveWithSupply(FakeChain::synthetic(1, 20), self::F);
        $this->assertSame(SnapshotOutcome::Stored, $this->snapshot()->run());
        $hosts = $this->transport->hosts();
        $this->assertSame([self::B, self::B, self::B, self::B, self::B], array_slice($hosts, -5));
        $this->assertSame('own-b', $this->db->value('SELECT DISTINCT node FROM supply_snapshots'));
        $this->assertCount(0, array_filter($hosts, static fn (string $h): bool => $h === self::F));
    }

    public function testTheFallbackIsNotUsedUnlessAllowed(): void
    {
        $this->down(self::A);
        $this->down(self::B);
        $this->serveWithSupply(FakeChain::synthetic(1, 20), self::F);
        $this->assertSame(SnapshotOutcome::Failed, $this->snapshot()->run());
        $this->assertCount(0, array_filter($this->transport->hosts(), static fn (string $h): bool => $h === self::F));
        $this->assertSame(0, $this->count('supply_snapshots'));

        $this->assertSame(SnapshotOutcome::Stored, $this->snapshot(true)->run());
        $this->assertSame('fallback', $this->db->value('SELECT DISTINCT node FROM supply_snapshots'));
    }

    public function testASnapshotOfAnOrphanedBlockIsKeptButNotUsedByReaders(): void
    {
        $config = $this->config();
        $a = FakeChain::synthetic(1, 20, 'a');
        $this->serveWithSupplyOnOwn($a);
        $this->indexer($config, 1)->run();
        $this->assertSame(SnapshotOutcome::Stored, $this->snapshot()->run());
        $store = new SnapshotStore($this->db);
        $this->assertSame(20, $store->latestOnChain('circulating')['height'] ?? null);

        // A reorganisation replaces block 19 and 20.
        $b = FakeChain::forked($a, 18, 'b', 21);
        $this->serveWithSupplyOnOwn($b);
        $s = $this->indexer($config, 1)->run();
        $this->assertSame(1, $s->reorgs, $s->note);
        $this->assertSame(3, $this->count('supply_snapshots'), 'the rows stay');
        $this->assertNull($store->latestOnChain('circulating'), 'but none is on the chain any more');

        $this->assertSame(SnapshotOutcome::Stored, $this->snapshot()->run());
        $this->assertSame(21, $store->latestOnChain('circulating')['height'] ?? null);
        $this->assertSame($b->hashAt(21), $store->latestOnChain('audited')['hash'] ?? null);
    }

    public function testTheNodeThatGaveTheTipIsAskedFirstAndGivesTheWholeSnapshot(): void
    {
        // The first own node lags; the second leads and is the node that gave the run's tip.
        $this->serveWithSupply(FakeChain::synthetic(1, 20), self::A);
        $lead = FakeChain::synthetic(1, 25);
        $this->serveWithSupply($lead, self::B);
        $this->assertSame(SnapshotOutcome::Stored, $this->snapshot()->run('own-b'));
        $this->assertSame([self::B], array_values(array_unique($this->transport->hosts())));
        $this->assertSame(25, (int) $this->db->value('SELECT max(height) FROM supply_snapshots'));
        $this->assertSame($lead->hashAt(25), $this->db->value('SELECT hash FROM supply_snapshots LIMIT 1'));
        $this->assertSame('own-b', $this->db->value('SELECT node FROM supply_snapshots LIMIT 1'));
    }

    public function testWithoutAPreferredNodeTheUsualOrderStartsWithTheFirstOwnNode(): void
    {
        $this->serveWithSupply(FakeChain::synthetic(1, 20), self::A);
        $this->serveWithSupply(FakeChain::synthetic(1, 25), self::B);
        $this->assertSame(SnapshotOutcome::Stored, $this->snapshot()->run());
        $this->assertSame(20, (int) $this->db->value('SELECT max(height) FROM supply_snapshots'));
    }

    public function testWhenThePreferredNodeFailsTheUsualOrderApplies(): void
    {
        $this->down(self::B);
        $this->serveWithSupply(FakeChain::synthetic(1, 20), self::A);
        $this->assertSame(SnapshotOutcome::Stored, $this->snapshot()->run('own-b'));
        $this->assertSame('own-a', $this->db->value('SELECT node FROM supply_snapshots LIMIT 1'));
    }

    public function testThePreferredFallbackIsNotUsedWhenTheFallbackIsNotAllowed(): void
    {
        $this->serveWithSupply(FakeChain::synthetic(1, 20), self::A);
        $this->serveWithSupply(FakeChain::synthetic(1, 30), self::F);
        $this->assertSame(SnapshotOutcome::Stored, $this->snapshot(false)->run('fallback'));
        $this->assertCount(0, array_filter($this->transport->hosts(), static fn (string $h): bool => $h === self::F));
        $this->assertSame('own-a', $this->db->value('SELECT node FROM supply_snapshots LIMIT 1'));
    }
}
