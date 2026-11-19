<?php

declare(strict_types=1);

namespace Lateo\Explorer\Tests;

use Lateo\Explorer\Cache\FileCache;
use Lateo\Explorer\Db\ChainStore;
use Lateo\Explorer\Db\RunStore;
use Lateo\Explorer\Db\SnapshotStore;
use Lateo\Explorer\Indexer\HeaderParser;
use Lateo\Explorer\Tests\Support\RecordedCall;
use Lateo\Explorer\Tests\Support\WebTestBase;
use Lateo\Explorer\Rpc\TransportResponse;
use Lateo\Explorer\Web\Format;

/** Every route of the explorer rendered through the Application, with fixtures and no web server. */
final class WebPagesTest extends WebTestBase
{
    // --- home ---------------------------------------------------------------------------------

    public function testHomeShowsTheStateTheLatestBlocksAndTheReserve(): void
    {
        $this->loadStandardIndex();
        $r = $this->get('/');
        $this->assertSame(200, $r->status);
        $this->assertSame('public, max-age=5', $r->header('Cache-Control'));
        $b = $r->body;
        $this->assertStringContains('<h1>Zephyr explorer</h1>', $b);
        $this->assertStringContains('Indexed height', $b);
        $this->assertStringContains('910,659', $b);
        $this->assertStringContains('Chain height (node tip)', $b);
        $this->assertStringContains('910,670', $b);
        $this->assertStringContains('Index behind the chain', $b);
        $this->assertStringContains('11 blocks', $b);
        $this->assertStringContains('Oracle price, spot', $b);
        $this->assertStringContains('0.1325 USD', $b);
        $this->assertStringContains('0.1289 USD', $b);
        $this->assertStringContains('200.89%', $b);
        $this->assertStringContains('195.24%', $b);
        $this->assertStringContains('<a class="lt-num" href="/block/910659">910,659</a>', $b);
        $this->assertStringContains('The 20 latest indexed blocks', $b);
        $this->assertSame(20, substr_count($b, '<td class="lt-num"><a class="lt-num" href="/block/'), 'twenty rows');
        $this->assertStringContains('<time datetime="' . Format::isoTime(1795047299) . '">' . Format::time(1795047299) . '</time>', $b);
        $this->assertStringContains('(25 min ago)', $b, 'the age beside the time of the newest block (1,501 s before the clock)');
        $this->assertStringContains(Format::hashShort((string) $this->recorded(910659)['hash']), $b);
        $this->assertStringContains('910,640</a>', $b, 'the oldest of the twenty');
        $this->assertSame(1, count($this->transport->calls), 'one call for the tip');
        $this->assertSame(1, $this->calls('get_last_block_header'));
    }

    public function testHomeWithAnEmptyIndexStillRenders(): void
    {
        $r = $this->get('/');
        $this->assertSame(200, $r->status);
        $this->assertStringContains('No block is indexed yet', $r->body);
        $this->assertStringContains('No pricing record is indexed yet', $r->body);
        $this->assertStringContains('0 blocks indexed so far', $r->body);
        $this->assertStringContains('910,670', $r->body, 'the tip is still shown');
    }

    public function testHomeRendersWithNodeNotReachableWhenTheTransportFails(): void
    {
        $this->loadStandardIndex();
        $this->down(self::A, self::B, self::F);
        $r = $this->get('/');
        $this->assertSame(200, $r->status);
        $this->assertStringContains('Node not reachable', $r->body);
        $this->assertStringContains('910,659', $r->body, 'the indexed data is still there');
        $this->assertStringContains('0.1325 USD', $r->body);
        $this->assertStringNotContains('Index behind the chain', $r->body);
        $this->assertTrue(count($this->transport->calls) <= 3, 'one attempt per node at most');
    }

    public function testHomeWordsABusyCacheAsTemporarilyUnavailable(): void
    {
        $this->loadStandardIndex();
        $cache = new FileCache($this->cacheDir, $this->clock);
        $lock = fopen($cache->lockPathFor('tip'), 'c+');
        $this->assertTrue($lock !== false && flock($lock, LOCK_EX | LOCK_NB), 'the test holds the lock of the tip');
        try {
            $r = $this->get('/');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $this->assertSame(200, $r->status);
        $this->assertStringContains('Temporarily unavailable', $r->body);
        $this->assertStringNotContains('Node not reachable', $r->body);
        $this->assertSame([], $this->transport->calls, 'a locked key makes no call');
    }

    public function testHomeReadsTheTipThroughTheCache(): void
    {
        $this->loadStandardIndex();
        $this->get('/');
        $this->clock->advance(5);
        $this->get('/');
        $this->assertSame(1, $this->calls('get_last_block_header'), 'within 10 seconds the cache answers');
        $this->clock->advance(6);
        $this->get('/');
        $this->assertSame(2, $this->calls('get_last_block_header'), 'after 10 seconds one new call');
    }

    public function testHomeUsesTheLastKnownTipWhenTheNodesFailAfterwards(): void
    {
        $this->loadStandardIndex();
        $this->get('/');
        $this->down(self::A, self::B, self::F);
        $this->clock->advance(20);
        $r = $this->get('/');
        $this->assertStringContains('910,670', $r->body);
        $this->assertStringContains('(last known value)', $r->body);
        $this->assertStringNotContains('Node not reachable', $r->body);
    }

    // --- block --------------------------------------------------------------------------------

    public function testASettledBlockByHeightShowsEverythingStored(): void
    {
        $this->loadStandardIndex();
        $h = $this->recorded(910640);
        $r = $this->get('/block/910640');
        $this->assertSame(200, $r->status);
        $this->assertSame('public, max-age=3600', $r->header('Cache-Control'));
        $b = $r->body;
        $this->assertStringContains('Block <span class="lt-num">910,640</span>', $b);
        $this->assertStringContains('Canonical', $b);
        $this->assertStringContains('lt-status-online', $b);
        $this->assertStringNotContains('may still change', $b);
        $this->assertStringContains('<code class="lt-hash">' . $h['hash'] . '</code>', $b);
        $this->assertStringContains('<code class="lt-hash">' . $h['prev_hash'] . '</code>', $b);
        $this->assertStringContains('3.834719181719 ZPH', $b, 'the miner reward, 12 decimals');
        $this->assertStringContains('111 bytes', $b);
        $this->assertStringContains('<code class="lt-hash">' . $h['miner_tx_hash'] . '</code>', $b);
        $this->assertStringContains('This block has no transactions besides the miner transaction.', $b);
        $this->assertStringContains('Oracle price, spot', $b);
        $this->assertStringContains('0.132133440000 USD per ZPH', $b);
        $this->assertStringContains('0.128725290000 USD per ZPH', $b);
        $this->assertStringContains('7.568106900000 ZPH per ZSD', $b);
        $this->assertStringContains('0.678924380000 ZPH per ZRS', $b);
        $this->assertStringContains('1.995604220000 ZSD per ZYS', $b);
        $this->assertStringContains('200.31%', $b);
        $this->assertStringContains('194.91%', $b);
        $this->assertStringContains('<code class="lt-hash">' . $h['pricing_record']['signature'] . '</code>', $b);
        $this->assertStringContains('No previous block in the index', $b);
        $this->assertStringContains('href="/block/910641">Next block 910,641', $b);
        $this->assertSame([], $this->transport->calls, 'a block without transactions needs no node');
    }

    public function testABlockByHashIsTheSamePage(): void
    {
        $this->loadStandardIndex();
        $hash = (string) $this->recorded(910640)['hash'];
        $byHash = $this->get('/block/' . $hash);
        $this->assertSame(200, $byHash->status);
        $this->assertSame($this->get('/block/910640')->body, $byHash->body);
    }

    public function testAnUnsettledBlockListsItsTransactionHashesFromOneLiveCall(): void
    {
        $this->loadStandardIndex();
        $r = $this->get('/block/910657');
        $this->assertSame(200, $r->status);
        $this->assertSame('public, max-age=15', $r->header('Cache-Control'));
        $b = $r->body;
        $this->assertStringContains('This block may still change', $b);
        $this->assertStringContains('one of the last 10 blocks of the index', $b);
        $this->assertStringContains('Previous block 910,656', $b);
        $this->assertStringContains('Next block 910,658', $b);
        $this->assertStringContains('The hashes of the 6 transactions', $b);
        $this->assertStringContains('<li><code class="lt-hash">9cfd6c88ec9bd3aff3cbbd2e3693bd1bad80eef6baf122df1a89f26549834c13</code></li>', $b);
        $this->assertSame(6, substr_count($b, '<li><code class="lt-hash">'));
        $this->assertStringNotContains('href="/tx', $b, 'plain text for now');
        $this->assertSame(1, count($this->transport->calls));
        $this->assertSame(1, $this->calls('get_block'));
        $this->assertSame(['height' => 910657], $this->transport->calls[0]->params);
    }

    public function testTheTransactionListIsCachedThirtySecondsWhileUnsettled(): void
    {
        $this->loadStandardIndex();
        $this->get('/block/910657');
        $this->clock->advance(20);
        $this->get('/block/910657');
        $this->assertSame(1, $this->calls('get_block'));
        $this->clock->advance(11);
        $this->get('/block/910657');
        $this->assertSame(2, $this->calls('get_block'));
    }

    public function testASettledBlockCachesItsTransactionListForADay(): void
    {
        $this->loadStandardIndex();
        $block = $this->recorded(910641);
        $txHash = str_repeat('ab', 32);
        $answer = static function (RecordedCall $c) use ($block, $txHash): TransportResponse {
            return new TransportResponse(200, (string) json_encode([
                'id' => '0',
                'jsonrpc' => '2.0',
                'result' => ['status' => 'OK', 'untrusted' => false, 'block_header' => $block, 'tx_hashes' => [$txHash]],
            ]));
        };
        foreach ([self::A, self::B, self::F] as $host) {
            $this->transport->serve($host, $answer);
        }
        $r = $this->get('/block/910641');
        $this->assertStringContains($txHash, $r->body);
        $this->assertStringNotContains('may still change', $r->body);
        $this->clock->advance(3600);
        $this->get('/block/910641');
        $this->assertSame(1, $this->calls('get_block'), 'a day of cache for a settled block');
        $this->clock->advance(86400);
        $this->get('/block/910641');
        $this->assertSame(2, $this->calls('get_block'));
    }

    public function testAnAnswerForAnotherBlockIsNotShownAndNotCached(): void
    {
        $this->loadStandardIndex();
        $other = $this->recorded(910657);
        $other['hash'] = str_repeat('cd', 32);
        $answer = static fn (RecordedCall $c): TransportResponse => new TransportResponse(200, (string) json_encode([
            'id' => '0',
            'jsonrpc' => '2.0',
            'result' => ['status' => 'OK', 'untrusted' => false, 'block_header' => $other, 'tx_hashes' => [str_repeat('11', 32)]],
        ]));
        $this->transport->serve(self::A, $answer);
        $r = $this->get('/block/910657');
        $this->assertSame(200, $r->status);
        $this->assertStringContains('Not shown, because the node answered about another block', $r->body);
        $this->assertStringNotContains(str_repeat('11', 32), $r->body);
        $this->assertStringContains('<code class="lt-hash">' . $this->recorded(910657)['hash'] . '</code>', $r->body, 'the rest of the page stays');
        $this->get('/block/910657');
        $this->assertSame(2, $this->calls('get_block'), 'a refused answer is not cached');
    }

    public function testAnAnswerWithAnotherNumberOfTransactionsIsNotShown(): void
    {
        $this->loadStandardIndex();
        $block = $this->recorded(910657);
        $answer = static fn (RecordedCall $c): TransportResponse => new TransportResponse(200, (string) json_encode([
            'id' => '0',
            'jsonrpc' => '2.0',
            'result' => ['status' => 'OK', 'untrusted' => false, 'block_header' => $block, 'tx_hashes' => [str_repeat('11', 32)]],
        ]));
        $this->transport->serve(self::A, $answer);
        $r = $this->get('/block/910657');
        $this->assertStringContains('Not shown', $r->body);
        $this->assertStringNotContains(str_repeat('11', 32), $r->body);
    }

    public function testABlockPageRendersWithoutANode(): void
    {
        $this->loadStandardIndex();
        $this->down(self::A, self::B, self::F);
        $r = $this->get('/block/910657');
        $this->assertSame(200, $r->status);
        $this->assertStringContains('Node not reachable', $r->body);
        $this->assertStringContains('The transaction hashes are read live from a node.', $r->body);
        $this->assertStringContains('Block <span class="lt-num">910,657</span>', $r->body);
        $this->assertStringContains('Oracle price, spot', $r->body);
        $this->assertTrue(count($this->transport->calls) <= 3);
    }

    public function testBlocksBeforeTheFirstPricingRecordSayWhenRecordsStart(): void
    {
        $this->loadStandardIndex();
        $this->down(self::A, self::B, self::F); // these blocks list transactions; no fixture is recorded for them
        foreach ([89298, 89299] as $height) {
            $r = $this->get('/block/' . $height);
            $this->assertSame(200, $r->status);
            $this->assertStringContains('Pricing records start at block 89,300 (2023-10-01).', $r->body);
            $this->assertStringNotContains('Oracle price, spot', $r->body);
        }
    }

    public function testTheFirstPricingRecordIsAtBlock89300(): void
    {
        $this->loadStandardIndex();
        $r = $this->get('/block/89300');
        $this->assertSame(200, $r->status);
        $this->assertStringContains('3.057468230000 USD per ZPH', $r->body);
        $this->assertStringContains('2023-10-01 09:27:07 UTC', $r->body);
        $this->assertStringContains('0.00%</span> (not defined yet: the average needs 720 records)', $r->body);
        $this->assertStringContains('Previous block 89,299', $r->body);
        $this->assertStringContains('Next block 89,301', $r->body);
        $this->assertStringNotContains('Pricing records start at block 89,300', $r->body);
    }

    public function testTheFirstBlockOfHardFork11(): void
    {
        $this->loadStandardIndex();
        $r = $this->get('/block/536000');
        $this->assertSame(200, $r->status);
        $this->assertStringContains('1,921,656.672716101217 ZPH', $r->body, 'a reward beyond the signed 32-bit range, exact');
        $this->assertStringContains('The first block of hard fork 11', $r->body);
        $this->assertStringContains('one-time mint of 1,921,650 ZPH', $r->body);
        $this->assertStringContains('559.51%', $r->body, 'the moving-average ratio (5595128710000)');
        $this->assertStringNotContains('ZEPH', $r->body, 'old asset names are not shown');
        $this->assertSame([], $this->transport->calls);
    }

    public function testAnOrphanedHashIsShownAsOrphaned(): void
    {
        $this->loadStandardIndex();
        $hash = hash('sha256', 'orphan');
        $this->db->exec('INSERT INTO orphaned_blocks (hash, height, detected_at) VALUES (?, ?, ?)', [$hash, 910655, 1795047000]);
        $r = $this->get('/block/' . $hash);
        $this->assertSame(200, $r->status);
        $this->assertStringContains('Orphaned block at height <span class="lt-num">910,655</span>', $r->body);
        $this->assertStringContains('lt-status-offline', $r->body);
        $this->assertStringContains('It is not on the canonical chain', $r->body);
        $this->assertStringContains('<code class="lt-hash">' . $hash . '</code>', $r->body);
        $this->assertStringContains('The canonical block at this height is block <a class="lt-num" href="/block/910655">910,655</a>', $r->body);
        $this->assertStringContains(Format::time(1795047000), $r->body, 'the time the indexer saw it');
        $this->assertSame([], $this->transport->calls);
    }

    public function testAHeightAboveTheIndexIsNotIndexedYet(): void
    {
        $this->loadStandardIndex();
        $r = $this->get('/block/910700');
        $this->assertSame(404, $r->status);
        $this->assertStringContains('Block not indexed yet', $r->body);
        $this->assertStringContains('Block <span class="lt-num">910,700</span> is not in the index.', $r->body);
        $this->assertStringContains('The index holds blocks up to height <a class="lt-num" href="/block/910659">910,659</a>', $r->body);
        $this->assertSame([], $this->transport->calls);
    }

    public function testAnUnknownHashIsNotIndexed(): void
    {
        $this->loadStandardIndex();
        $hash = hash('sha256', 'nothing');
        $r = $this->get('/block/' . $hash);
        $this->assertSame(404, $r->status);
        $this->assertStringContains('No block with this hash is in the index.', $r->body);
    }

    public function testBlockOnAnEmptyIndex(): void
    {
        $r = $this->get('/block/5');
        $this->assertSame(404, $r->status);
        $this->assertStringContains('The index is empty.', $r->body);
    }

    public function testMalformedBlockPathsAreNotFound(): void
    {
        $this->loadStandardIndex();
        foreach (['/block/', '/block', '/block/abc', '/block/0910657', '/block/910657/', '/block/-1', '/block/99999999999',
                  '/block/' . strtoupper((string) $this->recorded(910640)['hash']), '/block/' . str_repeat('a', 63)] as $path) {
            $r = $this->get($path);
            $this->assertSame(404, $r->status, $path);
            $this->assertStringContains('Page not found', $r->body, $path);
        }
    }

    // --- status -------------------------------------------------------------------------------

    public function testStatusListsEachNodeByNameOnly(): void
    {
        $this->loadStandardIndex();
        $r = $this->get('/status');
        $this->assertSame(200, $r->status);
        $this->assertSame('public, max-age=5', $r->header('Cache-Control'));
        $b = $r->body;
        foreach (['Own node 1', 'Own node 2', 'node.lateo.dev'] as $name) {
            $this->assertStringContains('<th scope="row">' . $name . '</th>', $b);
        }
        $this->assertSame(3, substr_count($b, 'Reachable'));
        $this->assertSame(3, substr_count($b, '910,670'));
        $this->assertSame(3, substr_count($b, 'Not indexed yet'), 'the node tip is above the index');
        foreach (['example.invalid', 'node-a', 'node-b', 'own-a', 'own-b', '17777', 'http://'] as $secret) {
            $this->assertStringNotContains($secret, $b);
        }
        $this->assertSame(3, count($this->transport->calls));
        $this->assertSame([self::A, self::B, self::F], $this->transport->hosts());
    }

    public function testStatusComparesTheTopHashWithTheIndex(): void
    {
        $this->loadStandardIndex();
        $tip = json_decode((string) file_get_contents(__DIR__ . '/fixtures/rpc/get_last_block_header.json'), true, 512, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
        (new ChainStore($this->db))->insertBatch([HeaderParser::parse($tip['result']['block_header'])]);
        $this->tipAt(self::B, 910659, str_repeat('ee', 32));
        $b = $this->get('/status')->body;
        $this->assertSame(2, substr_count($b, 'Matches the index'), 'both other nodes agree at the tip');
        $this->assertSame(1, substr_count($b, 'Differs from the index'));
        $differs = (int) strpos($b, 'Differs from the index');
        $this->assertTrue(
            strpos($b, '<th scope="row">Own node 2</th>') < $differs && $differs < strpos($b, '<th scope="row">node.lateo.dev</th>'),
            'the second node is the one that differs',
        );
    }

    public function testStatusMarksANodeThatFailsAndSkipsItBriefly(): void
    {
        $this->loadStandardIndex();
        $this->down(self::A);
        $b = $this->get('/status')->body;
        $this->assertSame(2, substr_count($b, 'Reachable'));
        $this->assertStringContains('Node not reachable', $b);
        $this->assertSame(3, count($this->transport->calls));

        $this->clock->advance(16); // the status entry has expired, the marker (30 s) has not
        $b = $this->get('/status')->body;
        $this->assertSame(5, count($this->transport->calls), 'the failed node is not asked again: two calls for the others');
        $this->assertSame([self::A, self::B, self::F, self::B, self::F], $this->transport->hosts());
        $this->assertStringContains('Not asked again for a short time after a failed try', $b);

        $this->clock->advance(16); // 32 s after the failure
        $this->get('/status');
        $this->assertSame(8, count($this->transport->calls));
        $this->assertSame(self::A, $this->transport->hosts()[5], 'asked again after 30 seconds');
    }

    public function testStatusIsOneCacheEntryForAllNodes(): void
    {
        $this->loadStandardIndex();
        $this->get('/status');
        $this->clock->advance(10);
        $this->get('/status');
        $this->assertSame(3, count($this->transport->calls));
        $this->clock->advance(6);
        $this->get('/status');
        $this->assertSame(6, count($this->transport->calls));
    }

    public function testStatusShowsTheIndexerAndTheLastRecords(): void
    {
        $this->loadStandardIndex();
        (new RunStore($this->db))->record(1795048700, 1795048745, 'tip', 910656, 910659, 4, 'own-a', 'complete', '');
        (new SnapshotStore($this->db))->store(1795048746, 910659, (string) $this->recorded(910659)['hash'], 'own-a', ['reserve_info' => ['height' => 910660]]);
        $this->db->exec(
            'INSERT INTO reorgs (detected_at, fork_height, old_tip_height, old_tip_hash, depth, node) VALUES (?, ?, ?, ?, ?, ?)',
            [1795040000, 910600, 910602, str_repeat('0', 64), 2, 'own-a'],
        );
        $b = $this->get('/status')->body;
        $this->assertStringContains(Format::time(1795048745), $b, 'the last run');
        $this->assertStringContains('<dt>Mode of that run</dt><dd>tip</dd>', $b);
        $this->assertStringContains('<dt>Outcome of that run</dt><dd>complete</dd>', $b);
        $this->assertStringContains(Format::time(1795040000) . '</time>', $b);
        $this->assertStringContains('2 blocks deep, above height <a class="lt-num" href="/block/910600">910,600</a>', $b);
        $this->assertStringContains(Format::time(1795048746), $b, 'the supply snapshot');
        $this->assertStringContains('Indexed height', $b);
        $this->assertStringNotContains('own-a', $b, 'the label of a node is not shown');
    }

    public function testStatusOnAnEmptyDatabase(): void
    {
        $b = $this->get('/status')->body;
        $this->assertStringContains('No block is indexed yet', $b);
        $this->assertStringContains('No run is recorded yet', $b);
        $this->assertStringContains('None recorded', $b);
        $this->assertStringContains('None taken yet', $b);
    }

    public function testStatusWithAllNodesDownStillRenders(): void
    {
        $this->down(self::A, self::B, self::F);
        $r = $this->get('/status');
        $this->assertSame(200, $r->status);
        $this->assertStringContains('lt-status-offline', $r->body);
        $this->assertSame(3, substr_count($r->body, 'Node not reachable'));
    }

    // --- search -------------------------------------------------------------------------------

    public function testSearchForAHeightFoundInTheIndexRedirects(): void
    {
        $this->loadStandardIndex();
        $r = $this->get('/search', ' 910657 ');
        $this->assertSame(303, $r->status);
        $this->assertSame('/block/910657', $r->header('Location'));
        $this->assertSame('no-store', $r->header('Cache-Control'));
        $this->assertSame([], $this->transport->calls);
    }

    public function testSearchForABlockHashRedirects(): void
    {
        $this->loadStandardIndex();
        $hash = (string) $this->recorded(910640)['hash'];
        $this->assertSame('/block/910640', $this->get('/search', $hash)->header('Location'));
        $this->assertSame('/block/910640', $this->get('/search', strtoupper($hash))->header('Location'), 'upper case is accepted');
        $orphan = hash('sha256', 'orphan');
        $this->db->exec('INSERT INTO orphaned_blocks (hash, height, detected_at) VALUES (?, ?, ?)', [$orphan, 910655, 1795047000]);
        $this->assertSame('/block/' . $orphan, $this->get('/search', $orphan)->header('Location'));
        $this->assertSame([], $this->transport->calls);
    }

    public function testSearchForAHashThatIsNoBlockSaysTransactionSearchComesLater(): void
    {
        $this->loadStandardIndex();
        $r = $this->get('/search', hash('sha256', 'a transaction'));
        $this->assertSame(404, $r->status);
        $this->assertStringContains('Transaction search comes later.', $r->body);
        $this->assertSame([], $this->transport->calls);
    }

    public function testSearchRefusesAnAddressWithTheReason(): void
    {
        $this->loadStandardIndex();
        $address = 'ZEPHs0000EXAMPLE0000PLACEHOLDER0000' . str_repeat('0', 63);
        $this->assertSame(98, strlen($address));
        $r = $this->get('/search', $address);
        $this->assertSame(422, $r->status);
        $this->assertStringContains('The explorer has no address pages, by design.', $r->body);
        $this->assertStringContains('An address page would show what one person holds and spends.', $r->body);
        $this->assertStringNotContains($address, $r->body, 'the address is not echoed');
        $this->assertStringNotContains('PLACEHOLDER', $r->body);
        $this->assertSame([], $this->transport->calls);
    }

    public function testSearchEchoesAQueryOnlyEscapedAndShortened(): void
    {
        $this->loadStandardIndex();
        $r = $this->get('/search', '<script>alert(1)</script>');
        $this->assertSame(404, $r->status);
        $this->assertStringContains('Nothing found for <code>&lt;script&gt;alert(1)&lt;/script&gt;</code>', $r->body);
        $this->assertStringNotContains('<script', $r->body);
        $this->assertStringContains('value="&lt;script&gt;alert(1)&lt;/script&gt;"', $r->body, 'the box keeps the text, escaped');

        $r = $this->get('/search', '"><img src=x onerror=alert(1)>');
        $this->assertStringNotContains('<img src=x', $r->body);
        $this->assertStringContains('value="&quot;&gt;&lt;img src=x onerror=alert(1)&gt;"', $r->body);

        $r = $this->get('/search', str_repeat('a', 100));
        $this->assertStringContains('<code>' . str_repeat('a', 40) . '…</code>', $r->body, 'cut to 40 characters');

        $r = $this->get('/search', "caf\u{e9}\x07");
        $this->assertStringContains('<code>caf???</code>', $r->body, 'non-ASCII and control bytes are not echoed');
        $this->assertSame([], $this->transport->calls);
    }

    public function testSearchForAHeightThatIsNotIndexedIsNotFound(): void
    {
        $this->loadStandardIndex();
        foreach (['910700', '99999999999', '12 34', '1e3', '-5', '0x10'] as $q) {
            $r = $this->get('/search', $q);
            $this->assertSame(404, $r->status, $q);
            $this->assertStringContains('Nothing found for', $r->body, $q);
        }
        $this->assertStringContains('The index holds blocks up to height <a class="lt-num" href="/block/910659">910,659</a>', $this->get('/search', '910700')->body);
    }

    public function testAnEmptySearchAsksForInput(): void
    {
        $this->assertSame(200, $this->get('/search')->status);
        $this->assertStringContains('Enter a block height or a block hash.', $this->get('/search', '   ')->body);
        $this->assertStringContains('Search by block height or block hash', $this->get('/search')->body);
    }

    public function testNoQueryParameterOtherThanQIsRead(): void
    {
        $this->loadStandardIndex();
        // The Request object has only the path and q; the application cannot see anything else.
        $request = new \Lateo\Explorer\Web\Request('GET', '/', 'ignored');
        $this->assertSame($this->get('/')->body, $this->app()->handle($request)->body, 'q means nothing outside /search');
    }

    // --- about, errors, methods ---------------------------------------------------------------

    public function testAboutExplainsTheMethod(): void
    {
        $r = $this->get('/about');
        $this->assertSame(200, $r->status);
        $this->assertSame('public, max-age=3600', $r->header('Cache-Control'));
        $b = $r->body;
        $this->assertStringContains('Independent open-source project around Zephyr. Not affiliated with ZephyrProtocol.', $b);
        $this->assertStringContains('Security notice: node.lateo.dev, explorer.lateo.dev, and lateo support will never ask for your 25-word seed phrase or private keys.', $b);
        $this->assertStringContains('as a fallback', $b);
        $this->assertStringContains('<code>lateo-explorer (+https://explorer.lateo.dev)</code>', $b);
        $this->assertStringContains('No cookies, no scripts, no analytics and no requests to third parties.', $b);
        $this->assertStringContains('The application does not log what you search for.', $b);
        $this->assertStringContains('ten files of 10 MB', $b);
        $this->assertStringContains('behind Cloudflare', $b);
        $this->assertStringContains('Hard fork 11, at block 536,000', $b);
        $this->assertStringContains('ZEPH, ZEPHUSD, ZEPHRSV and ZYIELD', $b, 'the renaming is explained here');
        $this->assertStringContains('loki-notfound@proton.me', $b);
        $this->assertStringContains('href="https://github.com/loki-notfound/lateo-explorer"', $b);
        $this->assertStringContains('BSD-3-Clause', $b);
        $this->assertStringContains('/assets/lockup-host-explorer-reversed.svg', $b);
        foreach (['example.invalid', 'node-a', '17777'] as $secret) {
            $this->assertStringNotContains($secret, $b);
        }
        $this->assertSame([], $this->transport->calls);
    }

    public function testUnknownPathsAreNotFound(): void
    {
        foreach (['/nope', '/index.php', '/assets/x.css', '/about/', '/status/', '/search/', '//'] as $path) {
            $r = $this->get($path);
            $this->assertSame(404, $r->status, $path);
            $this->assertStringContains('<h1 id="state-title">Page not found</h1>', $r->body, $path);
            $this->assertStringContains('<p class="lt-state-code">404</p>', $r->body, $path);
        }
        $this->assertSame([], $this->transport->calls);
    }

    public function testOtherMethodsAreRefusedWith405(): void
    {
        $opener = static function (): never {
            throw new \LogicException('the database must not be opened for a refused method');
        };
        foreach (['POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'] as $method) {
            foreach (['/', '/search', '/block/910657', '/status', '/nope'] as $path) {
                $r = $this->get($path, null, $method, $this->app([], $opener));
                $this->assertSame(405, $r->status, $method . ' ' . $path);
                $this->assertSame('GET, HEAD', $r->header('Allow'));
                $this->assertStringContains('Method not allowed', $r->body);
            }
        }
        $this->assertSame([], $this->transport->calls);
    }

    public function testHeadAnswersLikeGetWithoutABody(): void
    {
        $this->loadStandardIndex();
        $get = $this->get('/status');
        $this->transport->calls = [];
        $head = $this->get('/status', null, 'HEAD');
        $this->assertSame(200, $head->status);
        $this->assertSame('', $head->body);
        $this->assertSame($get->headers, $head->headers);
    }

    public function testAnIndexThatCannotBeOpenedIsA503PageAndOtherPagesStayUp(): void
    {
        $opener = static function (): never {
            throw new \RuntimeException('database cannot be opened');
        };
        $app = $this->app([], $opener);
        foreach (['/', '/status', '/block/5', '/search'] as $path) {
            $r = $this->get($path, '5', 'GET', $app);
            $this->assertSame(503, $r->status, $path);
            $this->assertStringContains('Index not available', $r->body);
            $this->assertSame('no-store', $r->header('Cache-Control'));
        }
        $this->assertSame(200, $this->get('/about', null, 'GET', $app)->status);
        $this->assertSame(404, $this->get('/nope', null, 'GET', $app)->status);
    }

    public function testADatabaseWithoutTablesIsAlsoA503Page(): void
    {
        $empty = \Lateo\Explorer\Db\Database::memory();
        $r = $this->get('/', null, 'GET', $this->app([], static fn () => $empty));
        $this->assertSame(503, $r->status);
        $this->assertStringContains('Index not available', $r->body);
    }

    public function testAFailureOfTheCodeIsAPlain500WithoutDetail(): void
    {
        $opener = static function (): never {
            throw new \LogicException('secret detail at /var/www/x');
        };
        $app = $this->app([], $opener);
        $reported = [];
        $r = $app->handleSafely(new \Lateo\Explorer\Web\Request('GET', '/', null), static function (\Throwable $e) use (&$reported): void {
            $reported[] = $e::class;
        });
        $this->assertSame([\LogicException::class], $reported);
        $this->assertSame(500, $r->status);
        $this->assertStringContains('Something broke on the server', $r->body);
        $this->assertStringNotContains('secret detail', $r->body);
        $this->assertStringNotContains('/var/www', $r->body);
        $this->assertSame('no-store', $r->header('Cache-Control'));
    }

    public function testACacheThatCannotBeUsedMakesLiveDataUnavailableNotTheWholePage(): void
    {
        $this->loadStandardIndex();
        $blocker = $this->scratch() . '/not-a-directory';
        file_put_contents($blocker, 'x');
        $this->cacheDir = $blocker . '/cache';
        $r = $this->get('/');
        $this->assertSame(200, $r->status);
        $this->assertStringContains('Temporarily unavailable', $r->body);
        $this->assertStringContains('0.1325 USD', $r->body);
        $this->assertSame([], $this->transport->calls, 'nothing is read live without the shared cache');
    }
}
