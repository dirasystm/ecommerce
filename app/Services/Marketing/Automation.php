<?php

declare(strict_types=1);

namespace Waimart\Services\Marketing;

use Waimart\Core\App;
use Waimart\Core\Database;
use Waimart\Core\Logger;
use Waimart\Core\Money;
use Waimart\Core\Settings;
use Waimart\Repositories\CatalogueRepository;
use Waimart\Services\AuditLogger;
use Waimart\Services\MessageTemplates;
use Waimart\Services\NotificationService;
use Waimart\Services\PricingService;

/**
 * Marketing automations, run by the scheduler (bin/marketing-run.php every 15 minutes), never by page visits.
 *
 * 1. enqueue(): finds who each switched-on automation applies to and queues one message per event and channel.
 *    The unique key (automation, channel, trigger) makes queuing idempotent: running twice queues nothing twice.
 * 2. process(): sends due messages. Each is claimed first (queued → sending), so two runs at once cannot both send it.
 *    Just before sending it is checked again: global pause, automation on, consent for that channel, still relevant
 *    (e.g. the cart was not ordered), daily/weekly limits, quiet hours. Temporary failures are retried (3 times);
 *    messages more than 3 days late are cancelled rather than sent stale.
 */
final class Automation
{
    public const CODES = [
        'welcome' => ['Welcome', 'New customers who agreed to marketing, once.', 'mkt_welcome'],
        'cart_recovery' => ['Cart reminders', 'Items left in the cart without an order: first and (optional) second reminder.', 'mkt_cart'],
        'post_purchase' => ['After delivery: you may also like', 'Products that go with a delivered or collected order.', 'mkt_post_purchase'],
        'review_request' => ['Review request', 'Asks for reviews of delivered items not yet reviewed.', 'mkt_review'],
        'reengage' => ['Win back inactive customers', 'No order since the delays below (e.g. 30, 60, 90 days after the last order).', 'mkt_reengage'],
        'price_drop' => ['Wishlist price drops', 'A saved product became cheaper by at least the % in Settings.', 'mkt_price_drop'],
        'discovery' => ['Product discovery', 'Weekly at most: picks from a category the customer keeps browsing without buying.', 'mkt_discovery'],
    ];
    private const LATE_DAYS = 3;

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? App::db();
    }

    /** @return array<string,array<string,mixed>> automation rows by code */
    public function all(): array
    {
        return array_column($this->db->all('SELECT * FROM marketing_automations ORDER BY FIELD(code, ' . implode(',', array_map(fn ($c) => "'$c'", array_keys(self::CODES))) . ')'), null, 'code');
    }

    /** @param array<string,mixed> $in */
    public function save(string $code, array $in, int $actorId): void
    {
        if (!isset(self::CODES[$code])) {
            throw new \Waimart\Core\ValidationException(['code' => 'Unknown automation.']);
        }
        $channels = array_values(array_intersect(['email', 'sms'], (array) ($in['channels'] ?? [])));
        if (!$channels) {
            throw new \Waimart\Core\ValidationException(['channels' => 'Choose email, SMS or both.']);
        }
        $int = function ($v, string $field) {
            $v = trim((string) $v);
            if ($v === '') {
                return null;
            }
            if (!ctype_digit($v) || (int) $v > 8760) {
                throw new \Waimart\Core\ValidationException([$field => 'Enter whole hours (0 to 8760).']);
            }
            return (int) $v;
        };
        $d1 = $int($in['delay_hours'] ?? '0', 'delay_hours') ?? 0;
        $d2 = $int($in['delay2_hours'] ?? '', 'delay2_hours');
        $d3 = $int($in['delay3_hours'] ?? '', 'delay3_hours');
        if (($d2 !== null && $d2 <= $d1) || ($d3 !== null && ($d2 === null || $d3 <= $d2))) {
            throw new \Waimart\Core\ValidationException(['delay2_hours' => 'Each later step must come after the one before it.']);
        }
        $before = $this->db->one('SELECT is_enabled, channels, delay_hours, delay2_hours, delay3_hours FROM marketing_automations WHERE code = ?', [$code]);
        $after = ['is_enabled' => !empty($in['is_enabled']) ? 1 : 0, 'channels' => implode(',', $channels), 'delay_hours' => $d1, 'delay2_hours' => $d2, 'delay3_hours' => $d3];
        $this->db->exec('UPDATE marketing_automations SET is_enabled = ?, channels = ?, delay_hours = ?, delay2_hours = ?, delay3_hours = ?, updated_by = ? WHERE code = ?',
            [...array_values($after), $actorId, $code]);
        if (!$after['is_enabled']) {
            $this->db->exec("UPDATE marketing_messages SET status = 'cancelled', reason = 'Automation switched off' WHERE automation = ? AND status = 'queued'", [$code]);
        }
        AuditLogger::log('marketing.automation_saved', 'automation', $code, $before, $after, $actorId);
    }

    // ------------------------------------------------------------------ 1. queue

    /** @return array<string,int> messages queued per automation */
    public function enqueue(): array
    {
        $out = [];
        foreach ($this->all() as $code => $a) {
            if (!(int) $a['is_enabled']) {
                continue;
            }
            $out[$code] = 0;
            try {
                foreach ($this->triggers($code, $a) as $t) {
                    foreach (explode(',', (string) $a['channels']) as $channel) {
                        $out[$code] += $this->db->exec(
                            'INSERT IGNORE INTO marketing_messages (automation, trigger_key, channel, user_id, lead_id, payload, scheduled_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                            [$code, $t['key'], $channel, $t['user_id'], $t['lead_id'] ?? null, json_encode($t['payload'] ?? []), $t['at']]);
                    }
                }
            } catch (\Throwable $e) {
                Logger::error('Marketing enqueue failed', ['automation' => $code, 'error' => $e->getMessage()]);
            }
        }
        return $out;
    }

    /** @return list<array{key:string,user_id:?int,lead_id?:int,at:string,payload?:array<string,mixed>}> */
    private function triggers(string $code, array $a): array
    {
        $h = (int) $a['delay_hours'];
        $consent = 'u.is_staff = 0 AND u.status = \'active\' AND (u.marketing_email = 1 OR u.marketing_sms = 1)';
        $rows = [];
        switch ($code) {
            case 'welcome':
                foreach ($this->db->all("SELECT u.id, u.created_at FROM users u WHERE $consent AND u.created_at > NOW() - INTERVAL 3 DAY") as $u) {
                    $rows[] = ['key' => 'u' . $u['id'], 'user_id' => (int) $u['id'], 'at' => $this->plus((string) $u['created_at'], $h)];
                }
                break;
            case 'cart_recovery':
                // Inactivity is measured from the cart's last activity on the server (adding, changing, opening the cart or checkout).
                // Step 1 after "cart_abandon_minutes" (default 60); optional step 2 after delay2_hours. New activity starts a new cycle.
                $first = max(15, Settings::int('cart_abandon_minutes', 60)) * 60;
                $steps = array_filter([1 => $first, 2 => $a['delay2_hours'] !== null ? (int) $a['delay2_hours'] * 3600 : null], fn ($v) => $v !== null && $v >= $first);
                $seen = "COALESCE(c.last_activity_at, c.updated_at)";
                $carts = $this->db->all("SELECT c.id, c.user_id, NULL AS lead_id, $seen AS seen FROM carts c JOIN users u ON u.id = c.user_id
                        WHERE $consent AND $seen > NOW() - INTERVAL 7 DAY AND $seen <= NOW() - INTERVAL ? SECOND AND EXISTS (SELECT 1 FROM cart_items ci WHERE ci.cart_id = c.id)
                          AND NOT EXISTS (SELECT 1 FROM orders o WHERE o.user_id = c.user_id AND o.placed_at > $seen)", [$first]);
                if (Settings::bool('cart_recovery_guests', true)) {
                    // Guests: only a browser that signed up for the newsletter with consent (we have no other way to reach a guest).
                    $carts = array_merge($carts, $this->db->all("SELECT c.id, NULL AS user_id, l.id AS lead_id, $seen AS seen FROM carts c
                            JOIN marketing_leads l ON l.visitor_id = c.visitor_id AND l.user_id IS NULL AND (l.consent_email = 1 OR l.consent_sms = 1)
                           WHERE c.user_id IS NULL AND c.visitor_id IS NOT NULL AND $seen > NOW() - INTERVAL 7 DAY AND $seen <= NOW() - INTERVAL ? SECOND
                             AND EXISTS (SELECT 1 FROM cart_items ci WHERE ci.cart_id = c.id)", [$first]));
                }
                foreach ($carts as $c) {
                    foreach ($steps as $step => $delay) {
                        $rows[] = ['key' => 'c' . $c['id'] . ':' . strtotime((string) $c['seen']) . ':' . $step, 'user_id' => $c['user_id'] !== null ? (int) $c['user_id'] : null,
                            'lead_id' => $c['lead_id'] !== null ? (int) $c['lead_id'] : null,
                            'at' => date('Y-m-d H:i:s', strtotime((string) $c['seen']) + $delay), 'payload' => ['cart_id' => (int) $c['id'], 'cart_at' => (string) $c['seen'], 'step' => $step]];
                    }
                }
                break;
            case 'post_purchase':
            case 'review_request':
                foreach ($this->db->all("SELECT o.id, o.user_id, COALESCE(f.delivered_at, f.collected_at) AS done_at FROM orders o JOIN users u ON u.id = o.user_id
                        JOIN order_fulfilment f ON f.order_id = o.id WHERE $consent AND o.status IN ('delivered','collected')
                          AND COALESCE(f.delivered_at, f.collected_at) > NOW() - INTERVAL 14 DAY") as $o) {
                    $rows[] = ['key' => ($code === 'review_request' ? 'r' : 'o') . $o['id'], 'user_id' => (int) $o['user_id'], 'at' => $this->plus((string) $o['done_at'], $h),
                        'payload' => ['order_id' => (int) $o['id']]];
                }
                break;
            case 'reengage':
                $delays = array_values(array_filter([$h, $a['delay2_hours'], $a['delay3_hours']], fn ($v) => $v !== null));
                foreach ($this->db->all("SELECT u.id, MAX(o.id) AS last_id, MAX(o.placed_at) AS last_at FROM users u JOIN orders o ON o.user_id = u.id AND o.status NOT IN ('cancelled','expired')
                        WHERE $consent GROUP BY u.id HAVING last_at < NOW() - INTERVAL ? HOUR", [(int) $delays[0]]) as $u) {
                    $age = (time() - strtotime((string) $u['last_at'])) / 3600;
                    foreach ($delays as $i => $d) {
                        if ($age >= (int) $d && (!isset($delays[$i + 1]) || $age < (int) $delays[$i + 1])) {
                            $rows[] = ['key' => 'u' . $u['id'] . ':o' . $u['last_id'] . ':' . ($i + 1), 'user_id' => (int) $u['id'], 'at' => date('Y-m-d H:i:s'),
                                'payload' => ['last_order_id' => (int) $u['last_id']]];
                        }
                    }
                }
                break;
            case 'price_drop':
                $min = max(1, Settings::int('price_drop_min_percent', 5)) / 100;
                foreach ($this->db->all("SELECT w.user_id, w.product_id, COALESCE(w.last_alert_price, w.price_at_add) AS ref, COALESCE(fs.sale_price, v.price) AS now_price
                        FROM wishlist_items w JOIN users u ON u.id = w.user_id JOIN products p ON p.id = w.product_id " . CatalogueRepository::SELLER_JOIN . "
                        JOIN product_variants v ON v.default_product_key = p.id AND v.is_active = 1 JOIN inventory i ON i.variant_id = v.id
                        LEFT JOIN (" . PricingService::ACTIVE_FLASH_SQL . ") fs ON fs.variant_id = v.id
                       WHERE $consent AND " . CatalogueRepository::PUBLIC_PRODUCT . ' AND (i.on_hand - i.reserved) > 0
                         AND COALESCE(w.last_alert_price, w.price_at_add) IS NOT NULL') as $w) {
                    if ((float) $w['now_price'] <= (float) $w['ref'] * (1 - $min)) {
                        $rows[] = ['key' => 'p' . $w['user_id'] . ':' . $w['product_id'] . ':' . (int) round((float) $w['now_price'] * 100), 'user_id' => (int) $w['user_id'],
                            'at' => date('Y-m-d H:i:s'), 'payload' => ['product_id' => (int) $w['product_id'], 'old' => (string) $w['ref'], 'new' => (string) $w['now_price']]];
                    }
                }
                break;
            case 'discovery':
                foreach ($this->db->all("SELECT e.user_id, p.category_id, COUNT(*) AS n FROM shop_events e JOIN users u ON u.id = e.user_id JOIN products p ON p.id = e.product_id
                        WHERE $consent AND e.event = 'product_viewed' AND e.occurred_at > NOW() - INTERVAL 7 DAY
                          AND NOT EXISTS (SELECT 1 FROM orders o WHERE o.user_id = e.user_id AND o.placed_at > NOW() - INTERVAL 7 DAY)
                        GROUP BY e.user_id, p.category_id HAVING n >= 3 ORDER BY n DESC") as $r) {
                    $rows[] = ['key' => 'd' . $r['user_id'] . ':' . date('oW'), 'user_id' => (int) $r['user_id'], 'at' => date('Y-m-d H:i:s'),
                        'payload' => ['category_id' => (int) $r['category_id']]];
                }
                break;
        }
        return $rows;
    }

    // ------------------------------------------------------------------ 2. send

    /** @return array<string,int> counts by outcome */
    public function process(int $limit = 200): array
    {
        $stats = ['sent' => 0, 'logged' => 0, 'failed' => 0, 'retry' => 0, 'suppressed' => 0, 'cancelled' => 0, 'deferred' => 0];
        if (Settings::bool('marketing_paused', false)) {
            return $stats + ['paused' => 1];
        }
        $automations = $this->all();
        foreach ($this->db->all("SELECT id FROM marketing_messages WHERE status = 'queued' AND scheduled_at <= NOW() ORDER BY scheduled_at, id LIMIT " . max(1, $limit)) as $row) {
            // Claim: only one runner can move this message from queued to sending.
            if ($this->db->exec("UPDATE marketing_messages SET status = 'sending' WHERE id = ? AND status = 'queued'", [$row['id']]) !== 1) {
                continue;
            }
            $m = $this->db->one('SELECT * FROM marketing_messages WHERE id = ?', [$row['id']]);
            try {
                $outcome = $this->deliver($m, $automations[$m['automation']] ?? null);
            } catch (\Throwable $e) {
                Logger::error('Marketing message failed', ['message_id' => $m['id'], 'error' => $e->getMessage()]);
                $outcome = $this->retry($m, 'Error while sending');
            }
            $stats[$outcome] = ($stats[$outcome] ?? 0) + 1;
        }
        return $stats;
    }

    private function deliver(array $m, ?array $a): string
    {
        $now = time();
        if ($a === null || !(int) $a['is_enabled']) {
            return $this->finish($m, 'cancelled', 'Automation switched off');
        }
        if (strtotime((string) $m['scheduled_at']) < $now - self::LATE_DAYS * 86400) {
            return $this->finish($m, 'cancelled', 'Too late to send');
        }
        $u = $this->recipient($m);
        if ($u === null || $u['status'] !== 'active' || (int) $u['is_staff'] === 1) {
            return $this->finish($m, 'cancelled', 'Customer not active');
        }
        $who = $u['kind'] === 'u' ? 'user_id' : 'lead_id';
        $channel = (string) $m['channel'];
        if (($channel === 'email' && (!(int) $u['marketing_email'] || (string) $u['email'] === '')) || ($channel === 'sms' && (!(int) $u['marketing_sms'] || (string) $u['phone'] === ''))) {
            return $this->finish($m, 'suppressed', 'No consent for ' . $channel);
        }
        $payload = (array) json_decode((string) $m['payload'], true);
        $why = $this->stillRelevant((string) $m['automation'], $u['kind'] === 'u' ? (int) $u['id'] : null, $payload, $u);
        if ($why !== null) {
            return $this->finish($m, 'cancelled', $why);
        }
        // Frequency limits (all marketing, all channels) and quiet hours.
        $perDay = $u['marketing_frequency'] === 'less' ? 1 : max(0, Settings::int('marketing_max_per_day', 1));
        $perWeek = $u['marketing_frequency'] === 'less' ? 1 : max(0, Settings::int('marketing_max_per_week', 3));
        $sentDay = (int) $this->db->value("SELECT COUNT(*) FROM marketing_messages WHERE $who = ? AND status IN ('sent','logged') AND sent_at > NOW() - INTERVAL 1 DAY", [$u['id']]);
        $sentWeek = (int) $this->db->value("SELECT COUNT(*) FROM marketing_messages WHERE $who = ? AND status IN ('sent','logged') AND sent_at > NOW() - INTERVAL 7 DAY", [$u['id']]);
        if ($sentWeek >= $perWeek) {
            return $this->defer($m, 24 * 3600, 'Weekly limit reached');
        }
        if ($sentDay >= $perDay) {
            return $this->defer($m, 24 * 3600, 'Daily limit reached');
        }
        if (($wait = self::quietWait()) > 0) {
            return $this->defer($m, $wait, 'Quiet hours');
        }
        [$code, $vars, $ctx] = $this->content((string) $m['automation'], $u, $payload, $channel, $m);
        $msg = (new MessageTemplates())->render($code, $vars);
        $notify = new NotificationService();
        $ctx += $u['kind'] === 'u' ? ['user_id' => (int) $u['id']] : [];
        if ($channel === 'email') {
            if ($msg['email'] === null) {
                return $this->finish($m, 'cancelled', 'Email switched off in the message wording');
            }
            $nid = $notify->email((string) $u['email'], (string) $msg['subject'], $msg['email'], $code, $ctx);
        } else {
            if ($msg['sms'] === null) {
                return $this->finish($m, 'cancelled', 'SMS switched off in the message wording');
            }
            $nid = $notify->sms((string) $u['phone'], $msg['sms'], $code, $ctx);
        }
        $n = $this->db->one('SELECT status, last_error FROM notifications WHERE id = ?', [$nid]);
        $this->db->exec('UPDATE marketing_messages SET notification_id = ? WHERE id = ?', [$nid, $m['id']]);
        if (($n['status'] ?? '') === 'failed') {
            return $this->retry($m, (string) ($n['last_error'] ?? 'Send failed'));
        }
        if ($m['automation'] === 'price_drop') {
            $this->db->exec('UPDATE wishlist_items SET last_alert_price = ? WHERE user_id = ? AND product_id = ?', [$payload['new'] ?? null, $u['id'], $payload['product_id'] ?? 0]);
        }
        // "logged": recorded but not delivered (development mail log or SMS switched off).
        return $this->finish($m, ($n['status'] ?? '') === 'sent' ? 'sent' : 'logged', null, true);
    }

    /** Null when the message still makes sense; otherwise the reason not to send it. */
    private function stillRelevant(string $code, ?int $userId, array $p, array $u = []): ?string
    {
        switch ($code) {
            case 'cart_recovery':
                $cart = $this->db->one('SELECT user_id, COALESCE(last_activity_at, updated_at) AS seen FROM carts WHERE id = ?', [$p['cart_id'] ?? 0]);
                if ($cart === null || ($userId !== null && (int) $cart['user_id'] !== $userId) || ($userId === null && $cart['user_id'] !== null)
                    || !$this->db->value('SELECT 1 FROM cart_items WHERE cart_id = ? LIMIT 1', [$p['cart_id']])) {
                    return 'Cart is empty now';
                }
                $since = (string) ($p['cart_at'] ?? $cart['seen']);
                $ordered = $userId !== null
                    ? $this->db->value('SELECT 1 FROM orders WHERE user_id = ? AND placed_at > ? LIMIT 1', [$userId, $since])
                    : $this->db->value('SELECT 1 FROM orders WHERE placed_at > ? AND ((customer_email <> \'\' AND customer_email = ?) OR (customer_phone <> \'\' AND customer_phone = ?)) LIMIT 1',
                        [$since, (string) ($u['email'] ?? ''), (string) ($u['phone'] ?? '')]);
                if ($ordered) {
                    return 'Customer has ordered';
                }
                if (strtotime((string) $cart['seen']) > strtotime($since)) {
                    return 'Customer came back to the cart (a new reminder is planned from the latest activity)';
                }
                if (!$this->cartItems((int) $p['cart_id'])) {
                    return 'Items are no longer available';
                }
                return null;
            case 'reengage':
                return $this->db->value("SELECT 1 FROM orders WHERE user_id = ? AND id > ? AND status NOT IN ('cancelled','expired') LIMIT 1", [$userId, $p['last_order_id'] ?? 0])
                    ? 'Customer has ordered again' : null;
            case 'review_request':
                return $this->db->value('SELECT 1 FROM order_items oi WHERE oi.order_id = ? AND NOT EXISTS (SELECT 1 FROM reviews r WHERE r.order_item_id = oi.id) LIMIT 1', [$p['order_id'] ?? 0])
                    ? null : 'Already reviewed';
            case 'price_drop':
                $now = $this->db->value('SELECT COALESCE(fs.sale_price, v.price) FROM product_variants v LEFT JOIN (' . PricingService::ACTIVE_FLASH_SQL . ') fs ON fs.variant_id = v.id
                                          WHERE v.default_product_key = ?', [$p['product_id'] ?? 0]);
                return $now !== null && (float) $now <= (float) ($p['new'] ?? 0) && $this->db->value('SELECT 1 FROM wishlist_items WHERE user_id = ? AND product_id = ?', [$userId, $p['product_id'] ?? 0])
                    ? null : 'Price went back up or item removed from wishlist';
        }
        return null;
    }

    /** @return array{0:string,1:array<string,string>,2:array<string,mixed>} template code, placeholders, extra context */
    private function content(string $code, array $u, array $p, string $channel, array $m = []): array
    {
        $site = rtrim((string) config('app.url'), '/');
        $vars = ['first_name' => (string) ($u['first_name'] ?: 'there'), 'unsubscribe_url' => Consent::unsubscribeUrl($u['kind'] ?? 'u', (int) $u['id'], $channel),
                 'shop_url' => $site . '/'] + MessageTemplates::contactVars();
        $ctx = ['unsubscribe_url' => $vars['unsubscribe_url']];
        $rec = new Recommender($this->db);
        $picks = fn (array $cards) => $channel === 'email' && Recommender::enabled('email') ? array_slice($cards, 0, 4) : [];
        switch ($code) {
            case 'cart_recovery':
                // Only items still on sale and in stock, at today's price; the link opens this cart (see CartRecovery).
                $items = $this->cartItems((int) ($p['cart_id'] ?? 0));
                $vars['cart_items'] = implode("\n", array_map(fn ($i) => '• ' . $i['name'] . ' × ' . (int) $i['quantity'] . ' — ' . Money::format((string) $i['price']), $items));
                $vars['cart_url'] = $m ? CartRecovery::link((int) $m['id']) : $site . '/cart';
                $cards = array_map(fn ($i) => ['name' => $i['name'] . ' × ' . (int) $i['quantity'], 'slug' => $i['slug'], 'image' => $i['image'], 'price' => $i['price'], 'was' => null], $items);
                return ['mkt_cart', $vars, $ctx + ['products' => $channel === 'email' ? array_slice($cards, 0, 4) : []]];
            case 'post_purchase':
                $vars['order_number'] = (string) $this->db->value('SELECT order_number FROM orders WHERE id = ?', [$p['order_id'] ?? 0]);
                $ids = array_map('intval', array_column($this->db->all('SELECT product_id FROM order_items WHERE order_id = ?', [$p['order_id'] ?? 0]), 'product_id'));
                return ['mkt_post_purchase', $vars, $ctx + ['products' => $picks($rec->complements($ids, 4))]];
            case 'review_request':
                $vars['order_number'] = (string) $this->db->value('SELECT order_number FROM orders WHERE id = ?', [$p['order_id'] ?? 0]);
                $vars['review_url'] = $site . '/account/reviews';
                return ['mkt_review', $vars, $ctx];
            case 'reengage':
                return ['mkt_reengage', $vars, $ctx + ['products' => $picks($rec->forYou((int) $u['id'], null, 4)['cards'])]];
            case 'price_drop':
                $prod = $this->db->one('SELECT name, slug FROM products WHERE id = ?', [$p['product_id'] ?? 0]) ?? ['name' => '', 'slug' => ''];
                $vars += ['product_name' => (string) $prod['name'], 'old_price' => Money::format((string) ($p['old'] ?? '0')), 'new_price' => Money::format((string) ($p['new'] ?? '0')),
                          'product_url' => $site . '/product/' . $prod['slug'] . '?rec=email'];
                return ['mkt_price_drop', $vars, $ctx];
            case 'discovery':
                $cat = $this->db->one('SELECT id, name, slug FROM categories WHERE id = ?', [$p['category_id'] ?? 0]) ?? ['id' => 0, 'name' => 'products', 'slug' => ''];
                $vars['category_name'] = (string) $cat['name'];
                $vars['shop_url'] = $site . '/category/' . $cat['slug'];
                $cards = (new CatalogueRepository($this->db))->cards('p.category_id = ? AND (i.on_hand - i.reserved) > 0', [(int) $cat['id']], 'p.rating_count DESC, p.id DESC', 4);
                return ['mkt_discovery', $vars, $ctx + ['products' => $picks($cards)]];
        }
        return ['mkt_welcome', $vars, $ctx + ['products' => $picks($rec->popular(4))]];
    }

    private function finish(array $m, string $status, ?string $reason, bool $sent = false): string
    {
        $this->db->exec('UPDATE marketing_messages SET status = ?, reason = ?, attempts = attempts + ?, sent_at = ' . ($sent ? 'NOW()' : 'sent_at') . ' WHERE id = ?',
            [$status, $reason, $sent ? 1 : 0, $m['id']]);
        return $status;
    }

    private function retry(array $m, string $error): string
    {
        $attempts = (int) $m['attempts'] + 1;
        if ($attempts >= max(1, min(10, Settings::int('marketing_max_attempts', 3)))) {
            $this->db->exec("UPDATE marketing_messages SET status = 'failed', attempts = ?, reason = ? WHERE id = ?", [$attempts, mb_substr($error, 0, 160), $m['id']]);
            return 'failed';
        }
        $this->db->exec("UPDATE marketing_messages SET status = 'queued', attempts = ?, reason = ?, scheduled_at = NOW() + INTERVAL ? MINUTE WHERE id = ?",
            [$attempts, mb_substr('Retrying: ' . $error, 0, 160), 30 * $attempts, $m['id']]);
        return 'retry';
    }

    private function defer(array $m, int $seconds, string $why): string
    {
        $this->db->exec("UPDATE marketing_messages SET status = 'queued', reason = ?, scheduled_at = ? WHERE id = ?",
            [$why, date('Y-m-d H:i:s', time() + $seconds), $m['id']]);
        return 'deferred';
    }

    /** Seconds until quiet hours end (Kenya time), or 0 outside them. */
    public static function quietWait(?int $now = null): int
    {
        $start = (string) Settings::get('marketing_quiet_start', '20:00');
        $end = (string) Settings::get('marketing_quiet_end', '08:00');
        if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end) || $start === $end) {
            return 0;
        }
        $tz = new \DateTimeZone('Africa/Nairobi');
        $t = (new \DateTimeImmutable('@' . ($now ?? time())))->setTimezone($tz);
        $hm = $t->format('H:i');
        $inQuiet = $start < $end ? ($hm >= $start && $hm < $end) : ($hm >= $start || $hm < $end);
        if (!$inQuiet) {
            return 0;
        }
        $endAt = $t->setTime((int) substr($end, 0, 2), (int) substr($end, 3, 2));
        if ($endAt <= $t) {
            $endAt = $endAt->modify('+1 day');
        }
        return $endAt->getTimestamp() - $t->getTimestamp();
    }

    /**
     * Who a message goes to: the customer, or (guest carts) the newsletter lead, in one shape.
     * @return array<string,mixed>|null
     */
    private function recipient(array $m): ?array
    {
        if ($m['user_id'] !== null) {
            $u = $this->db->one("SELECT id, email, phone, first_name, status, is_staff, marketing_email, marketing_sms, marketing_frequency FROM users WHERE id = ?", [$m['user_id']]);
            return $u === null ? null : $u + ['kind' => 'u'];
        }
        $l = $m['lead_id'] !== null ? $this->db->one('SELECT id, email, phone, name, consent_email, consent_sms, user_id FROM marketing_leads WHERE id = ?', [$m['lead_id']]) : null;
        if ($l === null) {
            return null;
        }
        return ['kind' => 'l', 'id' => (int) $l['id'], 'email' => (string) $l['email'], 'phone' => (string) $l['phone'],
                'first_name' => trim((string) strtok((string) $l['name'], ' ')), 'status' => 'active', 'is_staff' => 0,
                'marketing_email' => (int) $l['consent_email'], 'marketing_sms' => (int) $l['consent_sms'], 'marketing_frequency' => 'normal'];
    }

    /** Cart items still on sale and in stock, with today's price. @return list<array<string,mixed>> */
    private function cartItems(int $cartId): array
    {
        return $this->db->all('SELECT p.name, p.slug, ci.quantity, COALESCE(fs.sale_price, v.price) AS price,
                    (SELECT pi.path FROM product_images pi WHERE pi.product_id = p.id ORDER BY pi.sort_order, pi.id LIMIT 1) AS image
               FROM cart_items ci JOIN product_variants v ON v.id = ci.variant_id AND v.is_active = 1
               JOIN products p ON p.id = v.product_id ' . CatalogueRepository::SELLER_JOIN . '
               JOIN inventory i ON i.variant_id = v.id
               LEFT JOIN (' . PricingService::ACTIVE_FLASH_SQL . ') fs ON fs.variant_id = v.id
              WHERE ci.cart_id = ? AND ' . CatalogueRepository::PUBLIC_PRODUCT . ' AND (i.on_hand - i.reserved) > 0 ORDER BY ci.variant_id', [$cartId]);
    }

    private function plus(string $at, int $hours): string
    {
        return date('Y-m-d H:i:s', strtotime($at) + $hours * 3600);
    }
}
