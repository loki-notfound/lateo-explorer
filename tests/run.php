<?php

declare(strict_types=1);

// Runs the tests: php tests/run.php
//
// Discovers tests/*Test.php, runs the public methods named test* of each class (a fresh
// object per method, setUp and tearDown around it), prints one line per failure and a
// summary, and exits 0 when nothing failed and at least one test passed, and 1 otherwise.
// A test that throws SkipTest (a capability is missing here) counts as skipped, and its reason
// is printed. Any PHP warning, notice or deprecation raised by a test counts as a failure of
// that test.
//
// Scratch space: the constant LATEO_TEST_SCRATCH when defined, otherwise a new directory
// under sys_get_temp_dir() that is removed afterwards.

use Lateo\Explorer\Tests\Support\AssertionFailure;
use Lateo\Explorer\Tests\Support\SkipTest;
use Lateo\Explorer\Tests\Support\TestCase;

require __DIR__ . '/../src/autoload.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Lateo\\Explorer\\Tests\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return false; // silenced with @
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$ownScratch = null;
if (defined('LATEO_TEST_SCRATCH')) {
    $base = (string) constant('LATEO_TEST_SCRATCH');
    if (!file_exists($base)) {
        mkdir($base, 0700, true);
    }
} else {
    $base = sys_get_temp_dir() . '/lateo-explorer-tests-' . bin2hex(random_bytes(6));
    mkdir($base, 0700, true);
    $ownScratch = $base;
}
TestCase::$scratchBase = $base;

$files = scandir(__DIR__);
$files = $files === false ? [] : $files;
sort($files);
$classes = [];
foreach ($files as $file) {
    if (preg_match('/\A([A-Za-z0-9]+Test)\.php\z/', $file, $m) === 1) {
        $classes[] = 'Lateo\\Explorer\\Tests\\' . $m[1];
    }
}

$started = microtime(true);
$total = 0;
$failed = 0;
$skipped = 0;
foreach ($classes as $class) {
    $ref = new ReflectionClass($class);
    if (!$ref->isSubclassOf(TestCase::class) || $ref->isAbstract()) {
        continue;
    }
    foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        $name = $method->getName();
        if (!str_starts_with($name, 'test') || $method->isStatic()) {
            continue;
        }
        $total++;
        $test = $ref->newInstance();
        $label = $ref->getShortName() . '::' . $name;
        try {
            $test->setUp();
            $test->$name();
        } catch (SkipTest $e) {
            $skipped++;
            echo 'SKIP ' . $label . ': ' . $e->getMessage() . "\n";
        } catch (AssertionFailure $e) {
            $failed++;
            $where = $e->getFile() . ':' . $e->getLine();
            foreach ($e->getTrace() as $frame) {
                if (isset($frame['file']) && basename($frame['file']) !== 'TestCase.php') {
                    $where = $frame['file'] . ':' . ($frame['line'] ?? 0);
                    break;
                }
            }
            echo 'FAIL ' . $label . ': ' . $e->getMessage() . ' (' . basename($where) . ")\n";
        } catch (Throwable $e) {
            $failed++;
            echo 'ERROR ' . $label . ': ' . $e::class . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n";
        } finally {
            try {
                $test->tearDown();
            } catch (Throwable $e) {
                $failed++;
                echo 'ERROR ' . $label . ' (tearDown): ' . $e->getMessage() . "\n";
            }
        }
    }
}

if ($ownScratch !== null) {
    TestCase::removeTree($ownScratch);
}

$passed = $total - $failed - $skipped;
printf(
    "%d tests, %d passed, %d failed, %d skipped, %d assertions, %.2f s\n",
    $total,
    $passed,
    $failed,
    $skipped,
    TestCase::$assertions,
    microtime(true) - $started,
);
exit($failed === 0 && $passed > 0 ? 0 : 1);
