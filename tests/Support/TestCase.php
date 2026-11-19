<?php

declare(strict_types=1);

namespace Lateo\Explorer\Tests\Support;

/** Base class of the tests: assertions and a scratch directory per test. */
abstract class TestCase
{
    /** Base directory for scratch space, set by tests/run.php. */
    public static string $scratchBase = '';
    public static int $assertions = 0;

    /** @var list<string> */
    private array $scratchDirs = [];
    private static int $scratchCounter = 0;

    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
        foreach ($this->scratchDirs as $dir) {
            self::removeTree($dir);
        }
        $this->scratchDirs = [];
    }

    /** A new empty directory that is removed after the test. */
    protected function scratch(): string
    {
        $dir = rtrim(self::$scratchBase, '/') . '/t' . (++self::$scratchCounter);
        mkdir($dir, 0700, true);
        $this->scratchDirs[] = $dir;
        return $dir;
    }

    public static function removeTree(string $dir): void
    {
        if (!file_exists($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . '/' . $name;
            if (is_dir($path)) {
                self::removeTree($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    protected function fail(string $message): never
    {
        throw new AssertionFailure($message);
    }

    protected function assertTrue(bool $condition, string $message = 'expected true'): void
    {
        self::$assertions++;
        if (!$condition) {
            $this->fail($message);
        }
    }

    protected function assertFalse(bool $condition, string $message = 'expected false'): void
    {
        $this->assertTrue(!$condition, $message);
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::$assertions++;
        if ($expected !== $actual) {
            $this->fail(($message !== '' ? $message . ': ' : '') . 'expected ' . self::show($expected) . ', got ' . self::show($actual));
        }
    }

    protected function assertNull(mixed $actual, string $message = ''): void
    {
        $this->assertSame(null, $actual, $message);
    }

    protected function assertCount(int $expected, array $actual, string $message = ''): void
    {
        $this->assertSame($expected, count($actual), $message !== '' ? $message : 'count');
    }

    protected function assertInstanceOf(string $class, mixed $actual): void
    {
        $this->assertTrue($actual instanceof $class, 'expected instance of ' . $class . ', got ' . get_debug_type($actual));
    }

    protected function assertStringContains(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertTrue(str_contains($haystack, $needle), ($message !== '' ? $message . ': ' : '') . 'expected "' . $needle . '" in "' . $haystack . '"');
    }

    protected function assertStringNotContains(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertTrue(!str_contains($haystack, $needle), ($message !== '' ? $message . ': ' : '') . 'did not expect "' . $needle . '" in "' . $haystack . '"');
    }

    /**
     * @template T of \Throwable
     * @param class-string<T> $class
     * @return T
     */
    protected function assertThrows(string $class, callable $fn, string $message = ''): \Throwable
    {
        self::$assertions++;
        try {
            $fn();
        } catch (\Throwable $e) {
            if ($e instanceof $class) {
                return $e;
            }
            $this->fail(($message !== '' ? $message . ': ' : '') . 'expected ' . $class . ', got ' . $e::class . ': ' . $e->getMessage());
        }
        $this->fail(($message !== '' ? $message . ': ' : '') . 'expected ' . $class . ', nothing was thrown');
    }

    private static function show(mixed $v): string
    {
        if (is_string($v)) {
            return '"' . (strlen($v) > 80 ? substr($v, 0, 80) . '...' : $v) . '"';
        }
        if (is_scalar($v) || $v === null) {
            return var_export($v, true);
        }
        if ($v instanceof \UnitEnum) {
            return $v::class . '::' . $v->name;
        }
        return get_debug_type($v);
    }
}
