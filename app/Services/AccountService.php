<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Auth;
use Waimart\Core\Crypto;
use Waimart\Core\Database;
use Waimart\Core\RateLimiter;
use Waimart\Core\Settings;
use Waimart\Core\ValidationException;
use Waimart\Core\Validator;

/**
 * Customer self-service: registration, profile, password, password reset, notification
 * preferences and saved addresses. Every address operation is scoped to the owner (D2).
 */
final class AccountService
{
    public const RESET_TTL_MINUTES = 60;
    /** An SMS reset code works for this long, and for this many tries. */
    public const RESET_CODE_MINUTES = 15;
    public const RESET_CODE_ATTEMPTS = 5;
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? App::db();
    }

    /** @param array<string,mixed> $in @return array<string,mixed> the new user (not logged in) */
    public function register(array $in, string $ip): array
    {
        if (!self::registrationOpen()) {
            throw ValidationException::withMessage('New accounts cannot be created at the moment. You can still check out as a guest.');
        }
        if (!(new RateLimiter($this->db))->attempt('register|' . $ip, 10, 3600)) {
            throw ValidationException::withMessage('Too many registrations from your network. Please try again later.');
        }
        Validator::validate($in, [
            'first_name' => 'required|max:80',
            'last_name' => 'required|max:80',
            'email' => 'required|email',
            'phone' => 'required|phone',
            'password' => 'required|password',
            'password_confirmation' => 'required|same:password',
            'accept_terms' => 'required',
        ], ['password_confirmation' => 'Password confirmation', 'accept_terms' => 'Acceptance of the terms']);

        $email = strtolower(trim((string) $in['email']));
        $phone = Validator::normalizePhone((string) $in['phone']);
        if ($this->db->value('SELECT id FROM users WHERE email = ?', [$email])) {
            throw new ValidationException(['email' => 'An account with this email already exists. Sign in or reset your password.']);
        }
        // Phone numbers are unique so customers can sign in with either (uq_users_phone).
        if ($this->db->value('SELECT id FROM users WHERE phone = ?', [$phone])) {
            throw new ValidationException(['phone' => 'An account with this phone number already exists. Sign in or reset your password.']);
        }
        try {
            $id = $this->db->insert('users', [
                'email' => $email,
                'phone' => $phone,
                'password_hash' => password_hash((string) $in['password'], PASSWORD_DEFAULT),
                'first_name' => trim((string) $in['first_name']),
                'last_name' => trim((string) $in['last_name']),
                'marketing_opt_in' => !empty($in['marketing_opt_in']) ? 1 : 0,
            ]);
        } catch (\PDOException $e) {
            if (Database::isDuplicateKey($e)) {      // concurrent registration with the same email or phone
                throw str_contains($e->getMessage(), 'uq_users_phone')
                    ? new ValidationException(['phone' => 'An account with this phone number already exists.'])
                    : new ValidationException(['email' => 'An account with this email already exists.']);
            }
            throw $e;
        }
        $user = (array) $this->db->one('SELECT * FROM users WHERE id = ?', [$id]);
        try {
            (new NotificationService())->sendTemplate('account_welcome',
                ['first_name' => (string) $user['first_name'], 'login_url' => rtrim((string) config('app.url'), '/') . '/login'] + MessageTemplates::contactVars(),
                ['email' => (string) $user['email'], 'prefs' => $user], ['user_id' => (int) $id]);
        } catch (\Throwable $e) {
            \Waimart\Core\Logger::error('Welcome email failed', ['user_id' => $id, 'error' => $e->getMessage()]);
        }
        return $user;
    }

    /** Whether customers may create accounts (Admin → Settings → Checkout & accounts). */
    public static function registrationOpen(): bool
    {
        return Settings::bool('allow_registration', true);
    }

    /** @param array<string,mixed> $in */
    public function updateProfile(int $userId, array $in): void
    {
        Validator::validate($in, [
            'first_name' => 'required|max:80',
            'last_name' => 'required|max:80',
            'email' => 'required|email',
            'phone' => 'required|phone',
        ]);
        $user = $this->user($userId);
        $email = strtolower(trim((string) $in['email']));
        $phone = Validator::normalizePhone((string) $in['phone']);
        if ($email !== $user['email'] || $phone !== $user['phone']) {
            // Email and phone both sign the customer in: changing either requires the current password.
            if (!password_verify((string) ($in['current_password'] ?? ''), $user['password_hash'])) {
                throw new ValidationException(['current_password' => 'Enter your current password to change your email or phone number.']);
            }
            if ($email !== $user['email'] && $this->db->value('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $userId])) {
                throw new ValidationException(['email' => 'That email is used by another account.']);
            }
            if ($phone !== $user['phone'] && $this->db->value('SELECT id FROM users WHERE phone = ? AND id <> ?', [$phone, $userId])) {
                throw new ValidationException(['phone' => 'That phone number is used by another account.']);
            }
        }
        $this->db->exec(
            'UPDATE users SET first_name = ?, last_name = ?, email = ?, phone = ? WHERE id = ?',
            [trim((string) $in['first_name']), trim((string) $in['last_name']), $email, $phone, $userId]
        );
        Auth::reset();
    }

    /** @param array<string,mixed> $in */
    public function changePassword(int $userId, array $in): void
    {
        Validator::validate($in, [
            'current_password' => 'required',
            'password' => 'required|password',
            'password_confirmation' => 'required|same:password',
        ], ['password' => 'New password', 'password_confirmation' => 'Password confirmation']);
        $user = $this->user($userId);
        if (!password_verify((string) $in['current_password'], $user['password_hash'])) {
            throw new ValidationException(['current_password' => 'Your current password is incorrect.']);
        }
        if (password_verify((string) $in['password'], $user['password_hash'])) {
            throw new ValidationException(['password' => 'Choose a new password, different from your current one.']);
        }
        $this->setPassword($userId, (string) $in['password']);
        Auth::touchAuthTime();      // keep this session; other sessions are signed out
        AuthEvents::record('password_changed', $userId);
    }

    /** @param array<string,mixed> $in */
    public function updateNotifications(int $userId, array $in): void
    {
        $this->db->exec(
            'UPDATE users SET notify_email = ?, notify_sms = ?, marketing_opt_in = ? WHERE id = ?',
            [!empty($in['notify_email']) ? 1 : 0, !empty($in['notify_sms']) ? 1 : 0, !empty($in['marketing_opt_in']) ? 1 : 0, $userId]
        );
        Auth::reset();
    }

    // ------------------------------------------------------------------ password reset

    /**
     * Starts a password reset for the account with this email or phone number: an email with a reset link, or an SMS
     * with a 6-digit code. Behaves the same whether or not the account exists (no account enumeration).
     * @return int|null the account it was sent for (kept server-side for the code step; never shown), or null
     */
    public function requestReset(string $login, string $channel, string $ip): ?int
    {
        $login = trim($login);
        $byEmail = str_contains($login, '@');
        $phone = $byEmail ? null : Validator::normalizePhone($login);
        if ($byEmail ? Validator::check(['email' => $login], ['email' => 'required|email']) : $phone === null) {
            throw new ValidationException(['login' => 'Enter the email address or phone number on your account.']);
        }
        if (!in_array($channel, ['email', 'sms'], true)) {
            throw new ValidationException(['channel' => 'Choose how to receive your reset: email or SMS.']);
        }
        $rl = new RateLimiter($this->db);
        $key = $byEmail ? strtolower($login) : (string) $phone;
        if (!$rl->attempt('reset-ip|' . $ip, 10, 3600) || !$rl->attempt('reset|' . $key, 3, 3600)) {
            return null;     // silently dropped; the response is identical
        }
        $user = $byEmail
            ? $this->db->one("SELECT id, first_name, email, phone FROM users WHERE email = ? AND status = 'active'", [strtolower($login)])
            : $this->db->one("SELECT id, first_name, email, phone FROM users WHERE phone = ? AND status = 'active'", [$phone]);
        if ($user === null) {
            return null;
        }
        if ($channel === 'email') {
            $this->sendResetLink($user, $ip);
            return (int) $user['id'];
        }
        if ((string) $user['phone'] === '') {
            return null;
        }
        $code = (string) random_int(100000, 999999);
        $this->db->transaction(function () use ($user, $code, $ip) {
            $this->db->exec('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [$user['id']]);
            $this->db->insert('password_resets', [
                'user_id' => $user['id'], 'channel' => 'sms',
                'token_hash' => Crypto::hash(Crypto::token(32)),          // replaced by a usable link token once the code is right
                'code_hash' => self::codeHash((int) $user['id'], $code),
                'expires_at' => date('Y-m-d H:i:s', time() + self::RESET_CODE_MINUTES * 60),
                'ip_address' => $ip,
            ]);
        });
        (new NotificationService())->sendTemplate('password_reset_code',
            ['first_name' => (string) $user['first_name'], 'code' => $code, 'minutes' => (string) self::RESET_CODE_MINUTES] + MessageTemplates::contactVars(),
            ['phone' => (string) $user['phone']], ['user_id' => (int) $user['id'], 'secrets' => [$code]]);
        AuthEvents::record('password_reset_code_sent', (int) $user['id']);
        return (int) $user['id'];
    }

    /**
     * Checks an SMS reset code. The right code returns a one-time token for the "set a new password" page; a wrong
     * one counts against the code (RESET_CODE_ATTEMPTS), and every failure gives the same message.
     */
    public function verifyResetCode(?int $userId, string $code, string $ip): string
    {
        $code = preg_replace('/\D+/', '', $code) ?? '';
        $fail = fn () => new ValidationException(['code' => 'That code is not right, or it has expired. Check the SMS, or ask for a new code.']);
        if (!(new RateLimiter($this->db))->attempt('reset-code-ip|' . $ip, 20, 900)) {
            throw ValidationException::withMessage('Too many attempts. Please wait 15 minutes and try again.');
        }
        if ($userId === null || strlen($code) !== 6) {
            throw $fail();
        }
        // The transaction returns the token, or null for a wrong code; the wrong try is counted after it commits
        // (throwing inside would roll the count back and allow unlimited guesses).
        $wrongId = null;
        $token = $this->db->transaction(function () use ($userId, $code, $fail, &$wrongId) {
            $row = $this->db->one(
                "SELECT * FROM password_resets WHERE user_id = ? AND channel = 'sms' AND used_at IS NULL AND code_hash IS NOT NULL ORDER BY id DESC LIMIT 1 FOR UPDATE",
                [$userId]
            );
            if ($row === null || strtotime((string) $row['expires_at']) <= time() || (int) $row['code_attempts'] >= self::RESET_CODE_ATTEMPTS) {
                return null;
            }
            if (!hash_equals((string) $row['code_hash'], self::codeHash($userId, $code))) {
                $this->db->exec('UPDATE password_resets SET code_attempts = code_attempts + 1 WHERE id = ?', [$row['id']]);
                $wrongId = (int) $row['id'];
                return null;
            }
            $token = Crypto::token(32);
            $this->db->exec('UPDATE password_resets SET token_hash = ?, code_hash = NULL, expires_at = ? WHERE id = ?',
                [Crypto::hash($token), date('Y-m-d H:i:s', time() + self::RESET_CODE_MINUTES * 60), $row['id']]);
            return $token;
        });
        if ($token === null) {
            if ($wrongId !== null) {
                AuthEvents::record('password_reset_code_failed', $userId);
            }
            throw $fail();
        }
        return $token;
    }

    private static function codeHash(int $userId, string $code): string
    {
        return Crypto::hash('reset-code|' . $userId . '|' . $code);
    }

    /** Email reset link (kept for callers that only have an email address). */
    public function requestPasswordReset(string $email, string $ip): void
    {
        $email = strtolower(trim($email));
        if (Validator::check(['email' => $email], ['email' => 'required|email'])) {
            throw new ValidationException(['email' => 'Enter a valid email address.']);
        }
        $rl = new RateLimiter($this->db);
        if (!$rl->attempt('reset-ip|' . $ip, 10, 3600) || !$rl->attempt('reset|' . $email, 3, 3600)) {
            return;     // silently drop; the response is identical
        }
        $user = $this->db->one("SELECT id, first_name, email FROM users WHERE email = ? AND status = 'active'", [$email]);
        if ($user === null) {
            return;
        }
        $this->sendResetLink($user, $ip);
    }

    /** @param array<string,mixed> $user id, first_name, email */
    private function sendResetLink(array $user, string $ip): void
    {
        $token = Crypto::token(32);
        $this->db->transaction(function () use ($user, $token, $ip) {
            // Only the newest link works.
            $this->db->exec('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [$user['id']]);
            $this->db->insert('password_resets', [
                'user_id' => $user['id'],
                'token_hash' => Crypto::hash($token),
                'expires_at' => date('Y-m-d H:i:s', time() + self::RESET_TTL_MINUTES * 60),
                'ip_address' => $ip,
            ]);
        });
        $link = rtrim((string) config('app.url'), '/') . '/reset-password?token=' . $token;
        (new NotificationService())->sendTemplate('password_reset',
            ['first_name' => (string) $user['first_name'], 'reset_link' => $link, 'minutes' => (string) self::RESET_TTL_MINUTES] + MessageTemplates::contactVars(),
            ['email' => (string) $user['email']], ['user_id' => (int) $user['id'], 'secrets' => [$token]]);
    }

    /** True when the token is currently usable (for showing the form). */
    public function resetTokenValid(string $token): bool
    {
        return $token !== '' && (bool) $this->db->value(
            'SELECT id FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND code_hash IS NULL AND expires_at > NOW()',
            [Crypto::hash($token)]
        );
    }

    /** @param array<string,mixed> $in @return int the account whose password was changed */
    public function resetPassword(string $token, array $in): int
    {
        Validator::validate($in, [
            'password' => 'required|password',
            'password_confirmation' => 'required|same:password',
        ], ['password' => 'New password', 'password_confirmation' => 'Password confirmation']);

        return $this->db->transaction(function () use ($token, $in) {
            $row = $token === '' ? null : $this->db->one(
                'SELECT * FROM password_resets WHERE token_hash = ? FOR UPDATE',
                [Crypto::hash($token)]
            );
            if ($row === null || $row['used_at'] !== null || $row['code_hash'] !== null || strtotime((string) $row['expires_at']) <= time()) {
                throw ValidationException::withMessage('This reset link is invalid or has expired. Please request a new one.');
            }
            $this->db->exec('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [$row['user_id']]);
            $this->setPassword((int) $row['user_id'], (string) $in['password']);
            AuthEvents::record('password_reset', (int) $row['user_id']);
            (new RateLimiter($this->db))->clear('login|' . $this->db->value('SELECT email FROM users WHERE id = ?', [$row['user_id']]));
            return (int) $row['user_id'];
        });
    }

    private function setPassword(int $userId, string $password): void
    {
        $this->db->exec(
            'UPDATE users SET password_hash = ?, password_changed_at = NOW(), must_change_password = 0 WHERE id = ?',
            [password_hash($password, PASSWORD_DEFAULT), $userId]
        );
    }

    // ------------------------------------------------------------------ addresses

    /** @return list<array<string,mixed>> */
    public function addresses(int $userId): array
    {
        return $this->db->all(
            'SELECT a.*, c.name AS county_name FROM user_addresses a JOIN counties c ON c.id = a.county_id
              WHERE a.user_id = ? ORDER BY a.is_default DESC, a.id DESC',
            [$userId]
        );
    }

    /** Owner-scoped lookup; null when the address belongs to someone else. */
    public function address(int $userId, int $addressId): ?array
    {
        return $this->db->one(
            'SELECT a.*, c.name AS county_name FROM user_addresses a JOIN counties c ON c.id = a.county_id WHERE a.id = ? AND a.user_id = ?',
            [$addressId, $userId]
        );
    }

    /** @param array<string,mixed> $in */
    public function saveAddress(int $userId, ?int $addressId, array $in): int
    {
        $data = self::validateAddress($in, $this->db);
        return $this->db->transaction(function () use ($userId, $addressId, $data, $in) {
            if ($addressId !== null && $this->address($userId, $addressId) === null) {
                throw ValidationException::withMessage('Address not found.');
            }
            if ((int) $this->db->value('SELECT COUNT(*) FROM user_addresses WHERE user_id = ?', [$userId]) >= 10 && $addressId === null) {
                throw ValidationException::withMessage('You can save up to 10 addresses.');
            }
            $isFirst = !(bool) $this->db->value('SELECT COUNT(*) FROM user_addresses WHERE user_id = ?', [$userId]);
            $makeDefault = $isFirst || !empty($in['is_default']);
            if ($addressId === null) {
                $addressId = $this->db->insert('user_addresses', $data + ['user_id' => $userId, 'is_default' => 0]);
            } else {
                $this->db->exec(
                    'UPDATE user_addresses SET label = ?, recipient_name = ?, phone = ?, county_id = ?, town = ?, location = ?, instructions = ?
                      WHERE id = ? AND user_id = ?',
                    [$data['label'], $data['recipient_name'], $data['phone'], $data['county_id'], $data['town'], $data['location'], $data['instructions'], $addressId, $userId]
                );
            }
            if ($makeDefault) {
                $this->setDefaultAddress($userId, $addressId);
            }
            return $addressId;
        });
    }

    public function setDefaultAddress(int $userId, int $addressId): void
    {
        $this->db->transaction(function () use ($userId, $addressId) {
            if ($this->address($userId, $addressId) === null) {
                throw ValidationException::withMessage('Address not found.');
            }
            $this->db->exec('UPDATE user_addresses SET is_default = 0 WHERE user_id = ? AND is_default = 1', [$userId]);
            $this->db->exec('UPDATE user_addresses SET is_default = 1 WHERE id = ? AND user_id = ?', [$addressId, $userId]);
        });
    }

    public function deleteAddress(int $userId, int $addressId): void
    {
        $this->db->transaction(function () use ($userId, $addressId) {
            $addr = $this->address($userId, $addressId) ?? throw ValidationException::withMessage('Address not found.');
            $this->db->exec('DELETE FROM user_addresses WHERE id = ? AND user_id = ?', [$addressId, $userId]);
            if ((int) $addr['is_default'] === 1) {
                $next = $this->db->value('SELECT id FROM user_addresses WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$userId]);
                if ($next) {
                    $this->db->exec('UPDATE user_addresses SET is_default = 1 WHERE id = ?', [$next]);
                }
            }
        });
    }

    /**
     * Shared delivery-address validation (also used by checkout).
     * @param array<string,mixed> $in
     * @return array{label:?string,recipient_name:string,phone:string,county_id:int,town:string,location:string,instructions:?string}
     */
    public static function validateAddress(array $in, Database $db): array
    {
        Validator::validate($in, [
            'label' => 'nullable|max:40',
            'recipient_name' => 'required|max:160',
            'phone' => 'required|phone',
            'county_id' => 'required|int',
            'town' => 'required|max:100',
            'location' => 'required|min:3|max:255',
            'instructions' => 'nullable|max:500',
        ], ['recipient_name' => 'Recipient name', 'county_id' => 'County', 'location' => 'Estate, street or building']);
        if (!$db->value('SELECT id FROM counties WHERE id = ?', [(int) $in['county_id']])) {
            throw new ValidationException(['county_id' => 'Select your county.']);
        }
        return [
            'label' => trim((string) ($in['label'] ?? '')) ?: null,
            'recipient_name' => trim((string) $in['recipient_name']),
            'phone' => (string) Validator::normalizePhone((string) $in['phone']),
            'county_id' => (int) $in['county_id'],
            'town' => trim(preg_replace('/\s+/', ' ', (string) $in['town']) ?? ''),
            'location' => trim((string) $in['location']),
            'instructions' => trim((string) ($in['instructions'] ?? '')) ?: null,
        ];
    }

    /** @return array<string,mixed> */
    private function user(int $id): array
    {
        return $this->db->one('SELECT * FROM users WHERE id = ?', [$id]) ?? throw ValidationException::withMessage('Account not found.');
    }
}
