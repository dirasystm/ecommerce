<?php

declare(strict_types=1);

use Waimart\Core\App;
use Waimart\Core\Logger;

require __DIR__ . '/vendor/autoload.php';

App::boot(__DIR__);

error_reporting(E_ALL);
ini_set('display_errors', App::isDebug() ? '1' : '0');
ini_set('log_errors', '1');

// Convert warnings/notices into exceptions so they are never silently ignored.
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(static function (Throwable $e): void {
    Logger::error('Uncaught: ' . $e->getMessage(), ['file' => $e->getFile() . ':' . $e->getLine()]);
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
    http_response_code(500);
    echo App::isDebug() ? '<pre>' . htmlspecialchars((string) $e) . '</pre>' : 'Something went wrong.';
});
