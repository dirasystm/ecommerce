<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Crypto;
use Waimart\Core\Database;
use Waimart\Core\HttpException;
use Waimart\Core\Money;
use Waimart\Core\ValidationException;

/**
 * Fulfilment of ONE seller's part of an order (orders that include marketplace sellers; Official-Store-only orders
 * keep the order-level flow in OrderWorkflow).
 *
 *   seller:  pending_acceptance → accepted → processing → packed            (or → rejected, with a reason)
 *   Waimart: packed → handed_over → dispatched → out_for_delivery → delivered          (home delivery)
 *                                 → ready_for_collection → collected (code checked)    (pickup)
 *            The Official Store's own part skips hand-over (packed → dispatched / ready_for_collection).
 *   staff may cancel a part before it is handed over.
 *
 * Stock leaves the seller's inventory when the goods leave the seller: at hand-over for marketplace sellers, at
 * dispatch/collection for the Official Store. A rejected or cancelled part releases only its own reservations and,
 * if the customer has already paid, records a refund for that part. The parent order's status is recalculated from
 * its parts after every step (rollUp): it becomes delivered/collected only when every active part is.
 */
final class SellerOrderWorkflow
{
    /** to => [allowed from statuses] */
    private const FROM = [
        'accepted' => ['pending_acceptance'],
        'processing' => ['accepted'],
        'packed' => ['processing'],
        'handed_over' => ['packed'],
        'dispatched' => ['handed_over', 'packed'],
        'out_for_delivery' => ['dispatched'],
        'delivered' => ['dispatched', 'out_for_delivery'],
        'ready_for_collection' => ['handed_over', 'packed'],
        'collected' => ['ready_for_collection'],
        'rejected' => ['pending_acceptance', 'accepted', 'processing', 'packed'],
        'cancelled' => ['pending_acceptance', 'accepted', 'processing', 'packed'],
    ];
    public const SELLER_STEPS = ['accepted', 'processing', 'packed', 'rejected'];
    /** Parent statuses in which sellers may act (the order is confirmed: paid or COD confirmed). */
    private const PARENT_ACTIVE = ['confirmed', 'processing', 'packed', 'dispatched', 'out_for_delivery', 'ready_for_collection'];
    /** Progress rank used for the parent roll-up. */
    private const RANK = ['pending_acceptance' => 0, 'accepted' => 0, 'processing' => 1, 'packed' => 2, 'handed_over' => 2,
        'dispatched' => 3, 'ready_for_collection' => 3, 'out_for_delivery' => 4, 'delivered' => 5, 'collected' => 5];

    private Database $db;
    private bool $collectionVerified = false;
    /** @var list<array{type:string,order:array,so:array,code:?string}> customer messages to send after commit */
    private array $outbox = [];

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? App::db();
    }

    /** @internal set by CollectionService after the customer's code has been verified. */
    public function markCollectionVerified(): self
    {
        $this->collectionVerified = true;
        return $this;
    }

    /** What a seller (seller=true) or staff member may do next with this part. @return list<string> */
    public function allowedNext(array $so, bool $asSeller): array
    {
        $order = (array) $this->db->one('SELECT status, fulfilment_method FROM orders WHERE id = ?', [$so['order_id']]);
        $platform = (int) $this->db->value('SELECT is_platform FROM sellers WHERE id = ?', [$so['seller_id']]) === 1;
        $out = [];
        foreach (self::FROM as $to => $from) {
            if (in_array($so['status'], $from, true) && $this->permitted($to, $so, $order, $platform, $asSeller) === null) {
                $out[] = $to;
            }
        }
        return $out;
    }

    /**
     * @param array{type:'seller'|'staff'|'system',id?:?int,seller_id?:int} $actor seller actors carry the seller id from SellerContext
     * @param array<string,mixed> $opts reason, note, tracking_reference
     * @return array<string,mixed> the updated seller order
     */
    public function transition(int $sellerOrderId, string $to, array $actor, array $opts = []): array
    {
        $this->outbox = [];
        $so = $this->db->transaction(function () use ($sellerOrderId, $to, $actor, $opts) {
            $so = $this->db->one('SELECT * FROM seller_orders WHERE id = ? FOR UPDATE', [$sellerOrderId]) ?? throw HttpException::notFound();
            if ($actor['type'] === 'seller' && (int) $so['seller_id'] !== (int) ($actor['seller_id'] ?? 0)) {
                throw HttpException::notFound();              // another seller's part: indistinguishable from missing
            }
            $order = (array) $this->db->one('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$so['order_id']]);
            $platform = (int) $this->db->value('SELECT is_platform FROM sellers WHERE id = ?', [$so['seller_id']]) === 1;
            if (!isset(self::FROM[$to]) || !in_array($so['status'], self::FROM[$to], true)) {
                throw ValidationException::withMessage(sprintf('This part of the order is "%s" and cannot be moved to "%s".',
                    OrderPresenter::sellerOrderStatus((string) $so['status']), OrderPresenter::sellerOrderStatus($to)));
            }
            if (($problem = $this->permitted($to, $so, $order, $platform, $actor['type'] === 'seller')) !== null) {
                throw ValidationException::withMessage($problem);
            }
            $this->apply($so, $order, $to, $actor, $opts, $platform);
            $this->rollUp((int) $order['id'], $actor);
            return (array) $this->db->one('SELECT * FROM seller_orders WHERE id = ?', [$sellerOrderId]);
        });
        $this->flush();
        return $so;
    }

    /** Why this actor may not make this step now, or null when allowed. */
    private function permitted(string $to, array $so, array $order, bool $platform, bool $asSeller): ?string
    {
        if (!SellerOrderService::hasMarketplaceSeller((int) $so['order_id'], $this->db)) {
            return 'Orders sold only by the ' . brand_name() . ' Official Store are updated from the order itself.';
        }
        if ($asSeller) {
            if (!in_array($to, self::SELLER_STEPS, true)) {
                return brand_name() . ' staff handle hand-over, delivery and collection.';
            }
            if ($platform) {
                return 'The Official Store is fulfilled by ' . brand_name() . ' staff.';
            }
        } elseif (in_array($to, ['accepted', 'processing', 'packed', 'rejected'], true) && !$platform) {
            return 'Only the seller can accept, prepare or decline their part. Staff can cancel it instead.';
        }
        if (in_array($to, ['accepted', 'processing', 'packed', 'handed_over', 'dispatched', 'ready_for_collection'], true)
            && !in_array($order['status'], self::PARENT_ACTIVE, true)) {
            return 'This order is not confirmed yet (payment or cash-on-delivery confirmation is pending).';
        }
        if ($to === 'handed_over' && $platform) {
            return 'The Official Store\'s items do not need a hand-over.';
        }
        if (in_array($to, ['dispatched', 'out_for_delivery', 'delivered'], true) && $order['fulfilment_method'] !== 'delivery') {
            return 'This is a pickup order.';
        }
        if (in_array($to, ['ready_for_collection', 'collected'], true) && $order['fulfilment_method'] !== 'pickup') {
            return 'This is a home-delivery order.';
        }
        if (in_array($to, ['dispatched', 'ready_for_collection'], true) && $so['status'] === 'packed' && !$platform) {
            return brand_name() . ' must receive the parcel from the seller first (hand-over).';
        }
        if ($to === 'collected' && !$this->collectionVerified) {
            return 'Collection must be confirmed with the customer\'s collection code at the pickup desk.';
        }
        return null;
    }

    private function apply(array $so, array $order, string $to, array $actor, array $opts, bool $platform): void
    {
        $id = (int) $so['id'];
        $orderId = (int) $order['id'];
        $actorId = $actor['id'] ?? null;
        $reservations = new ReservationService($this->db);
        $actorType = $actor['type'] === 'seller' ? 'seller' : ($actor['type'] === 'system' ? 'system' : 'staff');
        $sets = ['status = ?'];
        $params = [$to];
        $note = isset($opts['note']) ? trim((string) $opts['note']) : null;

        switch ($to) {
            case 'accepted':
                array_push($sets, 'accepted_at = NOW()', 'accepted_by = ?');
                $params[] = $actorId;
                break;
            case 'packed':
                array_push($sets, 'packed_at = NOW()', 'packed_by = ?', 'dispatch_note = ?');
                array_push($params, $actorId, $note !== '' && $note !== null ? mb_substr($note, 0, 500) : null);
                break;
            case 'handed_over':
                $reservations->consumeForSellerOrder($orderId, $id, $actorType, $actorId, 'Handed over to ' . brand_name() . ' by the seller');
                array_push($sets, 'handed_over_at = NOW()', 'handed_over_by = ?');
                $params[] = $actorId;
                break;
            case 'dispatched':
                if ($platform) {
                    $reservations->consumeForSellerOrder($orderId, $id, $actorType, $actorId, 'Handed over for delivery');
                }
                $ref = trim((string) ($opts['tracking_reference'] ?? ''));
                array_push($sets, 'dispatched_at = NOW()', 'dispatched_by = ?', 'tracking_reference = ?');
                array_push($params, $actorId, $ref !== '' ? mb_substr($ref, 0, 80) : null);
                $this->outbox[] = ['type' => 'dispatched', 'order' => $order, 'so' => $so, 'code' => null];
                break;
            case 'delivered':
                array_push($sets, 'delivered_at = NOW()', 'delivered_by = ?');
                $params[] = $actorId;
                break;
            case 'ready_for_collection':
                $code = $this->ensureCollectionCode($orderId, $actorId);
                array_push($sets, 'ready_at = NOW()', 'ready_by = ?');
                $params[] = $actorId;
                $this->outbox[] = ['type' => 'ready', 'order' => $order, 'so' => $so, 'code' => $code];
                break;
            case 'collected':
                if ($platform) {
                    $reservations->consumeForSellerOrder($orderId, $id, $actorType, $actorId, 'Collected at pickup station');
                }
                array_push($sets, 'collected_at = NOW()', 'collected_by = ?');
                $params[] = $actorId;
                break;
            case 'rejected':
            case 'cancelled':
                $reason = trim((string) ($opts['reason'] ?? ''));
                if (mb_strlen($reason) < 5) {
                    throw new ValidationException(['reason' => 'Give a reason (at least 5 characters). The customer will see it.']);
                }
                $reservations->releaseForSellerOrder($orderId, $id, ($to === 'rejected' ? 'Declined by seller: ' : 'Part cancelled: ') . $reason, $actorType, $actorId);
                if ($to === 'rejected') {
                    array_push($sets, 'rejected_at = NOW()', 'rejected_by = ?', 'reject_reason = ?');
                    array_push($params, $actorId, mb_substr($reason, 0, 500));
                } else {
                    array_push($sets, 'cancelled_at = NOW()', 'cancel_reason = ?');
                    $params[] = mb_substr($reason, 0, 500);
                }
                $this->refundPartIfPaid($order, $so, $reason, $actorId);
                (new SellerLedgerService($this->db))->reversePart($id, $reason, $actorId);
                $this->outbox[] = ['type' => 'withdrawn', 'order' => $order, 'so' => $so + ['reason' => $reason], 'code' => null];
                break;
        }
        $params[] = $id;
        $this->db->exec('UPDATE seller_orders SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
        $this->db->insert('seller_order_history', [
            'seller_order_id' => $id, 'from_status' => $so['status'], 'to_status' => $to, 'actor_type' => $actor['type'],
            'actor_user_id' => $actorId, 'note' => $note !== null && $note !== '' ? mb_substr($note, 0, 500) : ($opts['reason'] ?? null),
        ]);
        if (in_array($actor['type'], ['staff', 'seller'], true)) {   // people's decisions are audited; automatic steps are in the history
            AuditLogger::log('seller_order.status_changed', 'seller_order', $id, ['status' => $so['status']], ['status' => $to, 'reason' => $opts['reason'] ?? null, 'by' => $actor['type']], $actorId);
        }
    }

    /** The customer already paid: this part's value becomes a refund obligation (never claimed as returned until paid out). */
    private function refundPartIfPaid(array $order, array $so, string $reason, ?int $actorId): void
    {
        if (!in_array($order['payment_status'], ['verified', 'refund_due', 'cod_collected'], true)) {
            return;   // unpaid / COD not yet collected: the amount due simply drops (SellerOrderService::amountDue)
        }
        // What was paid for the part less anything already refunded on it (e.g. a case resolved while in progress).
        $amount = (new RefundService($this->db))->refundableForPart((int) $so['id'], true);
        if (Money::cmp($amount, '0') <= 0) {
            return;
        }
        $this->db->insert('refunds', [
            'order_id' => $order['id'], 'seller_order_id' => $so['id'], 'amount' => $amount,
            'reason' => 'Part ' . $so['sub_number'] . ' not supplied: ' . mb_substr($reason, 0, 180), 'created_by' => $actorId,
        ]);
        $this->db->exec("UPDATE orders SET payment_status = 'refund_due' WHERE id = ?", [$order['id']]);
    }

    /** One collection code per order: issued with the first part that is ready, re-used for later parts. */
    private function ensureCollectionCode(int $orderId, ?int $actorId): ?string
    {
        $f = $this->db->one('SELECT collection_code_status, collection_code_enc FROM order_fulfilment WHERE order_id = ? FOR UPDATE', [$orderId]);
        if (($f['collection_code_status'] ?? null) === 'active') {
            return null;   // the customer already has their code
        }
        $code = Crypto::code(8);
        $this->db->exec(
            "UPDATE order_fulfilment SET collection_code_hash = ?, collection_code_enc = ?, collection_code_status = 'active',
                    ready_at = COALESCE(ready_at, NOW()), ready_by = COALESCE(ready_by, ?) WHERE order_id = ?",
            [Crypto::hash($code), Crypto::encrypt($code), $actorId, $orderId]
        );
        return $code;
    }

    /**
     * Recalculates the parent order's status from its active parts: the least advanced part decides
     * (so "delivered"/"collected" only when every part is); no active parts left → the order is cancelled.
     */
    public function rollUp(int $orderId, array $actor): void
    {
        $order = (array) $this->db->one('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
        $parts = $this->db->all("SELECT status FROM seller_orders WHERE order_id = ? AND status NOT IN ('cancelled','rejected')", [$orderId]);
        if ($parts === []) {
            if (!in_array($order['status'], ['cancelled', 'expired'], true)) {
                (new OrderWorkflow($this->db))->transition($orderId, 'cancelled', ['type' => 'system'], 'No items left to supply',
                    ['reason' => 'None of the sellers could supply the items']);
                $this->outbox[] = ['type' => 'order_status', 'order' => ['id' => $orderId], 'so' => [], 'code' => null];
            }
            return;
        }
        if (!in_array($order['status'], array_merge(['confirmed'], self::PARENT_ACTIVE, ['delivered', 'collected']), true)) {
            return;   // payment phase / resolution / returns: not driven by fulfilment
        }
        $rank = min(array_map(fn ($p) => self::RANK[$p['status']] ?? 0, $parts));
        $pickup = $order['fulfilment_method'] === 'pickup';
        $target = match ($rank) {
            0 => 'confirmed', 1 => 'processing', 2 => 'packed',
            3 => $pickup ? 'ready_for_collection' : 'dispatched',
            4 => 'out_for_delivery',
            default => $pickup ? 'collected' : 'delivered',
        };
        if ($target === $order['status']) {
            return;
        }
        $sets = ['status = ?'];
        $params = [$target];
        if (in_array($target, ['delivered', 'collected'], true)) {
            $sets[] = 'completed_at = NOW()';
            $col = $target === 'delivered' ? 'delivered_at = NOW(), delivered_by = ?' : "collected_at = NOW(), collected_by = ?, collection_code_status = IF(collection_code_status = 'active', 'used', collection_code_status)";
            $this->db->exec("UPDATE order_fulfilment SET $col WHERE order_id = ?", [$actor['id'] ?? null, $orderId]);
        } elseif ($target === 'dispatched') {
            $this->db->exec('UPDATE order_fulfilment SET dispatched_at = COALESCE(dispatched_at, NOW()), dispatched_by = COALESCE(dispatched_by, ?) WHERE order_id = ?', [$actor['id'] ?? null, $orderId]);
        }
        $params[] = $orderId;
        $this->db->exec('UPDATE orders SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
        $this->db->insert('order_status_history', [
            'order_id' => $orderId, 'from_status' => $order['status'], 'to_status' => $target,
            'actor_type' => 'system', 'actor_user_id' => null, 'note' => 'Updated from the sellers\' progress',
        ]);
        $this->outbox[] = ['type' => 'order_status', 'order' => ['id' => $orderId], 'so' => [], 'code' => null];
    }

    /** Customer messages, sent after the transaction committed. */
    private function flush(): void
    {
        foreach ($this->outbox as $m) {
            try {
                $order = (array) $this->db->one('SELECT * FROM orders WHERE id = ?', [$m['order']['id']]);
                if ($m['type'] === 'order_status') {
                    OrderNotifier::statusChanged($order);
                    continue;
                }
                $vars = ['part_number' => (string) $m['so']['sub_number'],
                         'seller_name' => (string) $this->db->value('SELECT name FROM sellers WHERE id = ?', [$m['so']['seller_id']])] + MessageTemplates::orderVars($order);
                if ($m['type'] === 'ready') {
                    $vars['collection_note'] = $m['code'] ? " Your collection code is {$m['code']}. Keep it private." : ' Use the collection code we sent you earlier.';
                } elseif ($m['type'] === 'withdrawn') {
                    $vars['reason'] = (string) $m['so']['reason'];
                    $vars['refund_note'] = in_array($order['payment_status'], ['refund_due'], true) ? ' We will refund you for it.' : ' You will not be charged for it.';
                }
                // "Ready" (it may carry the code) and "withdrawn" are always sent; "dispatched" follows the customer's preferences.
                $prefs = $order['user_id'] ? $this->db->one('SELECT notify_email, notify_sms FROM users WHERE id = ?', [$order['user_id']]) : null;
                (new NotificationService())->sendTemplate('seller_order_' . $m['type'], $vars,
                    ['email' => $order['customer_email'], 'phone' => $order['customer_phone'], 'prefs' => $prefs],
                    ['user_id' => $order['user_id'] ? (int) $order['user_id'] : null, 'order_id' => (int) $order['id'], 'secrets' => $m['code'] ? [$m['code']] : []]);
            } catch (\Throwable $e) {
                \Waimart\Core\Logger::error('Seller order notification failed', ['error' => $e->getMessage()]);
            }
        }
        $this->outbox = [];
    }
}
