<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Crypto;
use Waimart\Core\Database;
use Waimart\Core\RateLimiter;
use Waimart\Core\Settings;
use Waimart\Core\ValidationException;

/**
 * Two-step sign-in with an authenticator app (TOTP, RFC 6238: SHA-1, 6 digits, 30-second steps, ±1 step for clock
 * drift). Optional for every account unless Settings → Security requires it for staff.
 *
 * - The secret is stored encrypted with the app key, and only after the person proves their app works.
 * - A code is accepted once: the last accepted step is stored, so a code seen over someone's shoulder cannot be replayed.
 * - Ten single-use recovery codes are shown once and stored hashed.
 * - Turning it off needs the account password; an administrator can reset it for someone who lost their phone.
 */
final class MfaService
{
    private const DIGITS = 6;
    private const STEP = 30;
    private const RECOVERY_CODES = 10;
    private const MAX_ATTEMPTS = 5;          // wrong codes per 15 minutes, per account
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? App::db();
    }

    // ------------------------------------------------------------------ TOTP

    public static function newSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function code(string $secret, int $step): string
    {
        $key = self::base32Decode($secret);
        $hash = hash_hmac('sha1', pack('N2', 0, $step), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $bin = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);
        return str_pad((string) ($bin % 10 ** self::DIGITS), self::DIGITS, '0', STR_PAD_LEFT);
    }

    public static function currentStep(?int $time = null): int
    {
        return intdiv($time ?? time(), self::STEP);
    }

    /** The step the code belongs to (now ±1), if it is later than $lastStep; null when wrong or already used. */
    public static function matchStep(string $secret, string $code, ?int $lastStep, ?int $time = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (!preg_match('/^\d{6}$/', $code)) {
            return null;
        }
        $now = self::currentStep($time);
        foreach ([$now, $now - 1, $now + 1] as $step) {
            if (($lastStep === null || $step > $lastStep) && hash_equals(self::code($secret, $step), $code)) {
                return $step;
            }
        }
        return null;
    }

    /** For "add account" links in authenticator apps (and a QR code, if an app scans one). */
    public static function otpauthUri(string $secret, string $account): string
    {
        $issuer = brand_name();
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account) . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::STEP;
    }

    // ------------------------------------------------------------------ account

    /** @param array<string,mixed> $user */
    public static function enabled(array $user): bool
    {
        return !empty($user['mfa_enabled_at']) && !empty($user['mfa_secret']);
    }

    /** Settings → Security: staff must use two-step sign-in. */
    public static function requiredForStaff(): bool
    {
        return Settings::bool('staff_mfa_required', false);
    }

    /**
     * Turns two-step sign-in on once the person enters a code from their app for the secret they were shown.
     * @return list<string> the recovery codes, to show once
     */
    public function enable(int $userId, string $secret, string $code, string $password): array
    {
        $u = $this->user($userId);
        if (self::enabled($u)) {
            throw ValidationException::withMessage('Two-step sign-in is already on.');
        }
        if (!password_verify($password, (string) $u['password_hash'])) {
            throw new ValidationException(['password' => 'Your password is incorrect.']);
        }
        if (!preg_match('/^[A-Z2-7]{32}$/', $secret)) {
            throw ValidationException::withMessage('The setup expired. Start again.');
        }
        $step = self::matchStep($secret, $code, null);
        if ($step === null) {
            throw new ValidationException(['code' => 'That code is not right. Check the time on your phone is automatic, and enter the newest code.']);
        }
        $codes = $this->db->transaction(function () use ($userId, $secret, $step) {
            $this->db->exec('UPDATE users SET mfa_secret = ?, mfa_enabled_at = NOW(), mfa_last_step = ? WHERE id = ?', [Crypto::encrypt($secret), $step, $userId]);
            return $this->replaceRecoveryCodes($userId);
        });
        AuditLogger::log('auth.mfa_enabled', 'user', $userId, null, null, $userId);
        AuthEvents::record('mfa_enabled', $userId);
        return $codes;
    }

    public function disable(int $userId, string $password): void
    {
        $u = $this->user($userId);
        if (!password_verify($password, (string) $u['password_hash'])) {
            throw new ValidationException(['password' => 'Your password is incorrect.']);
        }
        if ((int) $u['is_staff'] === 1 && self::requiredForStaff()) {
            throw ValidationException::withMessage('Two-step sign-in is required for staff accounts (Settings → Security).');
        }
        $this->clear($userId);
        AuditLogger::log('auth.mfa_disabled', 'user', $userId, null, null, $userId);
        AuthEvents::record('mfa_disabled', $userId);
    }

    /** An administrator resets it for someone who lost their phone (users.manage; never on yourself). */
    public function adminReset(int $userId, int $actorId): void
    {
        if ($userId === $actorId) {
            throw ValidationException::withMessage('Use your own account page to change your two-step sign-in.');
        }
        $this->user($userId);
        $this->clear($userId);
        // Signed out everywhere: whoever holds a session made with the old second factor must sign in again.
        $this->db->exec('UPDATE users SET sessions_revoked_at = NOW() WHERE id = ?', [$userId]);
        AuditLogger::log('staff.mfa_reset', 'user', $userId, null, null, $actorId);
        AuthEvents::record('mfa_reset', $userId);
    }

    /** @return list<string> new recovery codes (the old ones stop working) */
    public function regenerateRecoveryCodes(int $userId, string $password): array
    {
        $u = $this->user($userId);
        if (!self::enabled($u)) {
            throw ValidationException::withMessage('Turn on two-step sign-in first.');
        }
        if (!password_verify($password, (string) $u['password_hash'])) {
            throw new ValidationException(['password' => 'Your password is incorrect.']);
        }
        $codes = $this->db->transaction(fn () => $this->replaceRecoveryCodes($userId));
        AuditLogger::log('auth.mfa_recovery_regenerated', 'user', $userId, null, null, $userId);
        return $codes;
    }

    public function unusedRecoveryCodes(int $userId): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM mfa_recovery_codes WHERE user_id = ? AND used_at IS NULL', [$userId]);
    }

    /**
     * The second step of signing in: an authenticator code or a recovery code.
     * @return 'totp'|'recovery'
     */
    public function verify(int $userId, string $input): string
    {
        $limiter = new RateLimiter($this->db);
        $key = 'mfa|' . $userId;
        if ($limiter->tooMany($key, self::MAX_ATTEMPTS, 900)) {
            throw ValidationException::withMessage('Too many wrong codes. Please wait 15 minutes and sign in again.');
        }
        $result = $this->db->transaction(function () use ($userId, $input) {
            $u = (array) $this->db->one('SELECT * FROM users WHERE id = ? FOR UPDATE', [$userId]);
            $secret = self::enabled($u) ? Crypto::decrypt((string) $u['mfa_secret']) : null;
            if ($secret === null) {
                return null;
            }
            $step = self::matchStep($secret, $input, $u['mfa_last_step'] !== null ? (int) $u['mfa_last_step'] : null);
            if ($step !== null) {
                $this->db->exec('UPDATE users SET mfa_last_step = ? WHERE id = ?', [$step, $userId]);
                return 'totp';
            }
            $norm = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $input) ?? '');
            if (strlen($norm) === 10) {
                $used = $this->db->exec('UPDATE mfa_recovery_codes SET used_at = NOW() WHERE user_id = ? AND code_hash = ? AND used_at IS NULL',
                    [$userId, Crypto::hash($norm)]);
                if ($used === 1) {
                    return 'recovery';
                }
            }
            return null;
        });
        if ($result === null) {
            $limiter->attempt($key, self::MAX_ATTEMPTS, 900);
            AuthEvents::record('mfa_failed', $userId);
            throw new ValidationException(['code' => 'That code is not right. Enter the newest 6-digit code from your app, or a recovery code.']);
        }
        $limiter->clear($key);
        return $result;
    }

    // ------------------------------------------------------------------ internals

    /** @return list<string> */
    private function replaceRecoveryCodes(int $userId): array
    {
        $this->db->exec('DELETE FROM mfa_recovery_codes WHERE user_id = ?', [$userId]);
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $c = Crypto::code(10);
            $this->db->insert('mfa_recovery_codes', ['user_id' => $userId, 'code_hash' => Crypto::hash($c)]);
            $codes[] = substr($c, 0, 5) . '-' . substr($c, 5);
        }
        return $codes;
    }

    private function clear(int $userId): void
    {
        $this->db->transaction(function () use ($userId) {
            $this->db->exec('UPDATE users SET mfa_secret = NULL, mfa_enabled_at = NULL, mfa_last_step = NULL WHERE id = ?', [$userId]);
            $this->db->exec('DELETE FROM mfa_recovery_codes WHERE user_id = ?', [$userId]);
        });
    }

    /** @return array<string,mixed> */
    private function user(int $userId): array
    {
        return $this->db->one('SELECT * FROM users WHERE id = ?', [$userId]) ?? throw ValidationException::withMessage('Account not found.');
    }

    private static function base32Encode(string $bin): string
    {
        $bits = '';
        foreach (str_split($bin) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    private static function base32Decode(string $b32): string
    {
        $bits = '';
        foreach (str_split(strtoupper(rtrim($b32, '='))) as $c) {
            $pos = strpos(self::ALPHABET, $c);
            if ($pos === false) {
                continue;
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }
}
