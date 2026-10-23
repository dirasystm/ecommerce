<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Logger;

/**
 * Customer notifications for status changes (F). Respects the customer's notification
 * preferences for registered accounts; guests receive order updates on the contact details
 * they gave. Never throws — a failed message must not undo a committed status change.
 */
final class OrderNotifier
{
    /** Statuses customers are told about; the wording is the template order_<status> (MessageTemplates). */
    private const NOTIFY = ['confirmed', 'processing', 'packed', 'dispatched', 'out_for_delivery', 'delivered', 'ready_for_collection', 'collected', 'cancelled', 'expired', 'requires_resolution'];

    /**
     * Where and until when a pickup order can be collected: the station (name, address, town, hours), the hold period,
     * and the last day (the day it arrived plus the hold period).
     * @return array{pickup_location:string,hold_days:string,collect_by:string}
     */
    public static function pickupVars(int $orderId): array
    {
        $f = App::db()->one('SELECT pickup_name, pickup_address, pickup_town, pickup_hours, ready_at FROM order_fulfilment WHERE order_id = ?', [$orderId]) ?? [];
        $days = \Waimart\Core\Settings::int('collection_hold_days', 7);
        $from = !empty($f['ready_at']) ? strtotime((string) $f['ready_at']) : time();
        $where = implode(', ', array_filter([$f['pickup_name'] ?? '', $f['pickup_address'] ?? '', $f['pickup_town'] ?? ''], fn ($v) => trim((string) $v) !== ''));
        return [
            'pickup_location' => $where . (!empty($f['pickup_hours']) ? " ({$f['pickup_hours']})" : ''),
            'hold_days' => (string) $days,
            'collect_by' => date('D j M', $from + $days * 86400),
        ];
    }

    public static function statusChanged(array $order): void
    {
        \Waimart\Services\Marketing\Signals::orderStatus($order);     // campaign and recommendation results (never blocks)
        if ($order['status'] === 'confirmed') {
            try {
                // Marketplace sellers start preparing once the order is confirmed (paid / COD confirmed).
                (new SellerOrderService())->notifyConfirmed((int) $order['id']);
            } catch (\Throwable $e) {
                \Waimart\Core\Logger::error('Seller notification failed', ['order_id' => $order['id'], 'error' => $e->getMessage()]);
            }
        }
        if (!in_array($order['status'], self::NOTIFY, true)) {
            return;
        }
        try {
            $db = App::db();
            $user = $order['user_id'] ? $db->one('SELECT notify_email, notify_sms FROM users WHERE id = ?', [$order['user_id']]) : null;
            $vars = ['status_label' => OrderPresenter::status((string) $order['status']),
                     'refund_note' => $order['status'] === 'cancelled' && $order['payment_status'] === 'refund_due'
                        ? ' Because your payment was already received, our team will arrange your refund.' : ''] + MessageTemplates::orderVars($order);
            $ctx = ['user_id' => $order['user_id'] ? (int) $order['user_id'] : null, 'order_id' => (int) $order['id']];
            if ($order['status'] === 'dispatched') {
                $vars['tracking_note'] = MessageTemplates::trackingNote((int) $order['id']);
            }
            if (in_array($order['status'], ['confirmed', 'processing', 'packed', 'dispatched'], true)) {
                $vars['eta_note'] = OrderJourney::etaNote($order);
            }

            // Ready for collection: the code is essential, so the template is always sent (preferences apply to
            // optional updates only) and the code is redacted from the stored outbox copy.
            if ($order['status'] === 'ready_for_collection') {
                $code = CollectionService::codeFor($order);
                $vars['collection_code'] = (string) $code;
                $vars += self::pickupVars((int) $order['id']);
                $ctx['secrets'] = [(string) $code];
            }
            (new NotificationService())->sendTemplate('order_' . $order['status'], $vars,
                ['email' => $order['customer_email'], 'phone' => $order['customer_phone'], 'prefs' => $user], $ctx);
        } catch (\Throwable $e) {
            Logger::error('Status notification failed', ['order_id' => $order['id'] ?? null, 'error' => $e->getMessage()]);
        }
    }
}
