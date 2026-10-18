<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Database;
use Waimart\Core\ValidationException;

/**
 * The only way an order changes status (B4, B5, C6, C7). Each transition:
 *   - locks the order row,
 *   - checks it is allowed from the current status for this fulfilment method,
 *   - applies its guards and stock side-effects in the SAME transaction,
 *   - records status history (and an audit entry for staff actions).
 * Arbitrary status edits are impossible: there is no "set status" operation.
 */
final class OrderWorkflow
{
    /** from => allowed targets (method-specific targets are filtered below). */
    public const TRANSITIONS = [
        'awaiting_payment'      => ['awaiting_verification', 'confirmed', 'cancelled', 'expired'],
        'awaiting_verification' => ['awaiting_payment', 'confirmed', 'cancelled', 'expired'],
        'pending_confirmation'  => ['confirmed', 'cancelled', 'expired'],
        'expired'               => ['requires_resolution', 'cancelled'],
        'requires_resolution'   => ['confirmed', 'cancelled'],
        'confirmed'             => ['processing', 'cancelled'],
        'processing'            => ['packed', 'ready_for_collection', 'cancelled'],
        'packed'                => ['dispatched', 'cancelled'],
        'dispatched'            => ['out_for_delivery', 'delivered'],
        'out_for_delivery'      => ['delivered'],
        'ready_for_collection'  => ['collected', 'cancelled'],
        'delivered'             => ['return_requested'],
        'collected'             => ['return_requested'],
        // Back to delivered/collected when a return is rejected or only part of the order came back.
        'return_requested'      => ['returned', 'delivered', 'collected'],
        'cancelled'             => [],
        'returned'              => [],
    ];

    private const DELIVERY_ONLY = ['packed', 'dispatched', 'out_for_delivery', 'delivered'];
    private const PICKUP_ONLY = ['ready_for_collection', 'collected'];
    /** Once goods have left the warehouse, cancellation is not a stock release — use returns (C6). */
    private const HANDED_OVER = ['dispatched', 'out_for_delivery', 'delivered', 'collected', 'return_requested', 'returned'];

    private Database $db;
    private ReservationService $reservations;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? App::db();
        $this->reservations = new ReservationService($this->db);
    }

    /** Targets allowed now for this order (for building staff action buttons). @return list<string> */
    public static function allowedTargets(array $order): array
    {
        $method = (string) $order['fulfilment_method'];
        return array_values(array_filter(
            self::TRANSITIONS[$order['status']] ?? [],
            fn ($to) => !($method === 'pickup' && in_array($to, self::DELIVERY_ONLY, true))
                && !($method === 'delivery' && in_array($to, self::PICKUP_ONLY, true))
        ));
    }

    /**
     * @param array{type:'customer'|'staff'|'system',id?:?int} $actor
     * @param array<string,mixed> $opts e.g. ['reason' => '...', 'confirmation' => 'Signed by recipient']
     * @return array<string,mixed> the updated order
     */
    public function transition(int $orderId, string $to, array $actor, ?string $note = null, array $opts = []): array
    {
        return $this->db->transaction(function () use ($orderId, $to, $actor, $note, $opts) {
            $order = $this->db->one('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId])
                ?? throw ValidationException::withMessage('Order not found.');
            $from = (string) $order['status'];

            if ($from === $to) {
                throw ValidationException::withMessage('This order is already ' . strtolower(OrderPresenter::status($to)) . '.');
            }
            // Goods that have left a seller's stock (a marketplace part handed over to Waimart) cannot be "un-sold" by
        // cancelling the whole order; the remaining parts are cancelled individually instead.
        if ($to === 'cancelled' && $this->db->value("SELECT 1 FROM stock_reservations WHERE order_id = ? AND status = 'consumed' LIMIT 1", [$orderId])) {
            throw ValidationException::withMessage('Part of this order has already been handed over, so the whole order cannot be cancelled. Cancel the remaining parts individually, or use the returns process.');
        }
        if ($to === 'cancelled' && in_array($from, self::HANDED_OVER, true)) {
                throw ValidationException::withMessage('This order has already been handed over, so it cannot be cancelled. Please use the returns process.');
            }
            if (!in_array($to, self::allowedTargets($order), true)) {
                throw ValidationException::withMessage(sprintf(
                    'An order that is "%s" cannot be moved to "%s".', OrderPresenter::status($from), OrderPresenter::status($to)
                ));
            }
            $this->guard($order, $to, $actor);

            $actorId = $actor['id'] ?? null;
            $sets = ['status = ?'];
            $params = [$to];
            $this->applySideEffects($order, $to, $actor, $opts, $sets, $params);

            $params[] = $orderId;
            $this->db->exec('UPDATE orders SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
            $this->db->insert('order_status_history', [
                'order_id' => $orderId, 'from_status' => $from, 'to_status' => $to,
                'actor_type' => $actor['type'], 'actor_user_id' => $actorId,
                'note' => $note !== null ? mb_substr($note, 0, 500) : null,
            ]);
            if ($actor['type'] === 'staff') {
                AuditLogger::log('order.status_changed', 'order', $orderId, ['status' => $from], ['status' => $to, 'note' => $note], $actorId);
            }
            // Keep the seller sub-orders in step (interim, until sellers drive fulfilment per sub-order in Phase 7).
            (new SellerOrderService($this->db))->mirrorParent($orderId, $to, $actor, $note);
            $this->collectionVerified = null;     // one-shot
            return (array) $this->db->one('SELECT * FROM orders WHERE id = ?', [$orderId]);
        });
    }

    /** Business preconditions beyond the transition table. */
    private function guard(array $o, string $to, array $actor): void
    {
        $method = (string) $o['payment_method'];
        if ($to === 'expired' && $actor['type'] !== 'system') {
            throw ValidationException::withMessage('Only the expiry process can expire an order.');
        }
        if ($to === 'confirmed') {
            if ($o['status'] === 'pending_confirmation' && $method !== 'cod') {
                throw ValidationException::withMessage('Only cash-on-delivery orders are confirmed without payment.');
            }
            if (in_array($o['status'], ['awaiting_payment', 'awaiting_verification', 'requires_resolution'], true)
                && $method !== 'cod' && $o['payment_status'] !== 'verified') {
                throw ValidationException::withMessage('This order cannot be confirmed until its payment has been verified.');
            }
            if (!$this->reservations->fullyReserved((int) $o['id'])) {
                throw ValidationException::withMessage('This order no longer has stock reserved for every item, so it cannot be confirmed. Resolve it first.');
            }
        }
        if (in_array($to, ['dispatched', 'collected', 'delivered', 'ready_for_collection', 'packed', 'processing'], true)
            && $actor['type'] !== 'staff') {
            throw ValidationException::withMessage('Only authorised staff can update fulfilment.');
        }
        // Orders with marketplace sellers are fulfilled per seller sub-order (SellerOrderWorkflow); the order's own
        // status follows from them. Returning to delivered/collected after a return request is not fulfilment.
        if (in_array($to, ['processing', 'packed', 'dispatched', 'out_for_delivery', 'delivered', 'ready_for_collection', 'collected'], true)
            && $o['status'] !== 'return_requested' && SellerOrderService::hasMarketplaceSeller((int) $o['id'], $this->db)) {
            throw ValidationException::withMessage('This order includes marketplace sellers, so each seller\'s part is updated separately below.');
        }
        // B4: "ready" never implies "collected" — collection requires the customer's code to have been
        // verified by CollectionService, which is the only caller that sets this flag.
        if ($to === 'collected' && $o['status'] === 'ready_for_collection' && ($this->collectionVerified ?? false) !== true) {
            throw ValidationException::withMessage('Collection must be confirmed with the customer\'s collection code at the pickup desk.');
        }
    }

    /** Set only by CollectionService after a successful code check; consumed by the next transition. */
    private ?bool $collectionVerified = null;

    /** @internal for CollectionService */
    public function markCollectionVerified(): self
    {
        $this->collectionVerified = true;
        return $this;
    }

    /** @param list<string> $sets @param list<mixed> $params */
    private function applySideEffects(array $o, string $to, array $actor, array $opts, array &$sets, array &$params): void
    {
        $id = (int) $o['id'];
        $actorId = $actor['id'] ?? null;

        // Returning to delivered/collected after a rejected or partial return is a restore, not a new
        // hand-over: the original timestamps stand and no stock is consumed again.
        if ($o['status'] === 'return_requested' && in_array($to, ['delivered', 'collected'], true)) {
            return;
        }

        // Leaving "requires resolution": close the open case with its outcome (C5).
        if ($o['status'] === 'requires_resolution') {
            $this->db->exec(
                "UPDATE order_resolutions SET status = 'resolved', outcome = ?, resolved_at = NOW(), resolved_by = ?, note = ?
                  WHERE order_id = ? AND status = 'open'",
                [$opts['resolution_outcome'] ?? ($to === 'confirmed' ? 're_reserved' : 'cancelled'), $actorId,
                 isset($opts['resolution_note']) ? mb_substr((string) $opts['resolution_note'], 0, 1000) : null, $id]
            );
            $sets[] = 'requires_resolution = 0';
        }

        switch ($to) {
            case 'requires_resolution':
                $this->db->insert('order_resolutions', [
                    'order_id' => $id,
                    'reason' => $opts['resolution_reason'] ?? 'other',
                    'details' => isset($opts['resolution_details']) ? mb_substr((string) $opts['resolution_details'], 0, 1000) : null,
                ]);
                $sets[] = 'requires_resolution = 1';
                break;

            case 'confirmed':
                $this->reservations->holdUntilFulfilment($id);
                $sets[] = 'confirmed_at = NOW()';
                break;

            case 'expired':
                $this->reservations->releaseForOrder($id, 'expired', 'Reservation expired', 'system');
                $this->voidCoupon($id);
                break;

            case 'cancelled':
                $reason = trim((string) ($opts['reason'] ?? '')) ?: 'Cancelled';
                $this->reservations->releaseForOrder($id, 'released', 'Order cancelled: ' . $reason, $actor['type'], $actorId);
                $this->voidCoupon($id);
                $sets[] = 'cancelled_at = NOW()';
                $sets[] = 'cancel_reason = ?';
                $params[] = mb_substr($reason, 0, 255);
                // Money already verified must be returned: record the obligation (A6, C5) — minus anything
                // already owed through earlier part-refunds (a seller rejected or a part was cancelled).
                if (in_array($o['payment_status'], ['verified', 'refund_due'], true)) {
                    $owed = (string) $this->db->value("SELECT COALESCE(SUM(amount), 0) FROM refunds WHERE order_id = ? AND status <> 'cancelled' AND return_id IS NULL", [$id]);
                    $remaining = \Waimart\Core\Money::sub((string) $o['grand_total'], $owed);
                    $sets[] = "payment_status = 'refund_due'";
                    if (\Waimart\Core\Money::cmp($remaining, '0') > 0) {
                        $this->db->insert('refunds', [
                            'order_id' => $id, 'amount' => $remaining, 'reason' => 'Order cancelled: ' . mb_substr($reason, 0, 200),
                            'created_by' => $actorId,
                        ]);
                    }
                }
                $this->db->exec("UPDATE order_fulfilment SET collection_code_status = 'invalidated' WHERE order_id = ? AND collection_code_status = 'active'", [$id]);
                break;

            case 'ready_for_collection':
                // B4: an unguessable collection code — stored as a keyed hash (verification) and
                // encrypted (re-display to the customer only). Never stored in plain text.
                $code = \Waimart\Core\Crypto::code(8);
                $this->db->exec(
                    "UPDATE order_fulfilment SET collection_code_hash = ?, collection_code_enc = ?, collection_code_status = 'active',
                            ready_at = NOW(), ready_by = ? WHERE order_id = ?",
                    [\Waimart\Core\Crypto::hash($code), \Waimart\Core\Crypto::encrypt($code), $actorId, $id]
                );
                break;

            case 'dispatched':
                $this->reservations->consumeForOrder($id, 'staff', $actorId, 'Handed over for delivery');
                $this->db->exec('UPDATE order_fulfilment SET dispatched_at = NOW(), dispatched_by = ? WHERE order_id = ?', [$actorId, $id]);
                break;

            case 'collected':
                $this->reservations->consumeForOrder($id, 'staff', $actorId, 'Collected at pickup station');
                $this->db->exec(
                    "UPDATE order_fulfilment SET collected_at = NOW(), collected_by = ?, collection_outcome = ?,
                            collection_code_status = IF(collection_code_status = 'active', 'used', collection_code_status)
                      WHERE order_id = ?",
                    [$actorId, mb_substr((string) ($opts['outcome'] ?? 'Collected'), 0, 255), $id]
                );
                $sets[] = 'completed_at = NOW()';
                break;

            case 'delivered':
                $this->db->exec(
                    'UPDATE order_fulfilment SET delivered_at = NOW(), delivered_by = ?, delivery_confirmation = ? WHERE order_id = ?',
                    [$actorId, mb_substr((string) ($opts['confirmation'] ?? 'Confirmed by staff'), 0, 255), $id]
                );
                $sets[] = 'completed_at = NOW()';
                break;
        }
    }

    private function voidCoupon(int $orderId): void
    {
        $r = $this->db->one("SELECT id, coupon_id FROM coupon_redemptions WHERE order_id = ? AND status = 'active' FOR UPDATE", [$orderId]);
        if ($r !== null && $this->db->exec("UPDATE coupon_redemptions SET status = 'voided' WHERE id = ? AND status = 'active'", [$r['id']]) === 1) {
            $this->db->exec('UPDATE coupons SET used_count = GREATEST(used_count, 1) - 1 WHERE id = ?', [$r['coupon_id']]);
        }
    }

    /** Customer self-cancellation is allowed only before payment is submitted or the order confirmed. */
    public static function customerCanCancel(array $order): bool
    {
        return in_array($order['status'], ['awaiting_payment', 'pending_confirmation'], true);
    }
}
