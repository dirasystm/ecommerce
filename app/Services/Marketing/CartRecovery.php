<?php

declare(strict_types=1);

namespace Waimart\Services\Marketing;

use Waimart\Core\App;
use Waimart\Core\Auth;
use Waimart\Core\Crypto;
use Waimart\Core\Database;
use Waimart\Core\Logger;
use Waimart\Core\Session;
use Waimart\Core\Settings;
use Waimart\Services\CartService;

/**
 * Abandoned cart recovery: the secure link in each reminder, opening the right cart from it, crediting recovered
 * orders, and the figures for Admin → Abandoned carts. Detection and sending are the "cart_recovery" automation.
 *
 * Links: one unguessable token per reminder (only its hash is stored), valid for "cart_reminder_link_days". A
 * customer's cart needs that customer signed in (the link sends them to sign in first); a guest cart is reopened in
 * the browser that follows the link. Nothing is ordered or charged; the cart page re-checks prices and stock.
 *
 * Recovered order: an order placed within the link period after a reminder was sent, by that customer (or, for a
 * guest, from the link or with the lead's email or phone). Each order credits one reminder, the latest.
 */
final class CartRecovery
{
    private const SESSION = 'cart_recovery_msg';

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? App::db();
    }

    /** Creates the link for a reminder (a new token each time the message is built). */
    public static function link(int $messageId, ?Database $db = null): string
    {
        $token = Crypto::token(16);     // 128 bits, 22 characters: keeps the SMS short
        ($db ?? App::db())->exec('UPDATE marketing_messages SET link_token_hash = ? WHERE id = ?', [Crypto::hash($token), $messageId]);
        return rtrim((string) config('app.url'), '/') . '/cart/recover/' . $messageId . '/' . $token;
    }

    /**
     * Follows a reminder link. Returns where to go and what to tell the customer.
     * @return array{to:string,flash:?array{0:string,1:string}}
     */
    public function open(int $messageId, string $token): array
    {
        $m = $this->db->one("SELECT * FROM marketing_messages WHERE id = ? AND automation = 'cart_recovery' AND link_token_hash IS NOT NULL", [$messageId]);
        if ($m === null || !hash_equals((string) $m['link_token_hash'], Crypto::hash($token))) {
            return ['to' => '/cart', 'flash' => ['error', 'This cart link is not valid. Your current cart is shown below.']];
        }
        $days = max(1, Settings::int('cart_reminder_link_days', 7));
        if ($m['sent_at'] === null || strtotime((string) $m['sent_at']) < time() - $days * 86400) {
            return ['to' => '/cart', 'flash' => ['error', 'This cart link has expired. If you were signed in, your cart is still saved in your account.']];
        }
        $this->db->exec('UPDATE marketing_messages SET clicked_at = COALESCE(clicked_at, NOW()) WHERE id = ?', [$messageId]);
        Session::set(self::SESSION, $messageId);
        $p = (array) json_decode((string) $m['payload'], true);
        $cart = $this->db->one('SELECT id, user_id FROM carts WHERE id = ?', [(int) ($p['cart_id'] ?? 0)]);
        if ($cart === null || !$this->db->value('SELECT 1 FROM cart_items WHERE cart_id = ? LIMIT 1', [$cart['id']])) {
            return ['to' => '/cart', 'flash' => ['info', 'Your saved cart is empty now (you may already have ordered). Happy shopping!']];
        }
        $me = Auth::id();
        if ($cart['user_id'] !== null) {
            if ($me === null) {
                Session::set('intended', '/cart');
                return ['to' => '/login', 'flash' => ['info', 'Sign in to see your saved cart.']];
            }
            if ((int) $cart['user_id'] !== $me) {
                return ['to' => '/cart', 'flash' => ['error', 'That cart belongs to another account. Sign out and sign in with the account the email was sent to.']];
            }
            return ['to' => '/cart', 'flash' => ['success', 'Welcome back! Your cart is below. Prices and stock have been checked again.']];
        }
        // Guest cart: give this browser a new key to it (the old key stops working), or add it to the signed-in account.
        $newToken = Crypto::token(32);
        $this->db->exec('UPDATE carts SET token_hash = ? WHERE id = ? AND user_id IS NULL', [Crypto::hash($newToken), $cart['id']]);
        if ($me !== null) {
            CartService::mergeGuestIntoUser($me, $newToken, $this->db);
        } else {
            CartService::setCookie($newToken);
            $_COOKIE[CartService::COOKIE] = $newToken;
        }
        return ['to' => '/cart', 'flash' => ['success', 'Welcome back! Your cart is below. Prices and stock have been checked again.']];
    }

    /** Credits a placed order to the latest reminder it recovers (see class comment). @param array<string,mixed> $order */
    public function orderPlaced(array $order): void
    {
        try {
            $days = max(1, Settings::int('cart_reminder_link_days', 7));
            $base = "SELECT m.id FROM marketing_messages m WHERE m.automation = 'cart_recovery' AND m.status IN ('sent','logged') AND m.recovered_order_id IS NULL
                       AND m.sent_at <= ? AND m.sent_at > ? - INTERVAL $days DAY";
            $at = (string) ($order['placed_at'] ?? date('Y-m-d H:i:s'));
            $id = null;
            if (!empty($order['user_id'])) {
                $id = $this->db->value("$base AND m.user_id = ? ORDER BY m.sent_at DESC LIMIT 1", [$at, $at, (int) $order['user_id']]);
            }
            if (!$id && ($clicked = (int) Session::get(self::SESSION, 0)) > 0) {
                $id = $this->db->value("$base AND m.id = ?", [$at, $at, $clicked]);
            }
            if (!$id && empty($order['user_id'])) {
                $id = $this->db->value("$base AND m.lead_id IN (SELECT l.id FROM marketing_leads l WHERE (l.email <> '' AND l.email = ?) OR (l.phone <> '' AND l.phone = ?))
                        ORDER BY m.sent_at DESC LIMIT 1", [$at, $at, (string) ($order['customer_email'] ?? ''), (string) ($order['customer_phone'] ?? '')]);
            }
            if ($id) {
                // Both channels of the same reminder point to one recovery; credit the one sent last, once.
                $this->db->exec('UPDATE marketing_messages SET recovered_order_id = ?, recovered_amount = ? WHERE id = ? AND recovered_order_id IS NULL',
                    [(int) $order['id'], (string) $order['grand_total'], (int) $id]);
            }
            Session::forget(self::SESSION);
        } catch (\Throwable $e) {
            Logger::error('Cart recovery credit failed', ['order_id' => $order['id'] ?? null, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Figures for the dashboard, for carts last active in the range.
     * @return array<string,mixed>
     */
    public function stats(string $from, string $to): array
    {
        $min = max(15, Settings::int('cart_abandon_minutes', 60));
        $seen = 'COALESCE(c.last_activity_at, c.updated_at)';
        $abandoned = "EXISTS (SELECT 1 FROM cart_items ci WHERE ci.cart_id = c.id) AND $seen <= NOW() - INTERVAL $min MINUTE
                      AND NOT EXISTS (SELECT 1 FROM orders o WHERE o.user_id = c.user_id AND o.placed_at > $seen)";
        $p = [$from, $to];
        $carts = $this->db->one("SELECT COUNT(*) AS n,
                SUM(c.user_id IS NOT NULL AND EXISTS (SELECT 1 FROM users u WHERE u.id = c.user_id AND u.status = 'active' AND u.is_staff = 0
                    AND ((u.marketing_email = 1 AND u.email <> '') OR (u.marketing_sms = 1 AND u.phone IS NOT NULL AND u.phone <> ''))))
                + SUM(c.user_id IS NULL AND EXISTS (SELECT 1 FROM marketing_leads l WHERE l.visitor_id = c.visitor_id AND l.user_id IS NULL AND (l.consent_email = 1 OR l.consent_sms = 1))) AS eligible,
                COALESCE(SUM((SELECT SUM(ci.quantity * v.price) FROM cart_items ci JOIN product_variants v ON v.id = ci.variant_id WHERE ci.cart_id = c.id)), 0) AS value
              FROM carts c WHERE $abandoned AND $seen BETWEEN ? AND ?", $p);
        $m = $this->db->one("SELECT
                SUM(channel = 'email' AND status IN ('sent','logged')) AS email_sent, SUM(channel = 'email' AND status = 'failed') AS email_failed,
                SUM(channel = 'sms' AND status IN ('sent','logged')) AS sms_sent, SUM(channel = 'sms' AND status = 'failed') AS sms_failed,
                SUM(status = 'queued') AS queued, SUM(status = 'suppressed') AS suppressed, SUM(status = 'cancelled') AS cancelled,
                SUM(GREATEST(attempts - 1, 0)) AS retries, SUM(clicked_at IS NOT NULL) AS clicks,
                COUNT(DISTINCT CASE WHEN clicked_at IS NOT NULL THEN JSON_VALUE(payload, '$.cart_id') END) AS returned,
                COUNT(DISTINCT CASE WHEN status IN ('sent','logged') THEN JSON_VALUE(payload, '$.cart_id') END) AS reminded,
                COUNT(DISTINCT recovered_order_id) AS recovered, COALESCE(SUM(recovered_amount), 0) AS revenue
              FROM marketing_messages WHERE automation = 'cart_recovery' AND created_at BETWEEN ? AND ?", $p);
        $paid = (float) $this->db->value("SELECT COALESCE(SUM(o.grand_total), 0) FROM orders o WHERE o.payment_status IN ('verified','cod_collected')
              AND o.id IN (SELECT recovered_order_id FROM marketing_messages WHERE automation = 'cart_recovery' AND created_at BETWEEN ? AND ?)", $p);
        $smsDelivered = (int) $this->db->value("SELECT COUNT(*) FROM marketing_messages m JOIN notifications n ON n.id = m.notification_id
              WHERE m.automation = 'cart_recovery' AND m.channel = 'sms' AND n.provider_status LIKE 'deliver%' AND m.created_at BETWEEN ? AND ?", $p);
        $int = fn ($v) => (int) $v;
        return [
            'abandoned' => (int) $carts['n'], 'eligible' => (int) $carts['eligible'], 'cart_value' => (float) $carts['value'],
            'email_sent' => $int($m['email_sent']), 'email_failed' => $int($m['email_failed']), 'sms_sent' => $int($m['sms_sent']), 'sms_failed' => $int($m['sms_failed']),
            'sms_delivered' => $smsDelivered, 'queued' => $int($m['queued']), 'suppressed' => $int($m['suppressed']), 'cancelled' => $int($m['cancelled']),
            'retries' => $int($m['retries']), 'clicks' => $int($m['clicks']), 'returned' => $int($m['returned']), 'reminded' => $int($m['reminded']),
            'recovered' => $int($m['recovered']), 'revenue' => (float) $m['revenue'], 'paid_revenue' => $paid,
            'rate' => $int($m['reminded']) > 0 ? (float) $int($m['recovered']) / $int($m['reminded']) : null,
        ];
    }

    /**
     * Abandoned carts (last active in the range), newest first, searchable by customer name, email, phone or cart number.
     * Contact details are masked unless $full.
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function carts(string $from, string $to, string $q, int $page, int $per): array
    {
        $min = max(15, Settings::int('cart_abandon_minutes', 60));
        $seen = 'COALESCE(c.last_activity_at, c.updated_at)';
        $where = "EXISTS (SELECT 1 FROM cart_items ci WHERE ci.cart_id = c.id) AND $seen <= NOW() - INTERVAL $min MINUTE
                  AND NOT EXISTS (SELECT 1 FROM orders o WHERE o.user_id = c.user_id AND o.placed_at > $seen) AND $seen BETWEEN ? AND ?";
        $params = [$from, $to];
        if (($q = trim(mb_substr($q, 0, 100))) !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where .= " AND (CONCAT_WS(' ', u.first_name, u.last_name, u.email, u.phone, l.name, l.email, l.phone) LIKE ? OR c.id = ?)";
            array_push($params, $like, ctype_digit(ltrim($q, '#')) ? (int) ltrim($q, '#') : 0);
        }
        $from_ = "FROM carts c LEFT JOIN users u ON u.id = c.user_id
                  LEFT JOIN marketing_leads l ON c.user_id IS NULL AND l.visitor_id = c.visitor_id AND c.visitor_id IS NOT NULL AND l.user_id IS NULL";
        $total = (int) $this->db->value("SELECT COUNT(*) $from_ WHERE $where", $params);
        $rows = $this->db->all("SELECT c.id, c.user_id, $seen AS seen, u.first_name, u.last_name, u.email, u.phone, l.name AS lead_name, l.email AS lead_email,
                (SELECT SUM(ci.quantity) FROM cart_items ci WHERE ci.cart_id = c.id) AS items,
                (SELECT SUM(ci.quantity * v.price) FROM cart_items ci JOIN product_variants v ON v.id = ci.variant_id WHERE ci.cart_id = c.id) AS value,
                (SELECT COUNT(*) FROM marketing_messages m WHERE m.automation = 'cart_recovery' AND m.status IN ('sent','logged') AND JSON_VALUE(m.payload, '$.cart_id') = c.id) AS reminders,
                (SELECT MAX(m.clicked_at) FROM marketing_messages m WHERE m.automation = 'cart_recovery' AND JSON_VALUE(m.payload, '$.cart_id') = c.id) AS clicked_at
              $from_ WHERE $where ORDER BY seen DESC LIMIT $per OFFSET " . (max(1, $page) - 1) * $per, $params);
        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Reminder log, newest first, filterable by channel and status and searchable by recipient or reason.
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function log(string $from, string $to, string $q, string $channel, string $status, int $page, int $per): array
    {
        $where = "m.automation = 'cart_recovery' AND m.created_at BETWEEN ? AND ?";
        $params = [$from, $to];
        if (in_array($channel, ['email', 'sms'], true)) {
            $where .= ' AND m.channel = ?';
            $params[] = $channel;
        }
        if (in_array($status, ['queued', 'sent', 'logged', 'failed', 'suppressed', 'cancelled'], true)) {
            $where .= ' AND m.status = ?';
            $params[] = $status;
        } elseif ($status === 'recovered') {
            $where .= ' AND m.recovered_order_id IS NOT NULL';
        }
        if (($q = trim(mb_substr($q, 0, 100))) !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where .= " AND (CONCAT_WS(' ', u.first_name, u.last_name, u.email, u.phone, l.name, l.email, l.phone, m.reason) LIKE ?)";
            $params[] = $like;
        }
        $from_ = 'FROM marketing_messages m LEFT JOIN users u ON u.id = m.user_id LEFT JOIN marketing_leads l ON l.id = m.lead_id
                  LEFT JOIN notifications n ON n.id = m.notification_id LEFT JOIN orders o ON o.id = m.recovered_order_id';
        $total = (int) $this->db->value("SELECT COUNT(*) $from_ WHERE $where", $params);
        $rows = $this->db->all("SELECT m.id, m.channel, m.status, m.reason, m.attempts, m.scheduled_at, m.sent_at, m.clicked_at, m.recovered_amount, m.payload,
                COALESCE(CONCAT_WS(' ', u.first_name, u.last_name), l.name) AS who, COALESCE(u.email, l.email) AS email, COALESCE(u.phone, l.phone) AS phone,
                n.provider_status, n.last_error, o.order_number
              $from_ WHERE $where ORDER BY m.id DESC LIMIT $per OFFSET " . (max(1, $page) - 1) * $per, $params);
        return ['rows' => $rows, 'total' => $total];
    }
}
