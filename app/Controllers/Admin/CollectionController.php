<?php

declare(strict_types=1);

namespace Waimart\Controllers\Admin;

use Waimart\Core\App;
use Waimart\Core\Auth;
use Waimart\Core\HttpException;
use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Core\Settings;
use Waimart\Services\CollectionService;

/**
 * Pickup collection desk (B4): find a ready order, verify the customer's code and ID,
 * then hand over. Requires orders.collect.
 */
final class CollectionController extends AdminController
{
    public function index(Request $req): Response
    {
        $db = App::db();
        $where = ["o.status = 'ready_for_collection'"];
        $params = [];
        $point = (string) ($req->query['point'] ?? '');
        if (ctype_digit($point)) {
            $where[] = 'f.pickup_point_id = ?';
            $params[] = (int) $point;
        }
        $q = strtoupper(trim((string) ($req->query['q'] ?? '')));
        if ($q !== '') {
            $where[] = 'o.order_number LIKE ?';
            $params[] = '%' . addcslashes(mb_substr($q, 0, 20), '%_\\') . '%';
        }
        $holdDays = Settings::int('collection_hold_days', 7);
        $rows = $db->all(
            'SELECT o.id, o.order_number, o.customer_name, o.payment_method, o.payment_status, o.grand_total,
                    f.pickup_name, f.pickup_code, f.ready_at, f.collection_code_status,
                    (f.ready_at < NOW() - INTERVAL ? DAY) AS overdue
               FROM orders o JOIN order_fulfilment f ON f.order_id = o.id
              WHERE ' . implode(' AND ', $where) . ' ORDER BY f.ready_at',
            [$holdDays, ...$params]
        );
        return $this->view('collections/index', [
            'title' => 'Collection desk', 'rows' => $rows, 'holdDays' => $holdDays,
            'points' => $db->all('SELECT id, name, code FROM pickup_points ORDER BY name'),
        ]);
    }

    public function show(Request $req): Response
    {
        $db = App::db();
        $id = $this->intParam($req, 'id');
        $order = $db->one(
            "SELECT o.*, f.pickup_name, f.pickup_code, f.ready_at, f.collection_code_status
               FROM orders o JOIN order_fulfilment f ON f.order_id = o.id WHERE o.id = ? AND o.fulfilment_method = 'pickup'",
            [$id]
        ) ?? throw HttpException::notFound();
        $items = $db->all('SELECT product_name, variant_name, quantity, sku FROM order_items WHERE order_id = ?', [$id]);
        return $this->view('collections/show', [
            'title' => 'Collect ' . $order['order_number'], 'order' => $order, 'items' => $items,
            'canReissue' => Auth::can('orders.manage'),
        ]);
    }

    public function verify(Request $req): Response
    {
        $id = $this->intParam($req, 'id');
        $order = (new CollectionService())->verifyAndCollect(
            $id, (string) $req->input('code', ''), $this->actor(), (string) $req->input('id_checked', '') === '1',
            trim((string) $req->input('note', '')) ?: null
        );
        $cod = $order['payment_method'] === 'cod'
            ? ' This is a cash-on-delivery order: collect ' . \Waimart\Core\Money::format((string) $order['grand_total']) . ' in cash from the customer.'
            : '';
        return $this->success("Order {$order['order_number']} collected. Stock has been deducted.$cod", '/admin/collections');
    }

    public function reissue(Request $req): Response
    {
        Auth::authorize('orders.manage');
        $id = $this->intParam($req, 'id');
        (new CollectionService())->reissueCode($id, $this->actor());
        return $this->success('A new collection code has been sent to the customer. The old code no longer works.', "/admin/collections/$id");
    }
}
