<?php

declare(strict_types=1);

// The front controller. Every path that is not a file reaches this script (see .htaccess).
//
// It reads the repository root and the settings file from the constants LATEO_EXPLORER_ROOT
// and LATEO_EXPLORER_SETTINGS when a deploy stub defines them; otherwise the root is the
// directory above this one and the settings path is the environment variable
// LATEO_EXPLORER_SETTINGS. It builds a response with the application and sends it: this is
// the only place that sends headers or output.

use Lateo\Explorer\Config\Config;
use Lateo\Explorer\Db\Database;
use Lateo\Explorer\Rpc\CurlTransport;
use Lateo\Explorer\Support\SystemClock;
use Lateo\Explorer\Web\Application;
use Lateo\Explorer\Web\Request;

$root = defined('LATEO_EXPLORER_ROOT') ? (string) constant('LATEO_EXPLORER_ROOT') : dirname(__DIR__);
$settings = defined('LATEO_EXPLORER_SETTINGS') ? (string) constant('LATEO_EXPLORER_SETTINGS') : (string) getenv('LATEO_EXPLORER_SETTINGS');

header_remove('X-Powered-By');

try {
    require $root . '/src/autoload.php';
    $config = Config::load($settings);
    $application = new Application(
        $config,
        new CurlTransport(),
        new SystemClock(),
        static fn (): Database => Database::openReadOnly($config->database),
        $root,
    );
    $request = Request::fromGlobals($_SERVER);
    $response = $application->handleSafely($request, static function (\Throwable $e): void {
        error_log('explorer: ' . $e::class);
    });
} catch (\Throwable $e) {
    // The page cannot be built at all (settings missing, code broken). Say so without detail.
    error_log('explorer: ' . $e::class);
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
    header('X-Content-Type-Options: nosniff');
    echo "The explorer is not available right now. Reload the page in a minute.\n";
    exit;
}

http_response_code($response->status);
foreach ($response->headers as $name => $value) {
    header($name . ': ' . $value);
}
if ($request->method !== 'HEAD') {
    echo $response->body;
}
