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
        foreach ($this->db->all("SELECT id, status FROM seller_orders WHERE order_id = ? AND status NOT IN ('cancelled','rejected') FOR UPDATE", [$orderId]) as $so) {
            if ($so['status'] === $to) {
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
