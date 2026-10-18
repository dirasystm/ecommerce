<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Auth;
use Waimart\Core\Crypto;
use Waimart\Core\Database;
use Waimart\Core\RateLimiter;
use Waimart\Core\ValidationException;
use Waimart\Core\Validator;

/**
 * Customer self-service: registration, profile, password, password reset, notification
 * preferences and saved addresses. Every address operation is scoped to the owner (D2).
 */
final class AccountService
{
    public const RESET_TTL_MINUTES = 60;
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? App::db();
    }

    /** @param array<string,mixed> $in @return array<string,mixed> the new user (not logged in) */
    public function register(array $in, string $ip): array
    {
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
        if ($this->db->value('SELECT id FROM users WHERE email = ?', [$email])) {
            throw new ValidationException(['email' => 'An account with this email already exists. Sign in or reset your password.']);
        }
        try {
            $id = $this->db->insert('users', [
                'email' => $email,
                'phone' => Validator::normalizePhone((string) $in['phone']),
                'password_hash' => password_hash((string) $in['password'], PASSWORD_DEFAULT),
                'first_name' => trim((string) $in['first_name']),
                'last_name' => trim((string) $in['last_name']),
                'marketing_opt_in' => !empty($in['marketing_opt_in']) ? 1 : 0,
            ]);
        } catch (\PDOException $e) {
            if (Database::isDuplicateKey($e)) {      // concurrent registration with same email
                throw new ValidationException(['email' => 'An account with this email already exists.']);
            }
            throw $e;
        }
        return (array) $this->db->one('SELECT * FROM users WHERE id = ?', [$id]);
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
        if ($email !== $user['email']) {
            // Changing the sign-in email requires the current password.
            if (!password_verify((string) ($in['current_password'] ?? ''), $user['password_hash'])) {
                throw new ValidationException(['current_password' => 'Enter your current password to change your email.']);
            }
            if ($this->db->value('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $userId])) {
                throw new ValidationException(['email' => 'That email is used by another account.']);
            }
        }
        $this->db->exec(
            'UPDATE users SET first_name = ?, last_name = ?, email = ?, phone = ? WHERE id = ?',
            [trim((string) $in['first_name']), trim((string) $in['last_name']), $email, Validator::normalizePhone((string) $in['phone']), $userId]
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
        $this->setPassword($userId, (string) $in['password']);
        Auth::touchAuthTime();      // keep this session; other sessions are signed out
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
     * Always behaves the same whether or not the email exists (no account enumeration).
     */
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
        (new NotificationService())->email(
            $user['email'],
            'Reset your Waimart password',
            "Hello {$user['first_name']},\n\nWe received a request to reset your Waimart password.\n"
            . "Use this link within " . self::RESET_TTL_MINUTES . " minutes:\n\n$link\n\n"
            . "If you did not ask for this, you can ignore this email. Your password will not change.\n\nWaimart",
            'password_reset',
            ['user_id' => (int) $user['id'], 'secrets' => [$token]]
        );
    }

    /** True when the token is currently usable (for showing the form). */
    public function resetTokenValid(string $token): bool
    {
        return $token !== '' && (bool) $this->db->value(
            'SELECT id FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()',
            [Crypto::hash($token)]
        );
    }

    /** @param array<string,mixed> $in */
    public function resetPassword(string $token, array $in): void
    {
        Validator::validate($in, [
            'password' => 'required|password',
            'password_confirmation' => 'required|same:password',
        ], ['password' => 'New password', 'password_confirmation' => 'Password confirmation']);

        $this->db->transaction(function () use ($token, $in) {
            $row = $token === '' ? null : $this->db->one(
                'SELECT * FROM password_resets WHERE token_hash = ? FOR UPDATE',
                [Crypto::hash($token)]
            );
            if ($row === null || $row['used_at'] !== null || strtotime((string) $row['expires_at']) <= time()) {
                throw ValidationException::withMessage('This reset link is invalid or has expired. Please request a new one.');
            }
            $this->db->exec('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [$row['user_id']]);
            $this->setPassword((int) $row['user_id'], (string) $in['password']);
            (new RateLimiter($this->db))->clear('login|' . $this->db->value('SELECT email FROM users WHERE id = ?', [$row['user_id']]));
        });
    }

    private function setPassword(int $userId, string $password): void
    {
        $this->db->exec(
            'UPDATE users SET password_hash = ?, password_changed_at = NOW() WHERE id = ?',
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
