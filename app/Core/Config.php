<?php

declare(strict_types=1);

namespace Waimart\Core;

final class Config
{
    /** @param array<string,mixed> $items */
    private function __construct(private array $items)
    {
    }

    /**
     * Loads config.php, overlays config.local.php (secrets, git-ignored), then config.live.php (git-ignored) on the
     * live server, then the "testing" overlay when WAIMART_ENV=testing.
     */
    public static function load(string $dir): self
    {
        $items = require $dir . '/config.php';
        if (is_file($dir . '/config.local.php')) {
            $items = array_replace_recursive($items, require $dir . '/config.local.php');
        }
        if (getenv('WAIMART_ENV') !== 'testing' && is_file($dir . '/config.live.php') && self::isLive()) {
            $items = array_replace_recursive($items, require $dir . '/config.live.php');
        }
        if (getenv('WAIMART_ENV') === 'testing') {
            $items['app']['env'] = 'testing';
            $items['db']['database'] = $items['db']['test_database'] ?? 'waimart_test';
            $items['mail']['driver'] = 'log';
            // Feature tests serve the app from the PHP built-in server at the root path.
            // ("/" means root; an empty env var is dropped on Windows.)
            if (($base = getenv('WAIMART_BASE_PATH')) !== false) {
                $items['app']['base_path'] = rtrim($base, '/');
            }
        }
        return new self($items);
    }

    /**
     * True on the live server. Development runs on XAMPP for Windows and the live host is Linux, so the operating
     * system decides; it never depends on the request (a forged Host header cannot switch databases).
     * WAIMART_LIVE=1 or 0 overrides it, e.g. for a Linux development machine.
     */
    public static function isLive(): bool
    {
        $force = getenv('WAIMART_LIVE');
        if ($force === '1' || $force === '0') {
            return $force === '1';
        }
        return PHP_OS_FAMILY !== 'Windows';
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    public function set(string $key, mixed $value): void
    {
        $ref = &$this->items;
        foreach (explode('.', $key) as $segment) {
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $ref[$segment] = [];
            }
            $ref = &$ref[$segment];
        }
        $ref = $value;
    }
}
