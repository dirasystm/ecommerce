<?php

declare(strict_types=1);

namespace Waimart\Services\Marketing;

use Waimart\Core\App;
use Waimart\Core\Crypto;
use Waimart\Core\Database;
use Waimart\Services\AuditLogger;

/**
 * Marketing and personalisation consent, per channel, with a full history (who, when, where, which wording).
 * Transactional messages (orders, payments, codes) never depend on these choices; marketing always does.
 * Unsubscribe links are signed with the app key, so they work in one click without signing in and cannot be forged.
 */
final class Consent
{
    public const CHANNELS = ['email' => 'marketing_email', 'sms' => 'marketing_sms', 'personalisation' => 'personalisation'];
    /** The wording customers agree to; bump the version when the wording changes. */
    public const WORDING_VERSION = 'v1';
    public const WORDING = [
        'email' => 'Send me offers, new arrivals and suggestions by email. I can unsubscribe at any time.',
        'sms' => 'Send me occasional offers by SMS. I can opt out at any time.',
        'personalisation' => 'Use my shopping activity on this site to personalise product suggestions.',
    ];

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? App::db();
    }

    /** @return array{email:bool,sms:bool,personalisation:bool,frequency:string} */
    public function forUser(int $userId): array
    {
        $u = $this->db->one('SELECT marketing_email, marketing_sms, personalisation, marketing_frequency FROM users WHERE id = ?', [$userId]) ?? [];
        return ['email' => (int) ($u['marketing_email'] ?? 0) === 1, 'sms' => (int) ($u['marketing_sms'] ?? 0) === 1,
                'personalisation' => (int) ($u['personalisation'] ?? 1) === 1, 'frequency' => (string) ($u['marketing_frequency'] ?? 'normal')];
    }

    /**
     * Records the customer's choices; only changes are written to the history.
     * @param array<string,bool> $choices channel => granted
     */
    public function setForUser(int $userId, array $choices, string $source, ?string $ip = null, ?string $frequency = null): void
    {
        $current = $this->forUser($userId);
        $this->db->transaction(function () use ($userId, $choices, $source, $ip, $frequency, $current) {
            foreach (self::CHANNELS as $channel => $column) {
                if (!array_key_exists($channel, $choices) || (bool) $choices[$channel] === $current[$channel]) {
                    continue;
                }
                $granted = (bool) $choices[$channel];
                $this->db->exec("UPDATE users SET $column = ? WHERE id = ?", [$granted ? 1 : 0, $userId]);
                if ($channel === 'email') {
                    $this->db->exec('UPDATE users SET marketing_opt_in = ? WHERE id = ?', [$granted ? 1 : 0, $userId]);
                }
                $this->db->insert('marketing_consents', ['user_id' => $userId, 'channel' => $channel, 'granted' => $granted ? 1 : 0,
                    'source' => $source, 'wording_version' => self::WORDING_VERSION, 'ip_address' => $ip]);
                if (!$granted && $channel !== 'personalisation') {
                    Tracker::record('unsubscribed', ['user_id' => $userId, 'query' => $channel]);
                }
            }
            if ($frequency !== null && in_array($frequency, ['normal', 'less'], true) && $frequency !== $current['frequency']) {
                $this->db->exec('UPDATE users SET marketing_frequency = ? WHERE id = ?', [$frequency, $userId]);
            }
            $this->db->exec("UPDATE marketing_leads SET consent_email = (SELECT marketing_email FROM users WHERE id = ?), consent_sms = (SELECT marketing_sms FROM users WHERE id = ?) WHERE user_id = ?",
                [$userId, $userId, $userId]);
        });
    }

    /** Consent history of a customer, newest first. @return list<array<string,mixed>> */
    public function history(int $userId, int $limit = 50): array
    {
        return $this->db->all('SELECT channel, granted, source, wording_version, created_at FROM marketing_consents WHERE user_id = ? ORDER BY id DESC LIMIT ' . max(1, $limit), [$userId]);
    }

    /** One-click unsubscribe link for a customer (u) or newsletter lead (l). */
    public static function unsubscribeUrl(string $kind, int $id, string $channel): string
    {
        return rtrim((string) config('app.url'), '/') . '/unsubscribe/' . $kind . $id . '/' . $channel . '/' . self::signature($kind, $id, $channel);
    }

    public static function signature(string $kind, int $id, string $channel): string
    {
        return substr(Crypto::hash('unsubscribe|' . $kind . '|' . $id . '|' . $channel . '|' . (string) config('app.key')), 0, 20);
    }

    /** Applies a signed unsubscribe link. Returns false when the link is not valid. */
    public function unsubscribe(string $kind, int $id, string $channel, string $sig, ?string $ip = null): bool
    {
        if (!in_array($kind, ['u', 'l'], true) || !in_array($channel, ['email', 'sms'], true) || !hash_equals(self::signature($kind, $id, $channel), $sig)) {
            return false;
        }
        if ($kind === 'u') {
            if (!$this->db->value('SELECT id FROM users WHERE id = ?', [$id])) {
                return false;
            }
            $this->setForUser($id, [$channel => false], 'unsubscribe_link', $ip);
        } else {
            if (!$this->db->value('SELECT id FROM marketing_leads WHERE id = ?', [$id])) {
                return false;
            }
            $this->db->exec('UPDATE marketing_leads SET consent_' . $channel . ' = 0 WHERE id = ?', [$id]);
            $this->db->insert('marketing_consents', ['lead_id' => $id, 'channel' => $channel, 'granted' => 0, 'source' => 'unsubscribe_link',
                'wording_version' => self::WORDING_VERSION, 'ip_address' => $ip]);
        }
        // Anything already queued for this person on this channel is cancelled straight away.
        $this->db->exec("UPDATE marketing_messages SET status = 'cancelled', reason = 'Unsubscribed' WHERE status = 'queued' AND channel = ? AND " . ($kind === 'u' ? 'user_id' : 'lead_id') . ' = ?',
            [$channel, $id]);
        AuditLogger::log('marketing.unsubscribed', $kind === 'u' ? 'user' : 'lead', $id, null, ['channel' => $channel], null);
        return true;
    }

    /** Guest choice about personalisation (cookie set by the banner): true, false or null when not asked yet. */
    public static function guestChoice(): ?bool
    {
        $v = $_COOKIE['bm_pc'] ?? null;
        return $v === '1' ? true : ($v === '0' ? false : null);
    }
}
