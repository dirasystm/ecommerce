<?php

declare(strict_types=1);

namespace Waimart\Services\Marketing;

use Waimart\Core\App;
use Waimart\Core\Auth;
use Waimart\Core\Logger;
use Waimart\Core\Settings;

/**
 * Shopping behaviour, recorded only when allowed (Marketing → Settings → Record shopping activity; signed-in customers'
 * own choice; guests' banner choice) and never with contact details, tokens or payment data.
 *
 * Guests get a random first-party visitor id (no fingerprinting). On sign-in their recent guest activity from the same
 * browser joins their account, if they allow personalisation. A failure here never affects the shop: errors are
 * logged and swallowed.
 */
final class Tracker
{
    public const COOKIE = 'bm_vid';
    public const EVENTS = [
        'product_viewed', 'category_viewed', 'brand_viewed', 'store_viewed', 'searched', 'search_click', 'rec_click', 'rec_dismissed',
        'cart_added', 'cart_removed', 'wishlist_added', 'wishlist_removed', 'checkout_started', 'order_placed', 'order_confirmed',
        'order_delivered', 'order_cancelled', 'order_returned', 'review_submitted', 'registered', 'lead_captured', 'link_clicked',
        'unsubscribed', 'rec_order',
    ];
    /** Viewing events counted once per visitor and subject within this many minutes. */
    private const DEDUPE_MINUTES = 30;
    /** Events that are part of the customer's own history (subject to their choice); the rest are business records. */
    private const BEHAVIOUR = ['product_viewed', 'category_viewed', 'brand_viewed', 'store_viewed', 'searched', 'search_click', 'rec_click',
        'rec_dismissed', 'cart_added', 'cart_removed', 'wishlist_added', 'wishlist_removed', 'checkout_started'];

    private static ?string $visitor = null;

    /**
     * @param array{user_id?:?int,product_id?:?int,seller_id?:?int,category_id?:?int,brand_id?:?int,order_id?:?int,placement?:?string,query?:?string,amount?:mixed,link_id?:?int,first_link_id?:?int} $a
     */
    public static function record(string $event, array $a = []): void
    {
        try {
            if (!in_array($event, self::EVENTS, true) || !Settings::bool('tracking_enabled', true)) {
                return;
            }
            $userId = array_key_exists('user_id', $a) ? $a['user_id'] : Auth::id();
            $isBehaviour = in_array($event, self::BEHAVIOUR, true);
            if ($isBehaviour && !self::allowed($userId !== null ? (int) $userId : null)) {
                return;
            }
            // Staff browsing the shop are not customers.
            if ($userId !== null && $isBehaviour && Auth::id() === (int) $userId && Auth::isStaff()) {
                return;
            }
            $visitor = $isBehaviour || $event === 'link_clicked' || $event === 'registered' ? self::visitorId($isBehaviour) : null;
            $db = App::db();
            if (in_array($event, ['product_viewed', 'category_viewed', 'brand_viewed', 'store_viewed', 'checkout_started', 'searched'], true)) {
                $subject = $a['product_id'] ?? $a['category_id'] ?? $a['brand_id'] ?? $a['seller_id'] ?? null;
                $col = isset($a['product_id']) ? 'product_id' : (isset($a['category_id']) ? 'category_id' : (isset($a['brand_id']) ? 'brand_id' : (isset($a['seller_id']) ? 'seller_id' : null)));
                $who = $userId !== null ? ['user_id = ?', (int) $userId] : ['visitor_id = ?', $visitor];
                if ($who[1] !== null && $db->value('SELECT id FROM shop_events WHERE event = ? AND ' . $who[0]
                        . ($col !== null ? " AND $col = ?" : ($event === 'searched' ? ' AND query = ?' : '')) . ' AND occurred_at > NOW() - INTERVAL ' . self::DEDUPE_MINUTES . ' MINUTE LIMIT 1',
                        array_merge([$event, $who[1]], $col !== null ? [$subject] : ($event === 'searched' ? [mb_substr((string) ($a['query'] ?? ''), 0, 100)] : [])))) {
                    return;
                }
            }
            $att = Attribution::current();
            $db->insert('shop_events', [
                'visitor_id' => $visitor, 'user_id' => $userId,
                'event' => $event,
                'product_id' => $a['product_id'] ?? null, 'seller_id' => $a['seller_id'] ?? null,
                'category_id' => $a['category_id'] ?? null, 'brand_id' => $a['brand_id'] ?? null, 'order_id' => $a['order_id'] ?? null,
                'link_id' => $a['link_id'] ?? $att['last'], 'first_link_id' => $a['first_link_id'] ?? $att['first'],
                'placement' => isset($a['placement']) ? mb_substr((string) $a['placement'], 0, 30) : null,
                'query' => isset($a['query']) ? mb_substr(trim((string) $a['query']), 0, 100) : null,
                'amount' => $a['amount'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Logger::error('Tracking failed', ['event' => $event, 'error' => $e->getMessage()]);
        }
    }

    /** May this visitor's behaviour be recorded? */
    public static function allowed(?int $userId = null): bool
    {
        if (!Settings::bool('tracking_enabled', true)) {
            return false;
        }
        $userId ??= Auth::id();
        if ($userId !== null) {
            return (int) App::db()->value('SELECT personalisation FROM users WHERE id = ?', [$userId]) === 1;
        }
        $choice = Consent::guestChoice();
        return Settings::get('tracking_consent_mode', 'opt_in') === 'notice' ? $choice !== false : $choice === true;
    }

    /** Whether to show the guest banner (opt-in mode, not answered yet, not signed in). */
    public static function needsGuestChoice(): bool
    {
        return Settings::bool('tracking_enabled', true) && Auth::id() === null && Consent::guestChoice() === null;
    }

    /** The browser's random visitor id; created (and the cookie set) only when $create and tracking is allowed. */
    public static function visitorId(bool $create = false): ?string
    {
        if (self::$visitor !== null) {
            return self::$visitor;
        }
        $v = (string) ($_COOKIE[self::COOKIE] ?? '');
        if (preg_match('/^[a-f0-9]{32}$/', $v)) {
            return self::$visitor = $v;
        }
        if (!$create) {
            return null;
        }
        self::$visitor = bin2hex(random_bytes(16));
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            setcookie(self::COOKIE, self::$visitor, [
                'expires' => time() + 180 * 86400, 'path' => rtrim((string) config('app.base_path', ''), '/') . '/',
                'secure' => (bool) config('session.secure', false), 'httponly' => true, 'samesite' => 'Lax',
            ]);
        }
        return self::$visitor;
    }

    /**
     * On sign-in or registration: this browser's recent guest activity joins the account, if the customer allows
     * personalisation. Only rows with no owner are taken, so one person's history can never move to another account.
     */
    public static function claimGuestHistory(int $userId): void
    {
        try {
            $visitor = self::visitorId();
            if ($visitor === null || !self::allowed($userId)) {
                return;
            }
            App::db()->exec('UPDATE shop_events SET user_id = ? WHERE visitor_id = ? AND user_id IS NULL AND occurred_at > NOW() - INTERVAL ? DAY',
                [$userId, $visitor, max(1, Settings::int('guest_history_days', 30))]);
        } catch (\Throwable $e) {
            Logger::error('Claiming guest history failed', ['error' => $e->getMessage()]);
        }
    }

    /** Nightly: deletes activity older than the retention periods. @return int rows deleted */
    public static function purge(): int
    {
        $db = App::db();
        $n = $db->exec('DELETE FROM shop_events WHERE occurred_at < NOW() - INTERVAL ? DAY', [max(30, Settings::int('tracking_retention_days', 180))]);
        $n += $db->exec('DELETE FROM shop_events WHERE user_id IS NULL AND visitor_id IS NOT NULL AND occurred_at < NOW() - INTERVAL ? DAY',
            [max(1, Settings::int('guest_history_days', 30))]);
        $n += $db->exec('DELETE FROM campaign_clicks WHERE occurred_at < NOW() - INTERVAL ? DAY', [max(30, Settings::int('tracking_retention_days', 180))]);
        return $n;
    }

    /** Tests only. */
    public static function reset(): void
    {
        self::$visitor = null;
    }
}
