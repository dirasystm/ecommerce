<?php

declare(strict_types=1);

namespace Waimart\Services\Marketing;

use Waimart\Core\App;
use Waimart\Core\Database;

/**
 * Marketing measurements. Every figure has one definition (DEFINITIONS) and is computed from recorded events:
 * nothing is estimated except where the label says so, and nothing is invented (impressions from social platforms are
 * not available, so they are shown as such; return on ad spend appears only when the real cost was entered).
 *
 * Attribution: "last" credits the latest promotional link clicked before the action (default); "first" credits the
 * first link in the window. Each order counts once. "Paid" follows the shop's own payment workflow (M-Pesa verified,
 * cash on delivery collected); cancelled and refunded orders are reported separately and never counted as sales.
 */
final class Analytics
{
    public const DEFINITIONS = [
        'clicks' => 'Clicks on promotional links by people (bots and link-preview crawlers excluded).',
        'visitors' => 'Estimated unique visitors: different browsers that clicked (one person on two devices counts twice).',
        'leads' => 'People who gave contact details (newsletter) or created an account in the period.',
        'registrations' => 'New customer accounts in the period (attributed: after clicking a promotional link).',
        'carts' => 'Add-to-cart actions.',
        'checkouts' => 'Checkout pages opened (once per visitor per half hour).',
        'orders' => 'Orders placed (each order once).',
        'paid' => 'Of those orders, how many are now paid: M-Pesa verified or cash on delivery collected.',
        'sales' => 'Value of the paid orders (order totals incl. delivery). Cancelled and refunded orders are not included.',
        'conversion' => 'Attributed orders ÷ estimated unique visitors from promotional links.',
        'email_rate' => 'Marketing emails accepted by the mail server ÷ marketing emails attempted (opens are not tracked).',
        'sms_rate' => 'Marketing SMS reported delivered by Talksasa ÷ marketing SMS sent (where delivery reports were checked).',
        'unsub_rate' => 'Unsubscribes ÷ marketing messages sent in the period.',
        'roas' => 'Return on ad spend: paid attributed sales ÷ the cost you entered (only campaigns with a cost).',
    ];

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? App::db();
    }

    /**
     * Date range for a preset or custom dates, in Kenya time, as whole days.
     * @return array{0:string,1:string,2:string} from, to (inclusive datetimes), label
     */
    public static function range(string $preset, ?string $from = null, ?string $to = null): array
    {
        $tz = new \DateTimeZone('Africa/Nairobi');
        $today = new \DateTimeImmutable('today', $tz);
        [$a, $b, $label] = match ($preset) {
            'today' => [$today, $today, 'Today'],
            'yesterday' => [$today->modify('-1 day'), $today->modify('-1 day'), 'Yesterday'],
            '7d' => [$today->modify('-6 days'), $today, 'Last 7 days'],
            'this_month' => [$today->modify('first day of this month'), $today, 'This month'],
            'last_month' => [$today->modify('first day of last month'), $today->modify('last day of last month'), 'Previous month'],
            'this_year' => [$today->setDate((int) $today->format('Y'), 1, 1), $today, 'This year'],
            'custom' => [self::day($from, $tz) ?? $today->modify('-29 days'), self::day($to, $tz) ?? $today, 'Custom'],
            default => [$today->modify('-29 days'), $today, 'Last 30 days'],
        };
        if ($b < $a) {
            [$a, $b] = [$b, $a];
        }
        return [$a->format('Y-m-d 00:00:00'), $b->format('Y-m-d 23:59:59'), $label];
    }

    private static function day(?string $v, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        return $v !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? new \DateTimeImmutable($v, $tz) : null;
    }

    /** Overview cards. @return array<string,mixed> */
    public function overview(string $from, string $to, string $model = 'last'): array
    {
        $col = $model === 'first' ? 'first_link_id' : 'link_id';
        $p = [$from, $to];
        $clicks = $this->db->one('SELECT COUNT(*) AS clicks, COUNT(DISTINCT visitor_id) AS visitors, SUM(via = \'qr\') AS qr FROM campaign_clicks WHERE is_bot = 0 AND occurred_at BETWEEN ? AND ?', $p);
        $ev = fn (string $event, bool $attributed) => (int) $this->db->value("SELECT COUNT(*) FROM shop_events WHERE event = ? AND occurred_at BETWEEN ? AND ?" . ($attributed ? " AND $col IS NOT NULL" : ''), [$event, ...$p]);
        $orders = $this->orders($from, $to, $col, null);
        $ordersAttr = $this->orders($from, $to, $col, 'any');
        $mk = $this->db->one("SELECT SUM(channel = 'email') AS emails, SUM(channel = 'email' AND status = 'sent') AS emails_ok, SUM(channel = 'email' AND status = 'failed') AS emails_failed,
                SUM(channel = 'sms' AND status = 'sent') AS sms_sent, SUM(status IN ('sent','logged')) AS sent_total
              FROM marketing_messages WHERE created_at BETWEEN ? AND ?", $p);
        $smsDelivered = (int) $this->db->value("SELECT COUNT(*) FROM marketing_messages m JOIN notifications n ON n.id = m.notification_id
              WHERE m.channel = 'sms' AND m.status = 'sent' AND n.provider_status LIKE 'deliver%' AND m.created_at BETWEEN ? AND ?", $p);
        $unsubs = $ev('unsubscribed', false);
        $cost = $this->db->one("SELECT SUM(cost) AS cost, COUNT(cost) AS with_cost FROM marketing_campaigns WHERE cost IS NOT NULL
              AND (starts_on IS NULL OR starts_on <= ?) AND (ends_on IS NULL OR ends_on >= ?)", [substr($to, 0, 10), substr($from, 0, 10)]);
        $paidForCost = $cost['with_cost'] ? (float) $this->db->value("SELECT COALESCE(SUM(o.grand_total), 0) FROM shop_events e JOIN orders o ON o.id = e.order_id
              JOIN campaign_links l ON l.id = e.$col JOIN marketing_campaigns c ON c.id = l.campaign_id
              WHERE e.event = 'order_placed' AND c.cost IS NOT NULL AND o.payment_status IN ('verified','cod_collected') AND e.occurred_at BETWEEN ? AND ?", $p) : 0.0;
        $visitors = (int) $clicks['visitors'];
        return [
            'campaigns' => (int) $this->db->value('SELECT COUNT(*) FROM marketing_campaigns'),
            'active' => (int) $this->db->value("SELECT COUNT(*) FROM marketing_campaigns WHERE status = 'active' AND (ends_on IS NULL OR ends_on >= CURDATE())"),
            'clicks' => (int) $clicks['clicks'], 'visitors' => $visitors, 'qr' => (int) $clicks['qr'],
            'leads' => (int) $this->db->value('SELECT COUNT(*) FROM marketing_leads WHERE first_seen_at BETWEEN ? AND ?', $p),
            'registrations' => (int) $this->db->value('SELECT COUNT(*) FROM users WHERE is_staff = 0 AND created_at BETWEEN ? AND ?', $p),
            'registrations_attr' => $ev('registered', true),
            'carts' => $ev('cart_added', false), 'carts_attr' => $ev('cart_added', true),
            'checkouts' => $ev('checkout_started', false), 'checkouts_attr' => $ev('checkout_started', true),
            'orders' => $orders['placed'], 'paid' => $orders['paid'], 'sales' => $orders['sales'], 'cancelled' => $orders['cancelled'], 'refunded' => $orders['refunded'],
            'orders_attr' => $ordersAttr['placed'], 'paid_attr' => $ordersAttr['paid'], 'sales_attr' => $ordersAttr['sales'],
            'conversion' => $visitors > 0 ? $ordersAttr['placed'] / $visitors : null,
            'email_rate' => (int) $mk['emails_ok'] + (int) $mk['emails_failed'] > 0 ? (int) $mk['emails_ok'] / ((int) $mk['emails_ok'] + (int) $mk['emails_failed']) : null,
            'sms_rate' => (int) $mk['sms_sent'] > 0 ? $smsDelivered / (int) $mk['sms_sent'] : null,
            'unsub_rate' => (int) $mk['sent_total'] > 0 ? $unsubs / (int) $mk['sent_total'] : null, 'unsubs' => $unsubs, 'messages' => (int) $mk['sent_total'],
            'cost' => $cost['with_cost'] ? (float) $cost['cost'] : null, 'roas' => $cost['with_cost'] && (float) $cost['cost'] > 0 ? $paidForCost / (float) $cost['cost'] : null,
        ];
    }

    /**
     * Orders placed in the range (each once), optionally only those credited to links.
     * @param 'any'|int|list<int>|null $links null: all orders; 'any': attributed to any link; ids: those links
     * @return array{placed:int,paid:int,sales:float,cancelled:int,refunded:int}
     */
    public function orders(string $from, string $to, string $col, mixed $links): array
    {
        $where = "e.event = 'order_placed' AND e.occurred_at BETWEEN ? AND ?";
        $params = [$from, $to];
        if ($links === 'any') {
            $where .= " AND e.$col IS NOT NULL";
        } elseif (is_array($links)) {
            if (!$links) {
                return ['placed' => 0, 'paid' => 0, 'sales' => 0.0, 'cancelled' => 0, 'refunded' => 0];
            }
            $where .= " AND e.$col IN (" . implode(',', array_fill(0, count($links), '?')) . ')';
            array_push($params, ...$links);
        }
        $r = $this->db->one("SELECT COUNT(DISTINCT o.id) AS placed,
                COUNT(DISTINCT CASE WHEN o.payment_status IN ('verified','cod_collected') THEN o.id END) AS paid,
                COALESCE(SUM(CASE WHEN o.payment_status IN ('verified','cod_collected') THEN o.grand_total END), 0) AS sales,
                COUNT(DISTINCT CASE WHEN o.status IN ('cancelled','expired') THEN o.id END) AS cancelled,
                COUNT(DISTINCT CASE WHEN o.payment_status IN ('refund_due','refunded') OR o.status = 'returned' THEN o.id END) AS refunded
              FROM (SELECT DISTINCT e.order_id FROM shop_events e WHERE $where) x JOIN orders o ON o.id = x.order_id", $params);
        return ['placed' => (int) $r['placed'], 'paid' => (int) $r['paid'], 'sales' => (float) $r['sales'], 'cancelled' => (int) $r['cancelled'], 'refunded' => (int) $r['refunded']];
    }

    /**
     * Funnel for promotional traffic (all links, or the given ones). Impressions are not available from social platforms.
     * @param list<int>|null $links
     * @return list<array{stage:string,count:?int}>
     */
    public function funnel(string $from, string $to, ?array $links = null, string $model = 'last'): array
    {
        $col = $model === 'first' ? 'first_link_id' : 'link_id';
        $in = $links === null ? "IS NOT NULL" : ($links ? 'IN (' . implode(',', array_map('intval', $links)) . ')' : 'IN (0)');
        $clickIn = $links === null ? '' : ' AND link_id ' . ($links ? 'IN (' . implode(',', array_map('intval', $links)) . ')' : 'IN (0)');
        $p = [$from, $to];
        $count = fn (string $event, string $distinct = 'id') => (int) $this->db->value("SELECT COUNT(DISTINCT $distinct) FROM shop_events WHERE event = ? AND $col $in AND occurred_at BETWEEN ? AND ?", [$event, ...$p]);
        $orders = $this->orders($from, $to, $col, $links === null ? 'any' : $links);
        return [
            ['stage' => 'Impressions', 'count' => null],
            ['stage' => 'Clicks', 'count' => (int) $this->db->value("SELECT COUNT(*) FROM campaign_clicks WHERE is_bot = 0$clickIn AND occurred_at BETWEEN ? AND ?", $p)],
            ['stage' => 'Unique visitors (est.)', 'count' => (int) $this->db->value("SELECT COUNT(DISTINCT visitor_id) FROM campaign_clicks WHERE is_bot = 0$clickIn AND occurred_at BETWEEN ? AND ?", $p)],
            ['stage' => 'Viewed a product', 'count' => $count('product_viewed', 'COALESCE(user_id, visitor_id)')],
            ['stage' => 'Signed up (lead or account)', 'count' => $count('registered') + $count('lead_captured')],
            ['stage' => 'Added to cart', 'count' => $count('cart_added', 'COALESCE(user_id, visitor_id)')],
            ['stage' => 'Started checkout', 'count' => $count('checkout_started', 'COALESCE(user_id, visitor_id)')],
            ['stage' => 'Placed an order', 'count' => $orders['placed']],
            ['stage' => 'Paid', 'count' => $orders['paid']],
        ];
    }

    /** The step with the biggest measured drop (needs at least 20 at the earlier step). @return array{from:string,to:string,lost:float}|null */
    public static function biggestDrop(array $funnel): ?array
    {
        $best = null;
        for ($i = 1; $i < count($funnel) - 1; $i++) {
            $a = $funnel[$i]['count'];
            $b = $funnel[$i + 1]['count'];
            if ($a === null || $b === null || $a < 20 || $funnel[$i + 1]['stage'] === 'Signed up (lead or account)') {
                continue;
            }
            $lost = 1 - $b / $a;
            if ($best === null || $lost > $best['lost']) {
                $best = ['from' => $funnel[$i]['stage'], 'to' => $funnel[$i + 1]['stage'], 'lost' => $lost];
            }
        }
        return $best;
    }

    /**
     * Campaign table with measured results.
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function campaigns(string $from, string $to, string $model, string $search, string $sort, int $page, int $per): array
    {
        $col = $model === 'first' ? 'first_link_id' : 'link_id';
        $where = '1=1';
        $params = [];
        if (($search = trim(mb_substr($search, 0, 100))) !== '') {
            $where = '(c.name LIKE ? OR c.platform LIKE ? OR c.channel LIKE ?)';
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $params = [$like, $like, $like];
        }
        $total = (int) $this->db->value("SELECT COUNT(*) FROM marketing_campaigns c WHERE $where", $params);
        $orderBy = match ($sort) {
            'clicks' => 'clicks DESC', 'orders' => 'orders DESC', 'sales' => 'sales DESC', 'name' => 'c.name ASC', 'conversion' => 'conversion DESC', default => 'c.created_at DESC',
        };
        $p = [$from, $to];
        $rows = $this->db->all("SELECT c.*,
                (SELECT COUNT(*) FROM campaign_clicks k JOIN campaign_links l ON l.id = k.link_id WHERE l.campaign_id = c.id AND k.is_bot = 0 AND k.occurred_at BETWEEN ? AND ?) AS clicks,
                (SELECT COUNT(DISTINCT k.visitor_id) FROM campaign_clicks k JOIN campaign_links l ON l.id = k.link_id WHERE l.campaign_id = c.id AND k.is_bot = 0 AND k.occurred_at BETWEEN ? AND ?) AS visitors,
                (SELECT COUNT(*) FROM marketing_leads ml JOIN campaign_links l ON l.id = ml.last_link_id WHERE l.campaign_id = c.id AND ml.first_seen_at BETWEEN ? AND ?) AS leads,
                (SELECT COUNT(*) FROM shop_events e JOIN campaign_links l ON l.id = e.$col WHERE l.campaign_id = c.id AND e.event = 'registered' AND e.occurred_at BETWEEN ? AND ?) AS registrations,
                (SELECT COUNT(*) FROM shop_events e JOIN campaign_links l ON l.id = e.$col WHERE l.campaign_id = c.id AND e.event = 'cart_added' AND e.occurred_at BETWEEN ? AND ?) AS carts,
                (SELECT COUNT(DISTINCT e.order_id) FROM shop_events e JOIN campaign_links l ON l.id = e.$col WHERE l.campaign_id = c.id AND e.event = 'order_placed' AND e.occurred_at BETWEEN ? AND ?) AS orders,
                (SELECT COUNT(DISTINCT o.id) FROM shop_events e JOIN campaign_links l ON l.id = e.$col JOIN orders o ON o.id = e.order_id
                  WHERE l.campaign_id = c.id AND e.event = 'order_placed' AND o.payment_status IN ('verified','cod_collected') AND e.occurred_at BETWEEN ? AND ?) AS paid,
                (SELECT COALESCE(SUM(o.grand_total), 0) FROM (SELECT DISTINCT e.order_id, l.campaign_id FROM shop_events e JOIN campaign_links l ON l.id = e.$col
                  WHERE e.event = 'order_placed' AND e.occurred_at BETWEEN ? AND ?) x JOIN orders o ON o.id = x.order_id
                  WHERE x.campaign_id = c.id AND o.payment_status IN ('verified','cod_collected')) AS sales,
                (SELECT COUNT(*) FROM campaign_links l WHERE l.campaign_id = c.id) AS links
              FROM marketing_campaigns c WHERE $where", array_merge($p, $p, $p, $p, $p, $p, $p, $p, $params));
        foreach ($rows as &$r) {
            $r['conversion'] = (int) $r['visitors'] > 0 ? (int) $r['orders'] / (int) $r['visitors'] : null;
            $r['roas'] = $r['cost'] !== null && (float) $r['cost'] > 0 ? (float) $r['sales'] / (float) $r['cost'] : null;
        }
        unset($r);
        $key = explode(' ', $orderBy)[0];
        usort($rows, function ($a, $b) use ($key, $orderBy) {
            $k = $key === 'c.name' ? 'name' : ($key === 'c.created_at' ? 'created_at' : $key);
            $cmp = $a[$k] <=> $b[$k];
            return str_ends_with($orderBy, 'DESC') ? -$cmp : $cmp;
        });
        return ['rows' => array_slice($rows, ($page - 1) * $per, $per), 'total' => $total];
    }

    /** Per link (variant) of a campaign. @return list<array<string,mixed>> */
    public function links(int $campaignId, string $from, string $to, string $model): array
    {
        $col = $model === 'first' ? 'first_link_id' : 'link_id';
        $p = [$from, $to];
        $rows = $this->db->all("SELECT l.*,
                (SELECT COUNT(*) FROM campaign_clicks k WHERE k.link_id = l.id AND k.is_bot = 0 AND k.occurred_at BETWEEN ? AND ?) AS clicks,
                (SELECT COUNT(DISTINCT k.visitor_id) FROM campaign_clicks k WHERE k.link_id = l.id AND k.is_bot = 0 AND k.occurred_at BETWEEN ? AND ?) AS visitors,
                (SELECT COUNT(*) FROM campaign_clicks k WHERE k.link_id = l.id AND k.is_bot = 0 AND k.via = 'qr' AND k.occurred_at BETWEEN ? AND ?) AS qr,
                (SELECT COUNT(*) FROM shop_events e WHERE e.$col = l.id AND e.event = 'cart_added' AND e.occurred_at BETWEEN ? AND ?) AS carts,
                (SELECT COUNT(DISTINCT e.order_id) FROM shop_events e WHERE e.$col = l.id AND e.event = 'order_placed' AND e.occurred_at BETWEEN ? AND ?) AS orders
              FROM campaign_links l WHERE l.campaign_id = ? ORDER BY l.id", array_merge($p, $p, $p, $p, $p, [$campaignId]));
        foreach ($rows as &$r) {
            $o = $this->orders($from, $to, $col, [(int) $r['id']]);
            $r['paid'] = $o['paid'];
            $r['sales'] = $o['sales'];
            $r['conversion'] = (int) $r['visitors'] > 0 ? (int) $r['orders'] / (int) $r['visitors'] : null;
        }
        return $rows;
    }

    /** Results by channel / platform / traffic source. @return list<array<string,mixed>> */
    public function channels(string $from, string $to, string $model): array
    {
        $col = $model === 'first' ? 'first_link_id' : 'link_id';
        $out = [];
        foreach ($this->db->all('SELECT DISTINCT c.channel, COALESCE(c.platform, \'\') AS platform FROM marketing_campaigns c') as $g) {
            $ids = array_map('intval', array_column($this->db->all('SELECT l.id FROM campaign_links l JOIN marketing_campaigns c ON c.id = l.campaign_id WHERE c.channel = ? AND COALESCE(c.platform, \'\') = ?',
                [$g['channel'], $g['platform']]), 'id'));
            if (!$ids) {
                continue;
            }
            $in = implode(',', $ids);
            $clicks = $this->db->one("SELECT COUNT(*) AS c, COUNT(DISTINCT visitor_id) AS v FROM campaign_clicks WHERE is_bot = 0 AND link_id IN ($in) AND occurred_at BETWEEN ? AND ?", [$from, $to]);
            $o = $this->orders($from, $to, $col, $ids);
            $out[] = ['channel' => $g['channel'], 'platform' => $g['platform'], 'clicks' => (int) $clicks['c'], 'visitors' => (int) $clicks['v'],
                      'orders' => $o['placed'], 'paid' => $o['paid'], 'sales' => $o['sales'], 'conversion' => (int) $clicks['v'] > 0 ? $o['placed'] / (int) $clicks['v'] : null];
        }
        usort($out, fn ($a, $b) => [$b['sales'], $b['clicks']] <=> [$a['sales'], $a['clicks']]);
        return $out;
    }

    /** Advertised products: clicks and orders containing them. @return list<array<string,mixed>> */
    public function products(string $from, string $to, string $model): array
    {
        $col = $model === 'first' ? 'first_link_id' : 'link_id';
        return $this->db->all("SELECT p.id, p.name, p.slug,
                (SELECT COUNT(*) FROM campaign_clicks k JOIN campaign_links l ON l.id = k.link_id WHERE l.destination_type = 'product' AND l.destination_id = p.id AND k.is_bot = 0 AND k.occurred_at BETWEEN ? AND ?) AS clicks,
                (SELECT COUNT(DISTINCT e.order_id) FROM shop_events e JOIN order_items oi ON oi.order_id = e.order_id
                  WHERE e.event = 'order_placed' AND e.$col IS NOT NULL AND oi.product_id = p.id AND e.occurred_at BETWEEN ? AND ?) AS orders,
                (SELECT COUNT(*) FROM marketing_leads ml JOIN campaign_links l ON l.id = ml.last_link_id WHERE l.destination_type = 'product' AND l.destination_id = p.id AND ml.first_seen_at BETWEEN ? AND ?) AS leads
              FROM products p WHERE p.id IN (SELECT destination_id FROM campaign_links WHERE destination_type = 'product')
              ORDER BY clicks DESC LIMIT 10", [$from, $to, $from, $to, $from, $to]);
    }

    /** Recommendation placements: shown, clicks, carts, orders and value from them. @return list<array<string,mixed>> */
    public function placements(string $from, string $to): array
    {
        $out = [];
        foreach (Recommender::PLACEMENTS as $code => [, $label]) {
            $shown = (int) $this->db->value('SELECT COALESCE(SUM(shown), 0) FROM rec_impressions WHERE placement = ? AND day BETWEEN ? AND ?', [$code, substr($from, 0, 10), substr($to, 0, 10)]);
            $e = $this->db->one("SELECT SUM(event = 'rec_click') AS clicks, SUM(event = 'cart_added') AS carts, COUNT(DISTINCT CASE WHEN event = 'rec_order' THEN order_id END) AS orders,
                    COALESCE(SUM(CASE WHEN event = 'rec_order' THEN amount END), 0) AS value, SUM(event = 'rec_dismissed') AS dismissed
                  FROM shop_events WHERE placement = ? AND occurred_at BETWEEN ? AND ?", [$code, $from, $to]);
            $out[] = ['code' => $code, 'label' => $label, 'shown' => $shown, 'clicks' => (int) $e['clicks'], 'carts' => (int) $e['carts'], 'orders' => (int) $e['orders'],
                      'value' => (float) $e['value'], 'dismissed' => (int) $e['dismissed'], 'ctr' => $shown > 0 ? (int) $e['clicks'] / $shown : null];
        }
        return $out;
    }

    /**
     * Plain-language observations backed by the numbers (no claims of cause, sample sizes stated).
     * @return list<array{tone:string,title:string,text:string,action:string}>
     */
    public function insights(string $from, string $to, string $model): array
    {
        $rows = $this->campaigns($from, $to, $model, '', 'clicks', 1, 500)['rows'];
        $out = [];
        $small = fn (int $n) => $n < 30 ? ' The sample is small (' . $n . ' visitors), so treat this as an early signal.' : '';
        foreach ($rows as $r) {
            $v = (int) $r['visitors'];
            if ((int) $r['clicks'] >= 20 && (int) $r['carts'] === 0) {
                $out[] = ['tone' => 'warning', 'title' => '“' . $r['name'] . '”: visits but no add-to-cart',
                    'text' => $r['clicks'] . ' clicks and no product added to a cart in this period.' . $small($v),
                    'action' => 'Check that the landing page shows what the ad promised, and review the price and the product image.'];
            } elseif ($v >= 10 && (int) $r['carts'] > 0 && (int) $r['orders'] === 0) {
                $out[] = ['tone' => 'warning', 'title' => '“' . $r['name'] . '”: carts but no orders',
                    'text' => $r['carts'] . ' add-to-cart actions from ' . $v . ' visitors, but no orders yet.' . $small($v),
                    'action' => 'Look at delivery fees for these customers, payment options, and whether the cart reminder automation is on.'];
            }
            if ($r['status'] === 'active' && (int) $r['clicks'] < 5 && $r['created_at'] < date('Y-m-d H:i:s', time() - 7 * 86400)) {
                $out[] = ['tone' => 'info', 'title' => '“' . $r['name'] . '”: very little traffic',
                    'text' => 'Only ' . (int) $r['clicks'] . ' clicks in this period although it has been running for over a week.',
                    'action' => 'Try a different headline or image, post it at a different time, or pause it.'];
            }
        }
        $best = array_values(array_filter($rows, fn ($r) => (int) $r['visitors'] >= 30 && $r['conversion'] !== null));
        if (count($best) >= 2) {
            usort($best, fn ($a, $b) => $b['conversion'] <=> $a['conversion']);
            $a = $best[0];
            $z = end($best);
            if ($a['conversion'] > 0 && $a['conversion'] >= 2 * max(0.0001, (float) $z['conversion'])) {
                $out[] = ['tone' => 'success', 'title' => '“' . $a['name'] . '” converts best',
                    'text' => sprintf('%.1f%% of its visitors ordered, against %.1f%% for “%s”. This is what was measured, not proof that the campaign caused it.', $a['conversion'] * 100, (float) $z['conversion'] * 100, $z['name']),
                    'action' => 'Consider giving it more of the budget, or reusing its creative in a new A/B test.'];
            }
        }
        $f = $this->funnel($from, $to, null, $model);
        if (($d = self::biggestDrop($f)) !== null && $d['lost'] >= 0.6) {
            $out[] = ['tone' => 'info', 'title' => 'Biggest drop: ' . strtolower($d['from']) . ' → ' . strtolower($d['to']),
                'text' => sprintf('%.0f%% of promotional visitors do not go from “%s” to “%s”.', $d['lost'] * 100, $d['from'], $d['to']),
                'action' => 'This is the step to improve first.'];
        }
        $ov = $this->overview($from, $to, $model);
        if ($ov['messages'] >= 50 && $ov['unsub_rate'] !== null && $ov['unsub_rate'] > 0.02) {
            $out[] = ['tone' => 'warning', 'title' => 'Unsubscribes are high',
                'text' => sprintf('%.1f%% of marketing messages led to an unsubscribe (%d of %d).', $ov['unsub_rate'] * 100, $ov['unsubs'], $ov['messages']),
                'action' => 'Lower the weekly limit, make messages more relevant, or send less often.'];
        }
        return $out;
    }
}
