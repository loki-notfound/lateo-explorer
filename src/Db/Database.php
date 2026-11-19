<?php

declare(strict_types=1);

namespace Lateo\Explorer\Db;

use PDO;

/**
 * A SQLite connection. The indexer opens one writable connection (WAL, synchronous NORMAL,
 * foreign keys on); the web code opens read-only connections.
 *
 * Every connection throws exceptions, uses native prepared statements and a busy timeout of
 * 5 seconds. A writer works in short transactions, one batch each (see transaction()).
 */
final class Database
{
    public const BUSY_TIMEOUT_MS = 5000;

    private function __construct(
        private readonly PDO $pdo,
        public readonly bool $readOnly,
    ) {
    }

    /**
     * The indexer's connection to the database file. Creates the file when it is missing.
     * WAL has to be in effect: on a file system where it is not (a network share), the open
     * fails rather than running without it.
     */
    public static function openWritable(string $path): self
    {
        $pdo = self::connect('sqlite:' . $path, null);
        $db = new self($pdo, false);
        $db->applyWriterPragmas(requireWal: true);
        return $db;
    }

    /**
     * A read-only connection for the web code: the file is opened with SQLITE_OPEN_READONLY
     * (so a missing file is an error, never created) and PRAGMA query_only refuses writes
     * as a second guard.
     */
    public static function openReadOnly(string $path): self
    {
        $db = new self(self::connect('sqlite:' . $path, \Pdo\Sqlite::OPEN_READONLY), true);
        $db->applyReaderPragmas();
        return $db;
    }

    /** A private in-memory database, for tests and bounded runs. Writable. */
    public static function memory(): self
    {
        $db = new self(self::connect('sqlite::memory:', null), false);
        $db->applyWriterPragmas(requireWal: false);
        return $db;
    }

    /**
     * A named in-memory database that several connections of one process can share, for tests
     * of the two connection kinds. A read-only connection to it gets the same pragmas as one
     * to a file (SQLite ignores the read-only open flag for memory databases, so query_only
     * is what refuses writes there).
     */
    public static function sharedMemory(string $name, bool $readOnly): self
    {
        if (preg_match('/\A[a-z0-9_]{1,40}\z/', $name) !== 1) {
            throw new \InvalidArgumentException('bad name for a shared memory database');
        }
        $dsn = 'sqlite:file:' . $name . '?mode=memory&cache=shared';
        if ($readOnly) {
            $db = new self(self::connect($dsn, \Pdo\Sqlite::OPEN_READONLY), true);
            $db->applyReaderPragmas();
            return $db;
        }
        $db = new self(self::connect($dsn, null), false);
        $db->applyWriterPragmas(requireWal: false);
        return $db;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Run $work in one transaction (BEGIN IMMEDIATE, so a second writer waits for the busy
     * timeout instead of failing half-way). The transaction is rolled back when $work throws.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function transaction(callable $work): mixed
    {
        if ($this->readOnly) {
            throw new \LogicException('a read-only connection cannot write');
        }
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $result = $work();
        } catch (\Throwable $e) {
            $this->pdo->exec('ROLLBACK');
            throw $e;
        }
        $this->pdo->exec('COMMIT');
        return $result;
    }

    /** @param array<int|string,mixed> $params */
    public function exec(string $sql, array $params = []): int
    {
        $st = $this->run($sql, $params);
        return $st->rowCount();
    }

    /**
     * @param array<int|string,mixed> $params
     * @return list<array<string,mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<int|string,mixed> $params
     * @return array<string,mixed>|null
     */
    public function one(string $sql, array $params = []): ?array
    {
        $rows = $this->all($sql, $params);
        return $rows[0] ?? null;
    }

    /** @param array<int|string,mixed> $params */
    public function value(string $sql, array $params = []): mixed
    {
        $row = $this->one($sql, $params);
        return $row === null ? null : reset($row);
    }

    /**
     * Prepare and run a statement. Integers are bound as integers (execute() would bind every
     * value as text).
     *
     * @param array<int|string,mixed> $params
     */
    private function run(string $sql, array $params): \PDOStatement
    {
        $st = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $name = is_int($key) ? $key + 1 : $key;
            $st->bindValue($name, $value, match (true) {
                is_int($value) => PDO::PARAM_INT,
                $value === null => PDO::PARAM_NULL,
                is_string($value) => PDO::PARAM_STR,
                default => throw new \InvalidArgumentException('unsupported parameter type'),
            });
        }
        $st->execute();
        return $st;
    }

    private static function connect(string $dsn, ?int $openFlags): PDO
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];
        if ($openFlags !== null) {
            $options[\Pdo\Sqlite::ATTR_OPEN_FLAGS] = $openFlags;
        }
        try {
            return new PDO($dsn, null, null, $options);
        } catch (\PDOException) {
            // The message of PDO names the file; keep paths out of messages.
            throw new \RuntimeException('database cannot be opened');
        }
    }

    private function applyWriterPragmas(bool $requireWal): void
    {
        $mode = strtolower((string) $this->pdo->query('PRAGMA journal_mode=WAL')->fetchColumn());
        if ($requireWal && $mode !== 'wal') {
            throw new \RuntimeException('database does not support WAL here');
        }
        $this->pdo->exec('PRAGMA synchronous=NORMAL');
        $this->pdo->exec('PRAGMA busy_timeout=' . self::BUSY_TIMEOUT_MS);
        $this->pdo->exec('PRAGMA foreign_keys=ON');
    }

    private function applyReaderPragmas(): void
    {
        $this->pdo->exec('PRAGMA busy_timeout=' . self::BUSY_TIMEOUT_MS);
        $this->pdo->exec('PRAGMA query_only=ON');
    }
}
