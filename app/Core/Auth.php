<?php

declare(strict_types=1);

namespace Waimart\Core;

/**
 * Session-based authentication and permission checks.
 */
final class Auth
{
    /** @var array<string,mixed>|null|false false = not loaded yet */
    private static array|null|false $user = false;
    /** @var list<string>|null */
    private static ?array $permissions = null;

    /** @return array<string,mixed>|null */
    public static function user(): ?array
    {
        if (self::$user === false) {
            $id = (int) Session::get('user_id', 0);
            self::$user = $id > 0
                ? App::db()->one("SELECT * FROM users WHERE id = ? AND status = 'active'", [$id])
                : null;
            // A password change invalidates sessions that authenticated before it.
            if (self::$user !== null && self::$user['password_changed_at'] !== null
                && strtotime((string) self::$user['password_changed_at']) > (int) Session::get('auth_at', 0)) {
                self::$user = null;
            }
            if ($id > 0 && self::$user === null) {
                Session::forget('user_id');
                Session::forget('auth_at');
            }
        }
        return self::$user;
    }

    public static function id(): ?int
    {
        $u = self::user();
        return $u ? (int) $u['id'] : null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function isStaff(): bool
    {
        $u = self::user();
        return $u !== null && (int) $u['is_staff'] === 1;
    }

    /** @param array<string,mixed> $user */
    public static function login(array $user): void
    {
        Session::regenerate();
        Session::set('user_id', (int) $user['id']);
        Session::set('auth_at', time());
        App::db()->exec('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$user['id']]);
        self::reset();
    }

    /** Keeps the current session valid after this user changes their own password. */
    public static function touchAuthTime(): void
    {
        Session::set('auth_at', time() + 1);
        self::reset();
    }

    /**
     * Signs out and wipes ALL session data (order-access grants, pending claims, recently viewed,
     * checkout keys) so the next person on a shared device inherits nothing.
     */
    public static function logout(): void
    {
        $_SESSION = [];
        Session::regenerate();
        self::reset();
    }

    public static function reset(): void
    {
        self::$user = false;
        self::$permissions = null;
    }

    /** @return list<string> */
    public static function permissions(): array
    {
        if (self::$permissions !== null) {
            return self::$permissions;
        }
        $id = self::id();
        if ($id === null || !self::isStaff()) {
            return self::$permissions = [];
        }
        return self::$permissions = self::permissionsFor($id);
    }

    /** @return list<string> */
    public static function permissionsFor(int $userId): array
    {
        $rows = App::db()->all(
            "SELECT DISTINCT p.slug FROM user_roles ur
               JOIN roles r ON r.id = ur.role_id
               JOIN role_permissions rp ON rp.role_id = r.id
               JOIN permissions p ON p.id = rp.permission_id
              WHERE ur.user_id = ?
             UNION
             SELECT p.slug FROM permissions p
              WHERE EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id
                             WHERE ur.user_id = ? AND r.slug = 'super_admin')",
            [$userId, $userId]
        );
        return array_column($rows, 'slug');
    }

    public static function can(string $permission): bool
    {
        return in_array($permission, self::permissions(), true);
    }

    /** Throws 403 unless the current user has the permission. */
    public static function authorize(string $permission): void
    {
        if (!self::can($permission)) {
            throw HttpException::forbidden();
        }
    }
}
