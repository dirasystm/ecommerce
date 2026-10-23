<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Auth;
use Waimart\Core\Session;

/**
 * "Keep me signed in": customers and sellers stay signed in on their device (browser or app) until they sign out, even
 * after the browser or app is closed. The cookie holds selector:validator; the database keeps only a SHA-256 of the
 * validator, so a leaked table cannot sign anyone in. Each use extends it (at most once a day).
 *
 * Never for staff: the admin always needs a password (and two-step sign-in where required). A password change,
 * "sign out everywhere" or a suspended account ends every remembered sign-in of that account.
 */
final class RememberMe
{
    public const DAYS = 365;

    public static function cookieName(): string
    {
        return (string) config('session.name', 'waimart_sid') . '_keep';
    }

    /** @param array<string,mixed> $user just signed in */
    public static function issue(array $user): void
    {
        if ((int) ($user['is_staff'] ?? 0) === 1) {
            return;
        }
        try {
            self::create($user);
        } catch (\PDOException) {
            // Database update (035) not applied yet: the person is signed in for this visit only.
        }
    }

    /** @param array<string,mixed> $user */
    private static function create(array $user): void
    {
        $db = App::db();
        self::forget();   // replaces this device's previous token, if any
        $db->exec('DELETE FROM remember_tokens WHERE user_id = ? AND expires_at < NOW()', [$user['id']]);
        $selector = bin2hex(random_bytes(12));
        $validator = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $db->insert('remember_tokens', [
            'user_id' => (int) $user['id'], 'selector' => $selector, 'validator_hash' => hash('sha256', $validator),
            'user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null,
            'expires_at' => date('Y-m-d H:i:s', time() + self::DAYS * 86400),
        ]);
        self::setCookie($selector . ':' . $validator);
    }

    /** Signs the person back in from their cookie when the session has ended (browser or app closed, idle timeout). */
    public static function restore(): void
    {
        $raw = (string) ($_COOKIE[self::cookieName()] ?? '');
        if ($raw === '' || Auth::check()) {
            return;
        }
        $parts = explode(':', $raw, 2);
        if (count($parts) !== 2 || !preg_match('/^[a-f0-9]{24}$/', $parts[0]) || !preg_match('/^[A-Za-z0-9_-]{43}$/', $parts[1])) {
            self::setCookie(null);
            return;
        }
        $db = App::db();
        $t = $db->one(
            "SELECT t.*, u.is_staff, u.password_changed_at, u.sessions_revoked_at
               FROM remember_tokens t JOIN users u ON u.id = t.user_id AND u.status = 'active'
              WHERE t.selector = ? AND t.expires_at > NOW()",
            [$parts[0]]
        );
        $valid = $t !== null && hash_equals((string) $t['validator_hash'], hash('sha256', $parts[1])) && (int) $t['is_staff'] === 0;
        foreach (['password_changed_at', 'sessions_revoked_at'] as $col) {
            if ($valid && $t[$col] !== null && strtotime((string) $t[$col]) > strtotime((string) $t['created_at'])) {
                $valid = false;   // signed out everywhere, or the password changed, after this device was remembered
            }
        }
        if (!$valid) {
            if ($t !== null) {
                $db->exec('DELETE FROM remember_tokens WHERE id = ?', [$t['id']]);
            }
            self::setCookie(null);
            return;
        }
        $user = $db->one('SELECT * FROM users WHERE id = ?', [$t['user_id']]);
        Auth::login((array) $user);
        Session::set('remembered', true);
        if ($t['last_used_at'] === null || strtotime((string) $t['last_used_at']) < time() - 86400) {
            $db->exec('UPDATE remember_tokens SET last_used_at = NOW(), expires_at = ? WHERE id = ?',
                [date('Y-m-d H:i:s', time() + self::DAYS * 86400), $t['id']]);
            self::setCookie($raw);
        }
    }

    /** Signing out on this device: its remembered sign-in ends too. */
    public static function forget(): void
    {
        $raw = (string) ($_COOKIE[self::cookieName()] ?? '');
        if ($raw === '') {
            return;
        }
        $selector = explode(':', $raw, 2)[0];
        if (preg_match('/^[a-f0-9]{24}$/', $selector)) {
            App::db()->exec('DELETE FROM remember_tokens WHERE selector = ?', [$selector]);
        }
        self::setCookie(null);
    }

    private static function setCookie(?string $value): void
    {
        if ($value === null) {
            unset($_COOKIE[self::cookieName()]);
        } else {
            $_COOKIE[self::cookieName()] = $value;
        }
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }
        setcookie(self::cookieName(), $value ?? '', [
            'expires' => $value === null ? time() - 3600 : time() + self::DAYS * 86400,
            'path' => rtrim((string) config('app.base_path', ''), '/') . '/',
            'secure' => (bool) config('session.secure', false),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
