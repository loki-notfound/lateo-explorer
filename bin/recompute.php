<?php

declare(strict_types=1);

// Recomputes every conversion and audit from the database: php bin/recompute.php --settings=/path/to/settings.php
//
// Reads only the database (no node). Empties conversion_checks, refills it with the current
// rule and prints a summary. It takes the indexer's lock file. Exit code 0 when no check
// is a mismatch, 2 when there is one, 1 after an error. See src/Cli/RecomputeApplication.php.

use Lateo\Explorer\Cli\RecomputeApplication;
use Lateo\Explorer\Config\Config;
use Lateo\Explorer\Config\ConfigException;
use Lateo\Explorer\Db\Database;

require __DIR__ . '/../src/autoload.php';

$settings = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--settings=') && strlen($arg) > 11) {
        $settings = substr($arg, 11);
    }
}
if ($settings === null) {
    fwrite(STDERR, "usage: php bin/recompute.php --settings=<path to settings.php>\n");
    exit(1);
}

try {
    $config = Config::load($settings);
} catch (ConfigException $e) {
    fwrite(STDERR, 'recompute: settings: ' . $e->getMessage() . "\n");
    exit(1);
}

exit((new RecomputeApplication(
    $config,
    static fn (): Database => Database::openWritable($config->database),
    STDOUT,
    STDERR,
))->run());
