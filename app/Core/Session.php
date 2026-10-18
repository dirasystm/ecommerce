<?php

declare(strict_types=1);

namespace Waimart\Core;

final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE || PHP_SAPI === 'cli') {
            if (PHP_SAPI === 'cli' && !isset($_SESSION)) {
                $_SESSION = [];
            }
            return;
        }
        $cfg = App::config();
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.gc_maxlifetime', (string) $cfg->get('session.lifetime', 7200));
        session_name((string) $cfg->get('session.name', 'waimart_sid'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => rtrim((string) $cfg->get('app.base_path', ''), '/') . '/',
            'secure'   => (bool) $cfg->get('session.secure', false),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        // Idle timeout.
        $now = time();
        $last = $_SESSION['_last_activity'] ?? $now;
        if ($now - $last > (int) $cfg->get('session.lifetime', 7200)) {
            self::destroy();
            session_start();
        }
        $_SESSION['_last_activity'] = $now;
        self::ageFlash();
    }

    /** Flash data written during request N is readable during request N+1 only. */
    public static function ageFlash(): void
    {
        $_SESSION['_flash_now'] = $_SESSION['_flash'] ?? [];
        $_SESSION['_flash'] = [];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /** New session ID, keeping data. Call on login / privilege change. */
    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        unset($_SESSION['_csrf']);
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'],
                'httponly' => true, 'samesite' => 'Lax',
            ]);
            session_destroy();
        }
    }

    public static function flash(string $key, mixed $value): void
    {
        $_SESSION['_flash'][$key] = $value;
    }

    public static function getFlash(string $key, mixed $default = null): mixed
    {
        return $_SESSION['_flash_now'][$key] ?? $_SESSION['_flash'][$key] ?? $default;
    }

    /** @return array<string,string> Validation errors flashed by the previous request. */
    public static function errors(): array
    {
        return (array) self::getFlash('_errors', []);
    }
}
