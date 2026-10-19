<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Auth;
use Waimart\Core\RateLimiter;
use Waimart\Core\Session;
use Waimart\Core\ValidationException;
use Waimart\Core\Validator;

/**
 * Credential checks with brute-force protection. Error messages never reveal whether
 * an email is registered. Every attempt is recorded in the sign-in activity (AuthEvents).
 *
 * Accounts with two-step sign-in are not signed in by the password alone: attempt() returns with 'mfa_required' and
 * keeps only a short-lived "password checked" marker in the (regenerated) session; completeMfa() then signs in.
 * This applies on every sign-in page, so the customer sign-in page is not a way around a staff member's second step.
 */
final class AuthService
{
    public const GENERIC_ERROR = 'Incorrect email, phone number or password.';
    private const MAX_PER_ACCOUNT = 5;      // per 15 minutes
    private const MAX_PER_IP = 30;          // per 15 minutes
    private const WINDOW = 900;

    private const MFA_PENDING_SECONDS = 300;

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
            AuthEvents::record('login_locked', null, $value, $staffOnly ?: null);
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
            // Unknown identifiers at the staff sign-in are worth seeing too (someone probing for staff accounts).
            AuthEvents::record('login_failed', $user !== null ? (int) $user['id'] : null, $value,
                $user !== null ? (int) $user['is_staff'] === 1 : $staffOnly);
            throw ValidationException::withMessage(self::GENERIC_ERROR);
        }

        $limiter->clear($accountKey);
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            App::db()->exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        }
        if (MfaService::enabled($user)) {
            Session::regenerate();
            Session::set('mfa_pending', ['user_id' => (int) $user['id'], 'at' => time()]);
            AuthEvents::record('mfa_challenged', (int) $user['id'], $value);
            return $user + ['mfa_required' => true];
        }
        $this->signIn($user, $ip, $value);
        return $user;
    }

    /** The person whose password was just checked and who still owes a second step, if any (5 minutes). */
    public static function pendingMfaUser(): ?int
    {
        $p = Session::get('mfa_pending');
        if (!is_array($p) || time() - (int) ($p['at'] ?? 0) > self::MFA_PENDING_SECONDS) {
            Session::forget('mfa_pending');
            return null;
        }
        return (int) $p['user_id'];
    }

    /** Second step: an authenticator or recovery code. @return array<string,mixed> the signed-in user */
    public function completeMfa(string $code, string $ip): array
    {
        $userId = self::pendingMfaUser() ?? throw ValidationException::withMessage('Your sign-in expired. Please enter your password again.');
        $how = (new MfaService())->verify($userId, $code);
        Session::forget('mfa_pending');
        $user = App::db()->one("SELECT * FROM users WHERE id = ? AND status = 'active'", [$userId])
            ?? throw ValidationException::withMessage(self::GENERIC_ERROR);
        AuthEvents::record($how === 'recovery' ? 'recovery_code_used' : 'mfa_succeeded', $userId);
        $this->signIn($user, $ip, null);
        return $user;
    }

    /** @param array<string,mixed> $user */
    private function signIn(array $user, string $ip, ?string $identifier): void
    {
        Auth::login($user);
        AuthEvents::record('login_succeeded', (int) $user['id'], $identifier, (int) $user['is_staff'] === 1);
        if ((int) $user['is_staff'] === 1) {
            AuditLogger::log('auth.staff_login', 'user', (int) $user['id'], null, ['ip' => $ip], (int) $user['id']);
        }
    }
}
