<?php

declare(strict_types=1);

// Writes conversion vectors (JSON, format zephyr-conversion-vectors) to standard output:
//   php bin/vectors.php --settings=/path/to/settings.php [--sample | --all]
//
// Reads only the database (read-only connection), no node. --sample (the default) writes the
// first transaction of each kind the research lists, --all every checked conversion and audit.
// Exit code 0, or 1 after an error. See src/Cli/VectorsApplication.php.

use Lateo\Explorer\Cli\VectorsApplication;
use Lateo\Explorer\Config\Config;
use Lateo\Explorer\Config\ConfigException;
use Lateo\Explorer\Db\Database;
use Lateo\Explorer\Support\SystemClock;

require __DIR__ . '/../src/autoload.php';

$settings = null;
$all = false;
$sample = false;
$bad = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--settings=') && strlen($arg) > 11) {
        $settings = substr($arg, 11);
    } elseif ($arg === '--all') {
        $all = true;
    } elseif ($arg === '--sample') {
        $sample = true;
    } else {
        $bad = true;
    }
}
if ($settings === null || $bad || ($all && $sample)) {
    fwrite(STDERR, "usage: php bin/vectors.php --settings=<path to settings.php> [--sample | --all]\n");
    exit(1);
}

try {
    $config = Config::load($settings);
} catch (ConfigException $e) {
    fwrite(STDERR, 'vectors: settings: ' . $e->getMessage() . "\n");
    exit(1);
}

exit((new VectorsApplication(
    static fn (): Database => Database::openReadOnly($config->database),
    new SystemClock(),
    STDOUT,
    STDERR,
))->run($all));
