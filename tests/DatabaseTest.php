<?php

declare(strict_types=1);

namespace Lateo\Explorer\Tests;

use Lateo\Explorer\Db\Database;
use Lateo\Explorer\Db\Schema;
use Lateo\Explorer\Tests\Support\TestCase;

final class DatabaseTest extends TestCase
{
    private const TABLES = [
        'blocks', 'conversion_checks', 'meta', 'orphaned_blocks', 'pricing_records', 'reorgs', 'runs', 'supply_snapshots', 'transactions',
    ];

    /** @return list<string> */
    private function tables(Database $db): array
    {
        $names = array_map(
            static fn (array $r): string => (string) $r['name'],
            $db->all("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"),
        );
        return $names;
    }

    public function testSchemaIsCreatedAndVersioned(): void
    {
        $db = Database::memory();
        $this->assertSame(0, Schema::currentVersion($db));
        $this->assertSame(Schema::VERSION, Schema::migrate($db));
        $this->assertSame(Schema::VERSION, Schema::currentVersion($db));
        $this->assertSame(self::TABLES, $this->tables($db));
        // Running it again changes nothing.
        $this->assertSame(Schema::VERSION, Schema::migrate($db));
        $this->assertSame(self::TABLES, $this->tables($db));
    }

    public function testEveryChainTableIsKeyedByHeight(): void
    {
        $db = Database::memory();
        Schema::migrate($db);
        foreach (['blocks', 'pricing_records'] as $table) {
            $cols = array_map(
                static fn (array $r): string => (string) $r['name'],
                $db->all('SELECT name FROM pragma_table_info(?) WHERE pk > 0 ORDER BY pk', [$table]),
            );
            $this->assertSame('height', $cols[0], $table . ' primary key starts with height');
        }
    }

    public function testForwardMigrationsRunOnceInOrder(): void
    {
        $db = Database::memory();
        $steps = [
            1 => ['CREATE TABLE a (x INTEGER)', 'INSERT INTO a VALUES (1)'],
        ];
        $this->assertSame(1, Schema::migrate($db, $steps));
        $steps[2] = ['ALTER TABLE a ADD COLUMN y INTEGER', 'UPDATE a SET y = x + 1'];
        $steps[3] = ['CREATE TABLE b (z INTEGER)'];
        $this->assertSame(3, Schema::migrate($db, $steps));
        $this->assertSame(2, (int) $db->value('SELECT y FROM a'));
        $this->assertSame(1, (int) $db->value('SELECT count(*) FROM a'), 'version 1 was not run again');
        $this->assertSame(3, Schema::currentVersion($db));
    }

    public function testFailedMigrationLeavesTheVersionAlone(): void
    {
        $db = Database::memory();
        Schema::migrate($db, [1 => ['CREATE TABLE a (x INTEGER)']]);
        $this->assertThrows(\PDOException::class, fn () => Schema::migrate($db, [
            1 => ['CREATE TABLE a (x INTEGER)'],
            2 => ['CREATE TABLE c (x INTEGER)', 'THIS IS NOT SQL'],
        ]));
        $this->assertSame(1, Schema::currentVersion($db));
        $this->assertSame(0, (int) $db->value("SELECT count(*) FROM sqlite_master WHERE name = 'c'"), 'the half migration was rolled back');
    }

    public function testNewerDatabaseIsRefused(): void
    {
        $db = Database::memory();
        Schema::migrate($db, [1 => ['CREATE TABLE a (x INTEGER)'], 2 => ['CREATE TABLE b (x INTEGER)']]);
        $e = $this->assertThrows(\RuntimeException::class, fn () => Schema::migrate($db, [1 => ['CREATE TABLE a (x INTEGER)']]));
        $this->assertStringContains('newer', $e->getMessage());
    }

    public function testWriterPragmas(): void
    {
        $db = Database::memory();
        $this->assertSame(1, (int) $db->value('PRAGMA foreign_keys'));
        $this->assertSame(5000, (int) $db->value('PRAGMA busy_timeout'));
        $this->assertSame(1, (int) $db->value('PRAGMA synchronous'), 'NORMAL is 1');
        $this->assertFalse($db->readOnly);
    }

    public function testForeignKeysAreEnforced(): void
    {
        $db = Database::memory();
        Schema::migrate($db);
        $this->assertThrows(\PDOException::class, fn () => $db->exec(
            'INSERT INTO pricing_records (height, spot, moving_average, stable, stable_ma, reserve, reserve_ma,
                reserve_ratio, reserve_ratio_ma, yield_price, timestamp, signature)
             VALUES (5, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, \'' . str_repeat('ab', 64) . '\')',
        ));
    }

    public function testReadOnlyConnectionRefusesWrites(): void
    {
        $writer = Database::sharedMemory('lateo_ro_test', false);
        Schema::migrate($writer);
        $writer->exec('INSERT INTO meta (key, value) VALUES (\'k\', \'v\')');

        $reader = Database::sharedMemory('lateo_ro_test', true);
        $this->assertTrue($reader->readOnly);
        $this->assertSame('v', $reader->value('SELECT value FROM meta WHERE key = \'k\''));
        $this->assertSame(5000, (int) $reader->value('PRAGMA busy_timeout'));
        $this->assertThrows(\PDOException::class, fn () => $reader->exec('DELETE FROM meta'));
        $this->assertThrows(\LogicException::class, fn () => $reader->transaction(static fn (): int => 1));
        $this->assertSame('v', $writer->value('SELECT value FROM meta WHERE key = \'k\''), 'nothing was deleted');
    }

    public function testReadOnlyFileOpenNeverCreatesAFile(): void
    {
        $e = $this->assertThrows(\RuntimeException::class, fn () => Database::openReadOnly('/nonexistent-directory/explorer.sqlite'));
        $this->assertSame('database cannot be opened', $e->getMessage());
        $this->assertStringNotContains('nonexistent', $e->getMessage());
    }

    public function testTransactionRollsBackOnError(): void
    {
        $db = Database::memory();
        Schema::migrate($db);
        $this->assertThrows(\RuntimeException::class, function () use ($db): void {
            $db->transaction(function () use ($db): void {
                $db->exec('INSERT INTO meta (key, value) VALUES (\'x\', \'1\')');
                throw new \RuntimeException('stop');
            });
        });
        $this->assertNull($db->value('SELECT value FROM meta WHERE key = \'x\''));
        $this->assertSame(7, $db->transaction(static fn (): int => 7));
    }

    public function testIntegersAreBoundAsIntegers(): void
    {
        $db = Database::memory();
        $db->exec('CREATE TABLE t (v)'); // no column affinity: the stored type shows how it was bound
        $db->exec('INSERT INTO t (v) VALUES (?)', [9223372036854775807]);
        $db->exec('INSERT INTO t (v) VALUES (?)', ['text']);
        $types = array_column($db->all('SELECT typeof(v) AS t FROM t ORDER BY rowid'), 't');
        $this->assertSame(['integer', 'text'], $types);
        $this->assertSame(9223372036854775807, $db->value('SELECT v FROM t ORDER BY rowid LIMIT 1'));
    }
}
