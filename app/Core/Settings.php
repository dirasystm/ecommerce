<?php

declare(strict_types=1);

namespace Waimart\Core;

/**
 * Admin-editable settings stored in the `settings` table (e.g. reservation_minutes).
 * Values are cached per request.
 */
final class Settings
{
    /** @var array<string,string>|null */
    private static ?array $cache = null;

    public static function get(string $key, ?string $default = null): ?string
    {
        if (self::$cache === null) {
            self::$cache = [];
            foreach (App::db()->all('SELECT setting_key, setting_value FROM settings') as $r) {
                self::$cache[$r['setting_key']] = (string) $r['setting_value'];
            }
        }
        return self::$cache[$key] ?? $default;
    }

    public static function int(string $key, int $default): int
    {
        $v = self::get($key);
        return $v === null || !is_numeric($v) ? $default : (int) $v;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key);
        return $v === null ? $default : in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
    }

    public static function set(string $key, string $value): void
    {
        App::db()->exec(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
            [$key, $value]
        );
        self::flush();
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    /**
     * Reservation window in minutes (C4), clamped to a sane range.
     * Cash-on-delivery orders (awaiting staff confirmation) use their own window.
     */
    public static function reservationMinutes(?string $paymentMethod = null): int
    {
        $minutes = $paymentMethod === 'cod'
            ? self::int('reservation_minutes_cod', 240)
            : self::int('reservation_minutes', 30);
        return max(5, min(10080, $minutes));
    }
}
