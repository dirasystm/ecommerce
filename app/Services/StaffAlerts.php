<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Database;
use Waimart\Core\Logger;
use Waimart\Core\Money;
use Waimart\Core\Validator;

/**
 * Tells the staff who must act on a new order, by email and SMS, as soon as it is placed:
 *   - an M-Pesa order: everyone who can verify payments (payments.verify), and again when the customer submits the
 *     transaction code, with the code and the amount claimed, so it can be checked against the statement at once;
 *   - a cash-on-delivery order: everyone who can confirm orders (orders.manage).
 * Super administrators always receive them. Wording: Admin → Messages → Staff alerts.
 *
 * Sent after the customer's page has been delivered (the customer never waits for several emails), and never able to
 * break an order: a failure is logged and shows as failed in the Email & SMS log.
 */
final class StaffAlerts
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? App::db();
    }

    /** @param array<string,mixed> $order */
    public static function orderPlaced(array $order): void
    {
        self::later(fn () => (new self())->sendOrderPlaced($order));
    }

    /** @param array<string,mixed> $submission the payment_submissions row */
    public static function paymentSubmitted(int $orderId, array $submission): void
    {
        self::later(fn () => (new self())->sendPaymentSubmitted($orderId, $submission));
    }

    /** @param array<string,mixed> $order */
    public function sendOrderPlaced(array $order): int
    {
        $cod = ($order['payment_method'] ?? '') === 'cod';
        $vars = $this->orderVars($order) + [
            'action' => $cod ? 'Call the customer to confirm this cash-on-delivery order, then press Confirm order.'
                             : 'The customer pays by M-Pesa. Verify the transaction code as soon as it arrives.',
        ];
        return $this->send('staff_order_new', $cod ? 'orders.manage' : 'payments.verify', $vars, (int) $order['id']);
    }

    /** @param array<string,mixed> $s */
    public function sendPaymentSubmitted(int $orderId, array $s): int
    {
        $order = $this->db->one('SELECT * FROM orders WHERE id = ?', [$orderId]);
        if ($order === null) {
            return 0;
        }
        $vars = $this->orderVars($order) + [
            'transaction_code' => (string) ($s['transaction_code'] ?? ''),
            'amount_claimed' => Money::format((string) ($s['amount_claimed'] ?? '0')),
            'payer_phone' => Validator::displayPhone((string) ($s['payer_phone'] ?? '')),
            'payments_url' => self::url('/admin/payments'),
        ];
        return $this->send('staff_payment_submitted', 'payments.verify', $vars, $orderId);
    }

    /**
     * Active staff who hold the permission (through a role or a direct grant), plus every super administrator.
     * @return list<array{id:int,email:string,phone:?string,first_name:string}>
     */
    public function recipients(string $permission): array
    {
        return $this->db->all(
            "SELECT DISTINCT u.id, u.email, u.phone, u.first_name FROM users u
              WHERE u.is_staff = 1 AND u.status = 'active' AND (
                    EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id AND r.slug = 'super_admin')
                 OR EXISTS (SELECT 1 FROM user_roles ur JOIN role_permissions rp ON rp.role_id = ur.role_id JOIN permissions p ON p.id = rp.permission_id
                             WHERE ur.user_id = u.id AND p.slug = ?)
                 OR EXISTS (SELECT 1 FROM user_permissions up JOIN permissions p ON p.id = up.permission_id WHERE up.user_id = u.id AND p.slug = ?))
              ORDER BY u.id",
            [$permission, $permission]
        );
    }

    /** @param array<string,string> $vars */
    private function send(string $template, string $permission, array $vars, int $orderId): int
    {
        $sent = 0;
        foreach ($this->recipients($permission) as $r) {
            try {
                $sent += (new NotificationService())->sendTemplate($template, ['first_name' => (string) $r['first_name']] + $vars + MessageTemplates::contactVars(),
                    ['email' => (string) $r['email'], 'phone' => (string) ($r['phone'] ?? ''), 'prefs' => null], ['user_id' => (int) $r['id'], 'order_id' => $orderId]);
            } catch (\Throwable $e) {
                Logger::error('Staff alert failed', ['template' => $template, 'staff' => $r['id'], 'order_id' => $orderId, 'error' => $e->getMessage()]);
            }
        }
        return $sent;
    }

    /** @param array<string,mixed> $o @return array<string,string> */
    private function orderVars(array $o): array
    {
        $method = (string) ($o['payment_method'] ?? '');
        return [
            'order_number' => (string) $o['order_number'],
            'customer_name' => (string) $o['customer_name'],
            'customer_phone' => Validator::displayPhone((string) $o['customer_phone']),
            'order_total' => Money::format((string) $o['grand_total']),
            'payment_method' => $method === 'cod' ? 'Cash on delivery' : PaymentOptions::label($method),
            'fulfilment' => ($o['fulfilment_method'] ?? '') === 'pickup' ? 'Pickup station' : 'Home delivery',
            'order_url' => self::url('/admin/orders/' . (int) $o['id']),
        ];
    }

    private static function url(string $path): string
    {
        return rtrim((string) config('app.url'), '/') . $path;
    }

    /**
     * Runs the job once the customer's response has been delivered (LiteSpeed / PHP-FPM can finish the request
     * first); elsewhere it runs at the end of the request. Never throws.
     */
    private static function later(callable $job): void
    {
        $run = static function () use ($job): void {
            try {
                $job();
            } catch (\Throwable $e) {
                Logger::error('Staff alert failed', ['error' => $e->getMessage()]);
            }
        };
        if (PHP_SAPI === 'cli') {
            $run();                 // scripts and tests: at once
            return;
        }
        register_shutdown_function(static function () use ($run): void {
            if (function_exists('litespeed_finish_request')) {
                litespeed_finish_request();
            } elseif (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            $run();
        });
    }
}
