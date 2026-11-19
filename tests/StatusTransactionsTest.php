<?php

declare(strict_types=1);

namespace Lateo\Explorer\Tests;

use Lateo\Explorer\Db\TransactionStore;
use Lateo\Explorer\Tests\Support\WebTestBase;

/** The line of the status page about the transaction indexer. */
final class StatusTransactionsTest extends WebTestBase
{
    private function status(array $limits = []): string
    {
        $r = $this->get('/status', null, 'GET', $this->app($limits));
        $this->assertSame(200, $r->status);
        return $r->body;
    }

    public function testSwitchedOffAndNothingStored(): void
    {
        $this->loadStandardIndex();
        $body = $this->status();
        $this->assertStringContains('Indexing is switched off', $body);
        $this->assertStringNotContains('None stored yet', $body);
        $this->assertStringNotContains('Indexed up to block', $body);
    }

    public function testSwitchedOnAndNothingStoredYet(): void
    {
        $this->loadStandardIndex();
        $body = $this->status(['tx_backfill_enabled' => true]);
        $this->assertStringContains('None stored yet', $body);
        $this->assertStringNotContains('switched off', $body);
    }

    public function testTheMarkIsShownAsABlockLink(): void
    {
        $this->loadStandardIndex();
        (new TransactionStore($this->db))->storeBlocks([], 910645);
        $body = $this->status(['tx_backfill_enabled' => true]);
        $this->assertStringContains('Indexed up to block <a class="lt-num" href="/block/910645">910,645</a>', $body);
        $this->assertStringNotContains('not indexed yet', $body);
        // Switched off again later: the stored mark is still the truth.
        $this->assertStringContains('Indexed up to block', $this->status());
    }

    public function testTheStatusPageRendersOnAVersion1DatabaseToo(): void
    {
        $db = \Lateo\Explorer\Db\Database::memory();
        \Lateo\Explorer\Db\Schema::migrate($db, [1 => \Lateo\Explorer\Db\Schema::migrations()[1]]);
        $this->db = $db;
        $this->loadStandardIndex();
        $this->assertStringContains('Indexing is switched off', $this->status());
    }
}
