<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Auth;
use Waimart\Core\Database;

/**
 * What waits for a member of staff right now: the red counts in the admin menu and the "pending orders" reminder.
 * Each queue is counted only for staff whose permissions let them act on it, so nobody is nagged about work that is
 * not theirs. One small query per page (and per 30-second refresh).
 */
final class AdminAlerts
{
    /** Orders not yet acted on, and who acts: COD orders to confirm, M-Pesa codes to verify, orders needing a resolution. */
    public const ORDER_STEPS = [
        'pending_confirmation' => 'orders.manage',
        'awaiting_verification' => 'payments.verify',
        'requires_resolution' => 'resolutions.manage',
    ];

    /** Menu link => [permission, SQL count]. (The Orders link is counted from ORDER_STEPS.) */
    public const QUEUES = [
        '/admin/seller-orders' => ['orders.view', "(SELECT COUNT(*) FROM seller_orders so JOIN orders o ON o.id = so.order_id WHERE so.status = 'packed' AND o.confirmed_at IS NOT NULL)"],
        '/admin/collections' => ['orders.collect', "(SELECT COUNT(*) FROM orders WHERE status = 'ready_for_collection')"],
        '/admin/returns' => ['returns.manage', "(SELECT COUNT(*) FROM return_requests WHERE status IN ('requested','received'))"],
        '/admin/reviews' => ['reviews.moderate', "(SELECT COUNT(*) FROM reviews WHERE status = 'pending')"],
        '/admin/payments' => ['payments.view', "(SELECT COUNT(*) FROM payment_submissions WHERE status = 'pending')"],
        '/admin/resolutions' => ['resolutions.manage', "(SELECT COUNT(*) FROM order_resolutions WHERE status = 'open')"],
        '/admin/cod' => ['cod.collect', "(SELECT COUNT(*) FROM orders o LEFT JOIN cod_collections c ON c.order_id = o.id WHERE o.payment_method = 'cod' AND o.status IN ('delivered','collected') AND c.id IS NULL)"],
        '/admin/refunds' => ['refunds.manage', "(SELECT COUNT(*) FROM refunds WHERE status = 'due')"],
        '/admin/sellers/applications' => ['sellers.view', "(SELECT COUNT(*) FROM seller_applications WHERE status IN ('submitted','under_review'))"],
        '/admin/moderation/products' => ['products.moderate', "(SELECT COUNT(*) FROM products WHERE moderation_status = 'pending')"],
        '/admin/disputes' => ['disputes.manage', "(SELECT COUNT(*) FROM seller_disputes WHERE status = 'open')"],
        '/admin/pickup-points/applications' => ['pickup_points.approve', "(SELECT COUNT(*) FROM pickup_station_applications WHERE status = 'pending')"],
    ];

    private static ?array $cache = null;

    /** @return array<string,int> menu link => count, only the queues the signed-in staff member can act on */
    public static function counts(?Database $db = null): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $u = (array) Auth::user();
        if ((int) ($u['must_change_password'] ?? 0) === 1 || (MfaService::requiredForStaff() && !MfaService::enabled($u))) {
            return self::$cache = [];   // until the password change / two-step set-up is done, no work is shown
        }
        $mine = array_filter(self::QUEUES, fn ($q) => Auth::can($q[0]));
        if ($steps = self::mySteps()) {
            $in = "'" . implode("','", $steps) . "'";   // constant status names, never input
            $mine = ['/admin/orders' => ['orders.view', "(SELECT COUNT(*) FROM orders WHERE status IN ($in))"]] + $mine;
        }
        if (!$mine) {
            return self::$cache = [];
        }
        $cols = [];
        $i = 0;
        foreach ($mine as $sql) {
            $cols[] = $sql[1] . ' AS q' . $i++;
        }
        $row = array_values((array) ($db ?? App::db())->one('SELECT ' . implode(', ', $cols)));
        return self::$cache = array_combine(array_keys($mine), array_map('intval', $row));
    }

    /**
     * The reminder: orders waiting for this person (empty when none, or when they cannot act on orders).
     * @return array{count:int,message:string,url:string}|null
     */
    public static function pendingOrders(): ?array
    {
        $n = self::counts()['/admin/orders'] ?? 0;
        if ($n <= 0) {
            return null;
        }
        return ['count' => $n, 'message' => $n === 1 ? '1 order is waiting for your action.' : "$n orders are waiting for your action.",
                'url' => url('/admin/orders', ['status' => self::firstStatus()])];
    }

    /** @return list<string> the order statuses this person can move on (none without access to the order list) */
    private static function mySteps(): array
    {
        if (!Auth::can('orders.view')) {
            return [];
        }
        return array_keys(array_filter(self::ORDER_STEPS, fn ($perm) => Auth::can($perm)));
    }

    /** The tab to open: COD to confirm first, then payments to verify. */
    private static function firstStatus(): ?string
    {
        $db = App::db();
        foreach (self::mySteps() as $s) {
            if ($db->value('SELECT 1 FROM orders WHERE status = ? LIMIT 1', [$s])) {
                return $s;
            }
        }
        return null;
    }

    /** For tests: forget the counts of this request. */
    public static function flush(): void
    {
        self::$cache = null;
    }
}
