<?php

declare(strict_types=1);

namespace Waimart\Services\Marketing;

use Waimart\Core\App;
use Waimart\Core\Database;
use Waimart\Core\Settings;

/**
 * Customer segments from measured behaviour, with thresholds set under Marketing → Settings. Each segment is a rule
 * over customer accounts (alias u), evaluated live, so membership is never stale. Being in a segment never sends
 * anything by itself: automations check consent, limits and eligibility before every message.
 */
final class Segments
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? App::db();
    }

    /** @return array<string,array{0:string,1:string,2:string}> code => [name, description, SQL condition on u] */
    public function definitions(): array
    {
        $new = max(1, Settings::int('segment_new_days', 30));
        $inactive = max(7, Settings::int('segment_inactive_days', 60));
        $views = max(2, Settings::int('segment_frequent_views', 10));
        $high = (float) (Settings::get('segment_high_value', '20000') ?: 20000);
        $orders = "(SELECT COUNT(*) FROM orders o WHERE o.user_id = u.id AND o.status NOT IN ('cancelled','expired'))";
        $lastOrder = "(SELECT MAX(o.placed_at) FROM orders o WHERE o.user_id = u.id AND o.status NOT IN ('cancelled','expired'))";
        $spent = "(SELECT COALESCE(SUM(o.grand_total), 0) FROM orders o WHERE o.user_id = u.id AND o.payment_status IN ('verified','cod_collected'))";
        $viewed30 = "(SELECT COUNT(*) FROM shop_events e WHERE e.user_id = u.id AND e.event = 'product_viewed' AND e.occurred_at > NOW() - INTERVAL 30 DAY)";
        return [
            'new' => ['Newly registered', "Created an account in the last $new days.", "u.created_at > NOW() - INTERVAL $new DAY"],
            'browsing' => ['Browsing, not buying yet', 'Viewed products in the last 30 days but has never ordered.', "$viewed30 > 0 AND $orders = 0"],
            'frequent_viewers' => ['Frequent viewers', "Viewed at least $views products in the last 30 days.", "$viewed30 >= $views"],
            'wishlist' => ['Wishlist users', 'Has saved products.', 'EXISTS (SELECT 1 FROM wishlist_items w WHERE w.user_id = u.id)'],
            'cart_abandoners' => ['Cart abandoners', 'Items left in the cart for over an hour, with no order since.',
                "EXISTS (SELECT 1 FROM carts c JOIN cart_items ci ON ci.cart_id = c.id WHERE c.user_id = u.id AND c.updated_at < NOW() - INTERVAL 1 HOUR
                   AND NOT EXISTS (SELECT 1 FROM orders o WHERE o.user_id = u.id AND o.placed_at > c.updated_at))"],
            'first_time' => ['First-time buyers', 'Exactly one order.', "$orders = 1"],
            'recent_buyers' => ['Recently purchased', 'Ordered in the last 30 days.', "$lastOrder > NOW() - INTERVAL 30 DAY"],
            'repeat' => ['Repeat customers', 'Two or more orders.', "$orders >= 2"],
            'high_value' => ['High-value customers', 'Paid orders worth at least KSh ' . number_format($high) . ' in total.', "$spent >= " . number_format($high, 2, '.', '')],
            'inactive' => ['Inactive buyers', "Ordered before, but not in the last $inactive days.", "$lastOrder IS NOT NULL AND $lastOrder < NOW() - INTERVAL $inactive DAY"],
            'declining' => ['Engagement declining', 'Browsed 31–90 days ago but not at all in the last 30 days.',
                "EXISTS (SELECT 1 FROM shop_events e WHERE e.user_id = u.id AND e.occurred_at BETWEEN NOW() - INTERVAL 90 DAY AND NOW() - INTERVAL 30 DAY)
                 AND NOT EXISTS (SELECT 1 FROM shop_events e WHERE e.user_id = u.id AND e.occurred_at > NOW() - INTERVAL 30 DAY)"],
            'email_ok' => ['Opted in to marketing email', 'Agreed to receive marketing emails.', 'u.marketing_email = 1'],
            'sms_ok' => ['Opted in to marketing SMS', 'Agreed to receive marketing SMS (and has a phone number).', "u.marketing_sms = 1 AND u.phone IS NOT NULL AND u.phone <> ''"],
            'opted_out' => ['Not opted in', 'No marketing consent on any channel: never sent marketing.', 'u.marketing_email = 0 AND u.marketing_sms = 0'],
        ];
    }

    /** @return array<string,array{name:string,description:string,count:int}> */
    public function summary(): array
    {
        $out = [];
        foreach ($this->definitions() as $code => [$name, $desc, $sql]) {
            $out[$code] = ['name' => $name, 'description' => $desc,
                'count' => (int) $this->db->value("SELECT COUNT(*) FROM users u WHERE u.is_staff = 0 AND u.status = 'active' AND ($sql)")];
        }
        return $out;
    }

    /** Customers in a segment (with their consent), newest first. @return array{rows:list<array<string,mixed>>,total:int} */
    public function members(string $code, int $page = 1, int $per = 25): array
    {
        $def = $this->definitions()[$code] ?? null;
        if ($def === null) {
            return ['rows' => [], 'total' => 0];
        }
        $where = "u.is_staff = 0 AND u.status = 'active' AND ({$def[2]})";
        $total = (int) $this->db->value("SELECT COUNT(*) FROM users u WHERE $where");
        $rows = $this->db->all("SELECT u.id, u.first_name, u.last_name, u.email, u.phone, u.created_at, u.marketing_email, u.marketing_sms,
                (SELECT COUNT(*) FROM orders o WHERE o.user_id = u.id AND o.status NOT IN ('cancelled','expired')) AS orders,
                (SELECT MAX(o.placed_at) FROM orders o WHERE o.user_id = u.id) AS last_order,
                (SELECT MAX(e.occurred_at) FROM shop_events e WHERE e.user_id = u.id) AS last_activity
              FROM users u WHERE $where ORDER BY u.id DESC LIMIT $per OFFSET " . (max(1, $page) - 1) * $per);
        return ['rows' => $rows, 'total' => $total];
    }

    /** User ids in a segment (for audiences), capped. @return list<int> */
    public function userIds(string $code, int $limit = 5000): array
    {
        $def = $this->definitions()[$code] ?? null;
        return $def === null ? [] : array_map('intval', array_column($this->db->all(
            "SELECT u.id FROM users u WHERE u.is_staff = 0 AND u.status = 'active' AND ({$def[2]}) LIMIT " . max(1, $limit)), 'id'));
    }

    /** Categories customers buy most (buyers per category, last 180 days). @return list<array{name:string,buyers:int,orders:int}> */
    public function categoryBuyers(int $limit = 10): array
    {
        return $this->db->all("SELECT c.name, COUNT(DISTINCT o.user_id) AS buyers, COUNT(DISTINCT o.id) AS orders
              FROM orders o JOIN order_items oi ON oi.order_id = o.id JOIN products p ON p.id = oi.product_id JOIN categories c ON c.id = p.category_id
             WHERE o.user_id IS NOT NULL AND o.status NOT IN ('cancelled','expired') AND o.placed_at > NOW() - INTERVAL 180 DAY
             GROUP BY c.id, c.name ORDER BY buyers DESC LIMIT " . max(1, $limit));
    }

    /** Guests in the last 30 days (distinct browsers that allowed tracking, never identified). */
    public function newVisitors(): int
    {
        return (int) $this->db->value("SELECT COUNT(DISTINCT visitor_id) FROM shop_events WHERE user_id IS NULL AND visitor_id IS NOT NULL AND occurred_at > NOW() - INTERVAL 30 DAY");
    }
}
