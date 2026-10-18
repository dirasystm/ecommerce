<?php

declare(strict_types=1);

namespace Waimart\Core;

/**
 * Minimal service container / application state.
 */
final class App
{
    private static ?Config $config = null;
    private static ?Database $db = null;
    private static string $root = '';

    public static function boot(string $root): void
    {
        self::$root = rtrim(str_replace('\\', '/', $root), '/');
        self::$config = Config::load(self::$root . '/config');
        date_default_timezone_set((string) self::$config->get('app.timezone', 'Africa/Nairobi'));
        mb_internal_encoding('UTF-8');
    }

    public static function root(): string
    {
        return self::$root;
    }

    public static function config(): Config
    {
        if (self::$config === null) {
            throw new \RuntimeException('App not booted.');
        }
        return self::$config;
    }

    public static function db(): Database
    {
        return self::$db ??= new Database(self::config()->get('db'));
    }

    /** Used by tests to swap or reset the connection. */
    public static function setDb(?Database $db): void
    {
        self::$db = $db;
    }

    public static function env(): string
    {
        return (string) self::config()->get('app.env', 'production');
    }

    public static function isDebug(): bool
    {
        return (bool) self::config()->get('app.debug', false);
    }
}
