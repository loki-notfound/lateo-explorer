<?php

declare(strict_types=1);

// The indexer, run by cron: php bin/indexer.php --settings=/path/to/settings.php
//
// Keeps blocks, pricing records and supply snapshots in SQLite up to date from the nodes in
// the settings file. See src/Indexer/IndexerApplication.php. Exit code 0, or 1 after an error.

use Lateo\Explorer\Config\Config;
use Lateo\Explorer\Config\ConfigException;
use Lateo\Explorer\Db\Database;
use Lateo\Explorer\Indexer\IndexerApplication;
use Lateo\Explorer\Rpc\CurlTransport;
use Lateo\Explorer\Support\SystemClock;

require __DIR__ . '/../src/autoload.php';

$settings = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--settings=') && strlen($arg) > 11) {
        $settings = substr($arg, 11);
    }
}
if ($settings === null) {
    fwrite(STDERR, "usage: php bin/indexer.php --settings=<path to settings.php>\n");
    exit(1);
}

try {
    $config = Config::load($settings);
} catch (ConfigException $e) {
    fwrite(STDERR, 'indexer: settings: ' . $e->getMessage() . "\n");
    exit(1);
}

exit((new IndexerApplication(
    $config,
    new CurlTransport(),
    new SystemClock(),
    static fn (): Database => Database::openWritable($config->database),
    STDOUT,
    STDERR,
))->run());
