<?php

declare(strict_types=1);

// Prints counts about the shape of the stored transactions (versions, ringct types, outputs of
// another variant than the tagged key, miner transactions):
//   php bin/shapes.php --settings=/path/to/settings.php
//
// Reads only the database (read-only connection), no node. Counts and heights only, never a
// hash. Exit code 0, or 1 after an error. See src/Cli/ShapesApplication.php.

use Lateo\Explorer\Cli\ShapesApplication;
use Lateo\Explorer\Config\Config;
use Lateo\Explorer\Config\ConfigException;
use Lateo\Explorer\Db\Database;

require __DIR__ . '/../src/autoload.php';

$settings = null;
$bad = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--settings=') && strlen($arg) > 11) {
        $settings = substr($arg, 11);
    } else {
        $bad = true;
    }
}
if ($settings === null || $bad) {
    fwrite(STDERR, "usage: php bin/shapes.php --settings=<path to settings.php>\n");
    exit(1);
}

try {
    $config = Config::load($settings);
} catch (ConfigException $e) {
    fwrite(STDERR, 'shapes: settings: ' . $e->getMessage() . "\n");
    exit(1);
}

exit((new ShapesApplication(
    static fn (): Database => Database::openReadOnly($config->database),
    STDOUT,
    STDERR,
))->run());
