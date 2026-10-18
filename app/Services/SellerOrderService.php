<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Database;
use Waimart\Core\Money;

/**
 * Seller sub-orders: one per seller represented in a customer order.
 *
 * Money recorded per seller order (all from the order-time snapshot):
 *  - items_subtotal   merchandise at the prices charged (incl. any flash price)
 *  - discount_share   the order's coupon discount allocated pro rata by merchandise value (rounding remainder on the
 *                     last group). Coupons are platform-funded by default, so seller_discount = 0 and commission is
 *                     calculated on the full merchandise value.
 *  - commission_*     sum of the per-item commission snapshots (CommissionService)
 *  - seller_net       items_subtotal − commission_total − seller_discount (earnings before refunds/adjustments)
 *  - delivery_fee_share 0: under the client's policy delivery fees belong to the platform.
 * Ledger postings (earnings) happen in Phase 8; nothing here is money received.
 */
final class SellerOrderService
{
    /** Parent status → seller-order status, used while the parent order still drives fulfilment and for back-fill. */
    public const PARENT_TO_SELLER = [
        'awaiting_payment' => 'pending_acceptance', 'awaiting_verification' => 'pending_acceptance',
        'pending_confirmation' => 'pending_acceptance', 'requires_resolution' => 'pending_acceptance',
        'confirmed' => 'accepted', 'processing' => 'processing', 'packed' => 'packed', 'dispatched' => 'dispatched',
        'out_for_delivery' => 'out_for_delivery', 'delivered' => 'delivered', 'ready_for_collection' => 'ready_for_collection',
        'collected' => 'collected', 'cancelled' => 'cancelled', 'expired' => 'cancelled',
    ];

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? App::db();
    }

    /**
     * Step 1 of placement (inside the order transaction, before items): one seller order per seller in the order,
     * with its merchandise subtotal and allocated discount.
     * @param list<array{seller_id:int,line_total:string}> $lines
     * @return array<int,int> seller id => seller order id
     */
    public function open(int $orderId, array $lines, ?int $actorUserId): array
    {
        if (!$this->db->inTransaction()) {
            throw new \LogicException('Seller orders must be created inside the order transaction.');
        }
        $order = (array) $this->db->one('SELECT id, order_number, subtotal, discount_total FROM orders WHERE id = ?', [$orderId]);
        $groups = [];
        foreach ($lines as $l) {
            $sid = (int) $l['seller_id'];
            $groups[$sid] = Money::add($groups[$sid] ?? '0.00', (string) $l['line_total']);
        }
        $ids = [];
        $allocated = '0.00';
        $n = 0;
        foreach ($groups as $sellerId => $subtotal) {
            $n++;
            $share = $n === count($groups)
                ? Money::sub((string) $order['discount_total'], $allocated)
                : (Money::cmp((string) $order['subtotal'], '0') > 0
                    ? Money::round(bcdiv(bcmul((string) $order['discount_total'], $subtotal, 6), (string) $order['subtotal'], 6))
                    : '0.00');
            $allocated = Money::add($allocated, $share);
            $ids[$sellerId] = $this->db->insert('seller_orders', [
                'order_id' => $orderId, 'seller_id' => $sellerId, 'sub_number' => $order['order_number'] . '-' . $n,
                'status' => 'pending_acceptance', 'items_subtotal' => $subtotal, 'discount_share' => $share,
                'commission_base' => $subtotal, 'seller_net' => $subtotal,
            ]);
            $this->db->insert('seller_order_history', [
                'seller_order_id' => $ids[$sellerId], 'from_status' => null, 'to_status' => 'pending_acceptance',
                'actor_type' => 'customer', 'actor_user_id' => $actorUserId, 'note' => 'Order placed',
            ]);
        }
        return $ids;
    }

    /**
     * Step 2: commission snapshot for one order item (columns for order_items).
     * @return array{commission_rate:string,commission_amount:string,seller_net_amount:string,commission_rule_id:?int}
     */
    public function itemSnapshot(int $sellerId, int $productId, string $lineTotal): array
    {
        $category = (int) $this->db->value('SELECT category_id FROM products WHERE id = ?', [$productId]);
        $rate = (new CommissionService($this->db))->rateFor($sellerId, $category);
        $amount = Money::percent($lineTotal, $rate['rate']);
        return ['commission_rate' => $rate['rate'], 'commission_amount' => $amount,
                'seller_net_amount' => Money::sub($lineTotal, $amount), 'commission_rule_id' => $rate['rule_id']];
    }

    /** Step 3 (after items): seller-order commission totals from the item snapshots. */
    public function close(int $orderId): void
    {
        $this->db->exec(
            'UPDATE seller_orders so
               JOIN (SELECT seller_order_id, SUM(line_total) AS base, SUM(commission_amount) AS c FROM order_items WHERE order_id = ? GROUP BY seller_order_id) t
                 ON t.seller_order_id = so.id
                SET so.commission_base = t.base, so.commission_total = t.c, so.seller_net = so.items_subtotal - t.c - so.seller_discount
              WHERE so.order_id = ?',
            [$orderId, $orderId]
        );
    }

    /**
     * Interim bridge until sellers drive fulfilment themselves (Phase 7): keeps every open seller order in step with
     * the parent order's status, and tells sellers when an order is confirmed (paid or COD confirmed) so they can
     * prepare it. Runs inside the parent transition's transaction.
     */
    public function mirrorParent(int $orderId, string $parentStatus, array $actor, ?string $note): void
    {
        $to = self::PARENT_TO_SELLER[$parentStatus] ?? null;
        if ($to === null) {
            return;    // return_requested / returned are handled per seller order in Phase 9
        }
        $rows = $this->db->all(
            "SELECT so.id, so.status, s.is_platform FROM seller_orders so JOIN sellers s ON s.id = so.seller_id
              WHERE so.order_id = ? AND so.status NOT IN ('cancelled','rejected') FOR UPDATE",
            [$orderId]
        );
        foreach ($rows as $so) {
            if ($so['status'] === $to) {
                continue;
            }
            // On confirmation only Waimart's own part is accepted automatically; marketplace sellers accept theirs.
            if ($parentStatus === 'confirmed' && !(int) $so['is_platform']) {
                continue;
            }
            $sets = ['status = ?'];
            $params = [$to];
            if ($to === 'cancelled') {
                $sets[] = 'cancelled_at = NOW()';
                $sets[] = 'cancel_reason = ?';
                $params[] = $note !== null ? mb_substr($note, 0, 500) : ($parentStatus === 'expired' ? 'Order expired before confirmation' : null);
            }
            $params[] = $so['id'];
            $this->db->exec('UPDATE seller_orders SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
            $this->db->insert('seller_order_history', [
                'seller_order_id' => $so['id'], 'from_status' => $so['status'], 'to_status' => $to,
                'actor_type' => $actor['type'], 'actor_user_id' => $actor['id'] ?? null, 'note' => 'Order ' . str_replace('_', ' ', $parentStatus),
            ]);
        }
    }

    /**
     * What the customer still owes/pays for the order: the order total minus parts that were declined or cancelled
     * (their merchandise less their discount share). Delivery fee stays unless the whole order is cancelled.
     */
    public static function amountDue(array $order, ?Database $db = null): string
    {
        $withdrawn = (string) ($db ?? App::db())->value(
            "SELECT COALESCE(SUM(items_subtotal - discount_share), 0) FROM seller_orders WHERE order_id = ? AND status IN ('rejected','cancelled')",
            [$order['id']]
        );
        return in_array($order['status'], ['cancelled', 'expired'], true) ? '0.00' : Money::sub((string) $order['grand_total'], $withdrawn);
    }

    /** Does the order contain a marketplace (non-platform) seller's part? Such orders are fulfilled per sub-order. */
    public static function hasMarketplaceSeller(int $orderId, ?Database $db = null): bool
    {
        return (bool) ($db ?? App::db())->value(
            'SELECT 1 FROM seller_orders so JOIN sellers s ON s.id = so.seller_id WHERE so.order_id = ? AND s.is_platform = 0 LIMIT 1',
            [$orderId]
        );
    }

    /** Emails each marketplace seller that a confirmed order is waiting for them (after commit). */
    public function notifyConfirmed(int $orderId): void
    {
        $rows = $this->db->all(
            "SELECT so.id, so.sub_number, so.items_subtotal, s.name AS store, u.id AS user_id, u.email, u.first_name
               FROM seller_orders so JOIN sellers s ON s.id = so.seller_id AND s.is_platform = 0
               JOIN seller_users su ON su.seller_id = s.id AND su.role IN ('owner','manager')
               JOIN users u ON u.id = su.user_id AND u.status = 'active'
              WHERE so.order_id = ? AND so.status NOT IN ('cancelled','rejected')",
            [$orderId]
        );
        foreach ($rows as $r) {
            (new NotificationService())->email((string) $r['email'], "New order {$r['sub_number']} for {$r['store']}",
                "Hello {$r['first_name']},\n\nOrder {$r['sub_number']} (" . Money::format((string) $r['items_subtotal']) . ") has been confirmed and is ready for you to prepare.\n\n"
                . 'Open it in the Seller Centre: ' . config('app.url') . "/seller\n\n— Waimart Seller Support",
                'seller_order_confirmed', ['user_id' => (int) $r['user_id']]);
        }
    }

    /** Seller Centre tabs: which part statuses each tab shows. */
    public const SELLER_TABS = [
        'new' => ['pending_acceptance'],
        'preparing' => ['accepted', 'processing'],
        'handover' => ['packed'],
        'with_waimart' => ['handed_over', 'dispatched', 'out_for_delivery', 'ready_for_collection'],
        'completed' => ['delivered', 'collected'],
        'cancelled' => ['cancelled', 'rejected'],
    ];

    /**
     * A seller's parts of CONFIRMED orders only (unpaid/unconfirmed orders are not shown to sellers).
     * @return list<array<string,mixed>>
     */
    public function forSeller(int $sellerId, ?string $tab = null, int $limit = 200): array
    {
        $params = [$sellerId];
        $where = 'so.seller_id = ? AND o.confirmed_at IS NOT NULL';
        if ($tab !== null && isset(self::SELLER_TABS[$tab])) {
            $where .= ' AND so.status IN (' . implode(',', array_fill(0, count(self::SELLER_TABS[$tab]), '?')) . ')';
            array_push($params, ...self::SELLER_TABS[$tab]);
        }
        return $this->db->all(
            "SELECT so.id, so.sub_number, so.status, so.items_subtotal, so.commission_total, so.seller_net, so.created_at, o.confirmed_at,
                    o.fulfilment_method, (SELECT SUM(quantity) FROM order_items oi WHERE oi.seller_order_id = so.id) AS units
               FROM seller_orders so JOIN orders o ON o.id = so.order_id
              WHERE $where ORDER BY o.confirmed_at DESC, so.id DESC LIMIT " . max(1, min(500, $limit)),
            $params
        );
    }

    /** @return array<string,int> tab => count for the seller's confirmed parts */
    public function sellerCounts(int $sellerId): array
    {
        $byStatus = array_map('intval', array_column($this->db->all(
            'SELECT so.status, COUNT(*) AS n FROM seller_orders so JOIN orders o ON o.id = so.order_id
              WHERE so.seller_id = ? AND o.confirmed_at IS NOT NULL GROUP BY so.status', [$sellerId]), 'n', 'status'));
        $out = [];
        foreach (self::SELLER_TABS as $tab => $statuses) {
            $out[$tab] = array_sum(array_map(fn ($s) => $byStatus[$s] ?? 0, $statuses));
        }
        return $out;
    }

    /**
     * One of this seller's parts with what the seller needs to fulfil it — never the customer's phone, email or
     * address (Waimart delivers). Another seller's part, or an unconfirmed order, is null (callers answer 404).
     * @return array<string,mixed>|null
     */
    public function sellerView(int $sellerId, int $sellerOrderId): ?array
    {
        $so = $this->db->one(
            "SELECT so.*, o.order_number, o.fulfilment_method, o.confirmed_at, o.status AS order_status,
                    SUBSTRING_INDEX(o.customer_name, ' ', 1) AS customer_first_name,
                    f.pickup_name, f.pickup_town, f.town AS delivery_town, f.county_name AS delivery_county
               FROM seller_orders so JOIN orders o ON o.id = so.order_id LEFT JOIN order_fulfilment f ON f.order_id = o.id
              WHERE so.id = ? AND so.seller_id = ? AND o.confirmed_at IS NOT NULL",
            [$sellerOrderId, $sellerId]
        );
        if ($so === null) {
            return null;
        }
        $so['items'] = $this->db->all(
            'SELECT product_id, product_name, variant_name, sku, image_path, unit_price, quantity, line_total, commission_rate, commission_amount, seller_net_amount
               FROM order_items WHERE seller_order_id = ? ORDER BY id',
            [$sellerOrderId]
        );
        $so['history'] = $this->history($sellerOrderId);
        return $so;
    }

    /** @return list<array<string,mixed>> */
    public function history(int $sellerOrderId): array
    {
        return $this->db->all(
            'SELECT h.from_status, h.to_status, h.actor_type, h.note, h.created_at FROM seller_order_history h WHERE h.seller_order_id = ? ORDER BY h.id',
            [$sellerOrderId]
        );
    }

    /** @return list<array<string,mixed>> seller groups of an order with their items, for customer and admin views */
    public function forOrder(int $orderId): array
    {
        $groups = $this->db->all(
            'SELECT so.*, s.name AS seller_name, s.slug AS seller_slug, s.is_platform AS seller_is_platform
               FROM seller_orders so JOIN sellers s ON s.id = so.seller_id WHERE so.order_id = ? ORDER BY so.id',
            [$orderId]
        );
        $items = $this->db->all('SELECT * FROM order_items WHERE order_id = ? ORDER BY id', [$orderId]);
        foreach ($groups as &$g) {
            $g['items'] = array_values(array_filter($items, fn ($i) => (int) $i['seller_order_id'] === (int) $g['id']));
        }
        return $groups;
    }
}
