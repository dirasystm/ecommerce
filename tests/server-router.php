<?php

// Router for `php -S` during feature tests: serve real files from public/, else the front controller.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$file = realpath(__DIR__ . '/../public' . $path);
$public = realpath(__DIR__ . '/../public');
if ($file !== false && is_file($file) && str_starts_with($file, $public) && !str_ends_with($file, '.php')) {
    return false;
}
require __DIR__ . '/../public/index.php';
