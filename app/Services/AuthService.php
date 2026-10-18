<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Auth;
use Waimart\Core\RateLimiter;
use Waimart\Core\ValidationException;
use Waimart\Core\Validator;

/**
 * Credential checks with brute-force protection. Error messages never reveal whether
 * an email is registered.
 */
final class AuthService
{
    public const GENERIC_ERROR = 'Incorrect email, phone number or password.';
    private const MAX_PER_ACCOUNT = 5;      // per 15 minutes
    private const MAX_PER_IP = 30;          // per 15 minutes
    private const WINDOW = 900;

    private static ?string $dummyHash = null;

    /** A real hash so verifying an unknown account costs the same time (timing side-channel). */
    private static function dummyHash(): string
    {
        return self::$dummyHash ??= password_hash(random_bytes(16), PASSWORD_DEFAULT);
    }

    /**
     * What the person typed in "Email or phone number": an email (contains @) or a Kenyan phone number in any common
     * form. Returns the users column to search and the normalised value; [null, raw] when it is neither (the sign-in
     * then fails with the same generic message and timing as a wrong password).
     * @return array{0:'email'|'phone'|null,1:string}
     */
    public static function identify(string $identifier): array
    {
        $raw = strtolower(trim($identifier));
        if ($raw === '') {
            return [null, ''];
        }
        if (str_contains($raw, '@')) {
            return ['email', $raw];
        }
        $phone = Validator::normalizePhone($raw);
        return $phone !== null ? ['phone', $phone] : [null, $raw];
    }

    /**
     * @return array<string,mixed> the authenticated user (already logged in to the session)
     * @throws ValidationException
     */
    public function attempt(string $identifier, string $password, string $ip, bool $staffOnly = false): array
    {
        [$column, $value] = self::identify($identifier);
        $limiter = new RateLimiter(App::db());
        // Keyed on the normalised identifier: 0712…, +254712… and 254712… share one limit.
        $accountKey = 'login|' . $value;
        $ipKey = 'login-ip|' . $ip;

        if ($limiter->tooMany($accountKey, self::MAX_PER_ACCOUNT, self::WINDOW)
            || !$limiter->attempt($ipKey, self::MAX_PER_IP, self::WINDOW)) {
            throw ValidationException::withMessage('Too many sign-in attempts. Please wait 15 minutes and try again.');
        }

        // Column names come from identify() only (never from input).
        $user = $column === null ? null : App::db()->one("SELECT * FROM users WHERE $column = ?", [$value]);
        $valid = password_verify($password, $user['password_hash'] ?? self::dummyHash())
            && $user !== null
            && $user['status'] === 'active'
            && (!$staffOnly || (int) $user['is_staff'] === 1);

        if (!$valid) {
            $limiter->attempt($accountKey, self::MAX_PER_ACCOUNT, self::WINDOW);
            if ($user !== null && $staffOnly) {
                AuditLogger::log('auth.staff_login_failed', 'user', (int) $user['id'], null, ['ip' => $ip], (int) $user['id']);
            }
            throw ValidationException::withMessage(self::GENERIC_ERROR);
        }

        $limiter->clear($accountKey);
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            App::db()->exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        }
        Auth::login($user);
        if ((int) $user['is_staff'] === 1) {
            AuditLogger::log('auth.staff_login', 'user', (int) $user['id'], null, ['ip' => $ip], (int) $user['id']);
        }
        return $user;
    }
}
