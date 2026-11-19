<?php

declare(strict_types=1);

// A small PSR-4 style autoloader for the namespace Lateo\Explorer (no Composer).
// Lateo\Explorer\Rpc\RpcClient is loaded from src/Rpc/RpcClient.php.

spl_autoload_register(static function (string $class): void {
    $prefix = 'Lateo\\Explorer\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});
