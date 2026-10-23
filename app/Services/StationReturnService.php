<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Database;
use Waimart\Core\Logger;
use Waimart\Core\Settings;
use Waimart\Core\ValidationException;

/**
 * Pickup station arrivals and returns.
 *
 *  - arrived():  the parcel reached the station; the order becomes Ready for collection and the customer is sent the
 *                station, the collection code, "bring your ID" and the last day to collect.
 *  - remindDue(): one reminder the day before that last day.
 *  - returnDue(): orders still not collected after the hold period are returned automatically: the order is cancelled
 *                (refund recorded when it was paid), the customer told, and the parcel listed under "Returns to
 *                warehouse". Its stock stays held until receive() records it back.
 */
final class StationReturnService
{
    public const REASONS = ['not_collected' => 'Not collected in time', 'refused' => 'Customer refused or no longer wants it', 'other' => 'Other'];

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? App::db();
    }

    /** The parcel has arrived at the pickup station. @return array<string,mixed> the order */
    public function arrived(int $orderId, int $staffId): array
    {
        $o = $this->db->one('SELECT status, fulfilment_method FROM orders WHERE id = ?', [$orderId]) ?? throw ValidationException::withMessage('Order not found.');
        if ($o['fulfilment_method'] !== 'pickup' || $o['status'] !== 'processing') {
            throw ValidationException::withMessage('Only a pickup order on its way to the station (being prepared) can be marked as arrived.');
        }
        return (new CollectionService($this->db))->markReady($orderId, $staffId);   // creates the code and notifies the customer
    }

    /**
     * Sends the parcel back to the warehouse and cancels the order. $staffId null = automatic (hold period over).
     * @return array<string,mixed> the cancelled order
     */
    public function start(int $orderId, ?int $staffId, string $reason = 'not_collected', ?string $note = null): array
    {
        if (!isset(self::REASONS[$reason])) {
            throw new ValidationException(['reason' => 'Choose why the parcel is going back.']);
        }
        $days = Settings::int('collection_hold_days', 7);
        $cancelReason = $reason === 'not_collected' ? "Not collected within $days days: returned to the warehouse" : 'Returned from the pickup station: ' . self::REASONS[$reason];
        $order = $this->db->transaction(function () use ($orderId, $staffId, $reason, $note, $cancelReason) {
            $status = $this->db->value('SELECT status FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
            if ($status !== 'ready_for_collection') {
                throw ValidationException::withMessage('Only a parcel waiting at the pickup station can be sent back.');
            }
            $point = $this->db->value('SELECT pickup_point_id FROM order_fulfilment WHERE order_id = ?', [$orderId]);
            $order = (new OrderWorkflow($this->db))->transition($orderId, 'cancelled', $staffId ? ['type' => 'staff', 'id' => $staffId] : ['type' => 'system'],
                $note, ['reason' => $cancelReason, 'keep_stock' => true]);
            $this->db->insert('station_returns', ['order_id' => $orderId, 'pickup_point_id' => $point ?: null, 'reason' => $reason,
                'note' => $note !== null ? mb_substr($note, 0, 255) : null, 'started_by' => $staffId]);
            AuditLogger::log('order.station_return_started', 'order', $orderId, null, ['reason' => $reason, 'automatic' => $staffId === null], $staffId);
            return $order;
        });
        try {
            $vars = ['refund_note' => $order['payment_status'] === 'refund_due' ? ' Because your payment was already received, our team will arrange your refund.' : '']
                + OrderNotifier::pickupVars($orderId) + MessageTemplates::orderVars($order);
            (new NotificationService())->sendTemplate('order_not_collected', $vars,
                ['email' => $order['customer_email'], 'phone' => $order['customer_phone'], 'prefs' => null], ['user_id' => $order['user_id'] ? (int) $order['user_id'] : null, 'order_id' => $orderId]);
        } catch (\Throwable $e) {
            Logger::error('Not-collected message failed', ['order_id' => $orderId, 'error' => $e->getMessage()]);
        }
        return $order;
    }

    /** The parcel is back at the warehouse: its stock becomes available again. */
    public function receive(int $returnId, int $staffId, ?string $note = null): array
    {
        return $this->db->transaction(function () use ($returnId, $staffId, $note) {
            $r = $this->db->one('SELECT * FROM station_returns WHERE id = ? FOR UPDATE', [$returnId]) ?? throw ValidationException::withMessage('Return not found.');
            if ($r['status'] !== 'awaiting_return') {
                throw ValidationException::withMessage('This parcel has already been received back.');
            }
            $released = (new ReservationService($this->db))->releaseForOrder((int) $r['order_id'], 'released', 'Parcel received back from the pickup station', 'staff', $staffId);
            $this->db->exec("UPDATE station_returns SET status = 'received', received_at = NOW(), received_by = ?, note = COALESCE(?, note) WHERE id = ?",
                [$staffId, $note !== null ? mb_substr($note, 0, 255) : null, $returnId]);
            AuditLogger::log('order.station_return_received', 'order', (int) $r['order_id'], null, ['items_released' => $released], $staffId);
            return $r + ['items_released' => $released];
        });
    }

    /** Reminds customers whose parcel is still waiting, once, the day before the last day. @return int reminders sent */
    public function remindDue(int $limit = 100): int
    {
        $days = Settings::int('collection_hold_days', 7);
        $hours = max(1, Settings::int('collection_reminder_hours', 24));
        if ($days * 24 <= $hours) {
            return 0;   // hold period shorter than the reminder: the first message already says the deadline
        }
        $due = $this->db->all(
            "SELECT o.* FROM orders o JOIN order_fulfilment f ON f.order_id = o.id
              WHERE o.status = 'ready_for_collection' AND f.ready_at <= NOW() - INTERVAL ? HOUR
                AND NOT EXISTS (SELECT 1 FROM notifications n WHERE n.order_id = o.id AND n.template = 'order_collection_reminder')
              ORDER BY f.ready_at LIMIT " . max(1, min(500, $limit)),
            [$days * 24 - $hours]
        );
        $sent = 0;
        foreach ($due as $o) {
            try {
                (new NotificationService())->sendTemplate('order_collection_reminder', OrderNotifier::pickupVars((int) $o['id']) + MessageTemplates::orderVars($o),
                    ['email' => $o['customer_email'], 'phone' => $o['customer_phone'], 'prefs' => null], ['user_id' => $o['user_id'] ? (int) $o['user_id'] : null, 'order_id' => (int) $o['id']]);
                $sent++;
            } catch (\Throwable $e) {
                Logger::error('Collection reminder failed', ['order_id' => $o['id'], 'error' => $e->getMessage()]);
            }
        }
        return $sent;
    }

    /**
     * Returns every parcel whose hold period is over (unless switched off in Settings). Orders that include marketplace
     * sellers' parts are left for staff on the Collection desk (each seller's part is handled separately).
     * @return int returns started
     */
    public function returnDue(int $limit = 100): int
    {
        if (!Settings::bool('collection_auto_return', true)) {
            return 0;
        }
        $days = Settings::int('collection_hold_days', 7);
        $due = $this->db->all(
            "SELECT o.id FROM orders o JOIN order_fulfilment f ON f.order_id = o.id
              WHERE o.status = 'ready_for_collection' AND f.ready_at <= NOW() - INTERVAL ? DAY
              ORDER BY f.ready_at LIMIT " . max(1, min(500, $limit)),
            [$days]
        );
        $n = 0;
        foreach ($due as $row) {
            if (SellerOrderService::hasMarketplaceSeller((int) $row['id'], $this->db)) {
                continue;
            }
            try {
                $this->start((int) $row['id'], null);
                $n++;
            } catch (ValidationException) {
                // Collected or changed a moment ago: nothing to do.
            } catch (\Throwable $e) {
                Logger::error('Automatic station return failed', ['order_id' => $row['id'], 'error' => $e->getMessage()]);
            }
        }
        return $n;
    }
}
