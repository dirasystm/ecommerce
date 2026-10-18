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
    private const NOTIFY = [
        'confirmed' => 'Your order {n} has been confirmed.',
        'dispatched' => 'Your order {n} has been dispatched and is on its way.',
        'out_for_delivery' => 'Your order {n} is out for delivery today.',
        'delivered' => 'Your order {n} has been delivered. Thank you for shopping with Waimart!',
        'ready_for_collection' => 'Your order {n} is ready for collection.',
        'collected' => 'Your order {n} has been collected. Thank you for shopping with Waimart!',
        'cancelled' => 'Your order {n} has been cancelled.',
        'expired' => 'The reservation for your order {n} expired before it was confirmed, so the items have been released. You are welcome to order again.',
        'requires_resolution' => 'Our team is reviewing your order {n} and will contact you about the next steps.',
    ];

    public static function statusChanged(array $order): void
    {
        if ($order['status'] === 'confirmed') {
            try {
                // Marketplace sellers start preparing once the order is confirmed (paid / COD confirmed).
                (new SellerOrderService())->notifyConfirmed((int) $order['id']);
            } catch (\Throwable $e) {
                \Waimart\Core\Logger::error('Seller notification failed', ['order_id' => $order['id'], 'error' => $e->getMessage()]);
            }
        }
        $tpl = self::NOTIFY[$order['status']] ?? null;
        if ($tpl === null) {
            return;
        }
        try {
            $db = App::db();
            $user = $order['user_id'] ? $db->one('SELECT notify_email, notify_sms FROM users WHERE id = ?', [$order['user_id']]) : null;
            $text = str_replace('{n}', (string) $order['order_number'], $tpl);
            if ($order['status'] === 'cancelled' && $order['payment_status'] === 'refund_due') {
                $text .= ' Because your payment was already received, our team will arrange your refund.';
            }
            $track = rtrim((string) config('app.url'), '/') . '/track-order';
            $ctx = ['user_id' => $order['user_id'] ? (int) $order['user_id'] : null, 'order_id' => (int) $order['id']];

            // Ready for collection: the code is essential, so it is always sent (preferences apply to
            // optional updates only) and redacted from the stored outbox copy.
            $essential = false;
            if ($order['status'] === 'ready_for_collection') {
                $code = CollectionService::codeFor($order);
                $f = $db->one('SELECT pickup_name, pickup_address, pickup_town, pickup_hours FROM order_fulfilment WHERE order_id = ?', [$order['id']]);
                $hold = \Waimart\Core\Settings::int('collection_hold_days', 7);
                $text .= " Collection code: $code. Collect at {$f['pickup_name']}, {$f['pickup_address']}, {$f['pickup_town']} ({$f['pickup_hours']})"
                    . " within $hold days. Bring this code and your ID. Never share the code with anyone who calls you.";
                $ctx['secrets'] = [(string) $code];
                $essential = true;
            }

            $n = new NotificationService();
            if ($order['customer_email'] && ($essential || $user === null || (int) $user['notify_email'] === 1)) {
                $n->email((string) $order['customer_email'], "Order {$order['order_number']}: " . OrderPresenter::status((string) $order['status']),
                    "Hello {$order['customer_name']},\n\n$text\n\nTrack your order at $track using your order number and phone number.\n\nWaimart",
                    'order_' . $order['status'], $ctx);
            }
            if ($essential || $user === null || (int) $user['notify_sms'] === 1) {
                $n->sms((string) $order['customer_phone'], "Waimart: $text", 'order_' . $order['status'], $ctx);
            }
        } catch (\Throwable $e) {
            Logger::error('Status notification failed', ['order_id' => $order['id'] ?? null, 'error' => $e->getMessage()]);
        }
    }
}
