<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Logger;

/**
 * Sign-in activity (`auth_events`, append-only): successful and failed sign-ins, two-step checks, sign-outs and
 * account security changes, for every account. What was typed is stored masked; passwords and codes never are.
 * Recording never breaks the sign-in it describes.
 */
final class AuthEvents
{
    public const LABELS = [
        'login_succeeded' => 'Signed in', 'login_failed' => 'Sign-in failed', 'login_locked' => 'Sign-in blocked (too many attempts)',
        'mfa_challenged' => 'Password correct, two-step code requested', 'mfa_succeeded' => 'Two-step code accepted', 'mfa_failed' => 'Two-step code wrong',
        'recovery_code_used' => 'Signed in with a recovery code', 'logout' => 'Signed out', 'password_changed' => 'Password changed',
        'password_reset' => 'Password reset by email link', 'mfa_enabled' => 'Two-step sign-in turned on', 'mfa_disabled' => 'Two-step sign-in turned off',
        'mfa_reset' => 'Two-step sign-in reset by an administrator', 'sessions_revoked' => 'Signed out everywhere',
    ];

    public static function record(string $event, ?int $userId, ?string $identifier = null, ?bool $isStaff = null): void
    {
        try {
            $db = App::db();
            if ($isStaff === null) {
                $isStaff = $userId !== null && (int) $db->value('SELECT is_staff FROM users WHERE id = ?', [$userId]) === 1;
            }
            $db->insert('auth_events', [
                'user_id' => $userId, 'event' => $event, 'identifier' => $identifier !== null ? self::mask($identifier) : null, 'is_staff' => $isStaff ? 1 : 0,
                'ip_address' => mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
                'user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null,
            ]);
        } catch (\Throwable $e) {
            Logger::error('Auth event not recorded', ['event' => $event, 'error' => $e->getMessage()]);
        }
    }

    /** jane.doe@example.com → ja•••@example.com; 254712345678 → 0712•••678; anything else → first 2 characters. */
    public static function mask(string $identifier): string
    {
        $v = mb_substr(trim($identifier), 0, 190);
        if (str_contains($v, '@')) {
            [$local, $domain] = explode('@', $v, 2);
            return mb_substr($local, 0, 2) . '•••@' . mb_substr($domain, 0, 60);
        }
        if (preg_match('/^254(\d{3})\d{3}(\d{3})$/', $v, $m)) {
            return '0' . $m[1] . '•••' . $m[2];
        }
        return mb_substr($v, 0, 2) . '•••';
    }

    /** @return list<array<string,mixed>> */
    public static function recent(?int $userId = null, bool $staffOnly = false, int $limit = 50, ?string $event = null): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($userId !== null) {
            $where[] = 'e.user_id = ?';
            $params[] = $userId;
        }
        if ($staffOnly) {
            $where[] = 'e.is_staff = 1';
        }
        if ($event !== null && isset(self::LABELS[$event])) {
            $where[] = 'e.event = ?';
            $params[] = $event;
        }
        return App::db()->all(
            "SELECT e.*, CONCAT(u.first_name, ' ', u.last_name) AS who FROM auth_events e LEFT JOIN users u ON u.id = e.user_id
              WHERE " . implode(' AND ', $where) . ' ORDER BY e.id DESC LIMIT ' . max(1, min(500, $limit)),
            $params
        );
    }
}
