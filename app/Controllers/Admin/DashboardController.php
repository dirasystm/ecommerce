<?php

declare(strict_types=1);

namespace Waimart\Controllers\Admin;

use Waimart\Core\App;
use Waimart\Core\Auth;
use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Core\Settings;
use Waimart\Services\ReportService;

/** Operations dashboard. Every widget is shown only to staff with the matching permission. */
final class DashboardController extends AdminController
{
    public function index(Request $req): Response
    {
        $db = App::db();
        $data = ['title' => 'Dashboard'];

        // Revenue figures are financial data: reports.view only (orders.view alone sees the order list).
        if (Auth::can('reports.view')) {
            $reports = new ReportService($db);
            [$todayFrom, $todayTo] = ReportService::range(date('Y-m-d'), date('Y-m-d'));
            [$f7, $t7] = ReportService::range(null, null, 7);
            [$fPrev, $tPrev] = ReportService::range(date('Y-m-d', strtotime('-13 days')), date('Y-m-d', strtotime('-7 days')));
            [$f14, $t14] = ReportService::range(null, null, 14);
            $data['today'] = $reports->salesSummary($todayFrom, $todayTo);
            $data['week'] = $reports->salesSummary($f7, $t7);
            $data['prevWeek'] = $reports->salesSummary($fPrev, $tPrev);
            $data['daily'] = $reports->daily($f14, $t14);
        }

        // Work queues: what needs a person right now.
        $holdDays = Settings::int('collection_hold_days', 7);
        $q = $db->one(
            "SELECT
               (SELECT COUNT(*) FROM orders WHERE status = 'pending_confirmation') AS cod_to_confirm,
               (SELECT COUNT(*) FROM payment_submissions WHERE status = 'pending') AS payments_to_verify,
               (SELECT COUNT(*) FROM order_resolutions WHERE status = 'open') AS resolutions,
               (SELECT COUNT(*) FROM orders WHERE status IN ('confirmed','processing')) AS to_prepare,
               (SELECT COUNT(*) FROM orders WHERE status = 'packed') AS to_dispatch,
               (SELECT COUNT(*) FROM orders o JOIN order_fulfilment f ON f.order_id = o.id
                 WHERE o.status = 'ready_for_collection' AND f.ready_at < NOW() - INTERVAL ? DAY) AS overdue_pickups,
               (SELECT COUNT(*) FROM orders o LEFT JOIN cod_collections c ON c.order_id = o.id
                 WHERE o.payment_method = 'cod' AND o.status IN ('delivered','collected') AND c.id IS NULL) AS cod_unrecorded,
               (SELECT COUNT(*) FROM refunds WHERE status = 'due') AS refunds_due,
               (SELECT COALESCE(SUM(amount),0) FROM refunds WHERE status = 'due') AS refunds_due_amount,
               (SELECT COUNT(*) FROM product_variants v JOIN inventory i ON i.variant_id = v.id
                 WHERE v.is_active = 1 AND i.on_hand - i.reserved <= 0) AS out_of_stock,
               (SELECT COUNT(*) FROM product_variants v JOIN inventory i ON i.variant_id = v.id
                 WHERE v.is_active = 1 AND i.on_hand - i.reserved > 0 AND i.on_hand - i.reserved <= i.low_stock_threshold) AS low_stock",
            [$holdDays]
        );
        $data['queues'] = $q;

        if (Auth::can('inventory.view')) {
            $data['lowStock'] = $db->all(
                'SELECT v.id, v.sku, p.name AS product, v.name AS variant, (i.on_hand - i.reserved) AS available, i.low_stock_threshold
                   FROM product_variants v JOIN inventory i ON i.variant_id = v.id JOIN products p ON p.id = v.product_id
                  WHERE v.is_active = 1 AND p.is_active = 1 AND i.on_hand - i.reserved <= i.low_stock_threshold
                  ORDER BY (i.on_hand - i.reserved), p.name LIMIT 8'
            );
        }
        if (Auth::can('orders.view')) {
            $data['recentOrders'] = $db->all(
                'SELECT id, order_number, status, customer_name, grand_total, placed_at, fulfilment_method FROM orders ORDER BY id DESC LIMIT 8'
            );
        }
        return $this->view('dashboard', $data);
    }
}
