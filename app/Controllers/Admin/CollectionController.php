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
use Waimart\Services\StationReturnService;

/**
 * Pickup collection desk (B4): find a ready order, verify the customer's code and ID,
 * then hand over. Requires orders.collect.
 */
final class CollectionController extends AdminController
{
    public function index(Request $req): Response
    {
        $db = App::db();
        $tab = (string) ($req->query['tab'] ?? 'ready');
        $tab = in_array($tab, ['arriving', 'ready', 'returns'], true) ? $tab : 'ready';
        $point = (string) ($req->query['point'] ?? '');
        $holdDays = Settings::int('collection_hold_days', 7);
        $counts = $db->one(
            "SELECT (SELECT COUNT(*) FROM orders WHERE fulfilment_method = 'pickup' AND status = 'processing') AS arriving,
                    (SELECT COUNT(*) FROM orders o WHERE o.status = 'ready_for_collection' OR EXISTS (SELECT 1 FROM seller_orders so WHERE so.order_id = o.id AND so.status = 'ready_for_collection')) AS ready,
                    (SELECT COUNT(*) FROM station_returns WHERE status = 'awaiting_return') AS returns"
        );
        $params = [];
        $where = match ($tab) {
            // Pickup orders being prepared / on their way: "Arrived" makes them ready and tells the customer.
            'arriving' => ["o.fulfilment_method = 'pickup' AND o.status = 'processing'"],
            // Whole orders ready, or marketplace orders with at least one seller's part waiting at the station.
            'ready' => ["(o.status = 'ready_for_collection' OR EXISTS (SELECT 1 FROM seller_orders so WHERE so.order_id = o.id AND so.status = 'ready_for_collection'))"],
            'returns' => ['r.id IS NOT NULL'],
        };
        if (ctype_digit($point)) {
            $where[] = 'f.pickup_point_id = ?';
            $params[] = (int) $point;
        }
        $lp = \Waimart\Core\ListParams::from($req, ['ready' => 'f.ready_at', 'order' => 'o.order_number', 'since' => 'r.created_at'],
            $tab === 'returns' ? 'since' : 'ready', $tab === 'returns' ? 'desc' : 'asc');
        if ($lp->q !== '') {
            $where[] = '(o.order_number LIKE ? OR o.customer_name LIKE ?)';
            array_push($params, '%' . addcslashes(strtoupper($lp->q), '%_\\') . '%', $lp->like());
        }
        $rows = $lp->fetch($db, 'SELECT o.id, o.order_number, o.customer_name, o.payment_method, o.payment_status, o.grand_total, o.updated_at,
                    f.pickup_name, f.pickup_code, f.ready_at, f.collection_code_status, (f.ready_at < NOW() - INTERVAL ' . (int) $holdDays . ' DAY) AS overdue,
                    r.id AS return_id, r.status AS return_status, r.reason AS return_reason, r.created_at AS return_started, r.received_at, r.started_by',
            'FROM orders o JOIN order_fulfilment f ON f.order_id = o.id ' . ($tab === 'returns' ? 'JOIN' : 'LEFT JOIN') . ' station_returns r ON r.order_id = o.id
              WHERE ' . implode(' AND ', $where), $params, 'o.id');
        return $this->view('collections/index', [
            'title' => 'Collection desk', 'tab' => $tab, 'counts' => $counts, 'rows' => $rows, 'holdDays' => $holdDays, 'lp' => $lp, 'point' => $point,
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
        // Each item with the state of its seller part, so staff hand over only what is ready.
        $items = $db->all(
            'SELECT oi.product_name, oi.variant_name, oi.quantity, oi.sku, so.status AS part_status, so.sub_number, s.name AS seller_name
               FROM order_items oi JOIN seller_orders so ON so.id = oi.seller_order_id JOIN sellers s ON s.id = so.seller_id
              WHERE oi.order_id = ? ORDER BY so.id, oi.id',
            [$id]
        );
        return $this->view('collections/show', [
            'title' => 'Collect ' . $order['order_number'], 'order' => $order, 'items' => $items,
            'canReissue' => true,   // anyone at the desk: the new code goes to the customer only
            'canReturn' => $order['status'] === 'ready_for_collection',
        ]);
    }

    public function verify(Request $req): Response
    {
        $id = $this->intParam($req, 'id');
        $order = (new CollectionService())->verifyAndCollect(
            $id, (string) $req->input('code', ''), $this->actor(), (string) $req->input('id_checked', '') === '1',
            trim((string) $req->input('note', '')) ?: null
        );
        $cod = $order['payment_method'] === 'cod' && $order['status'] === 'collected'
            ? ' This is a cash-on-delivery order: collect ' . \Waimart\Core\Money::format(\Waimart\Services\SellerOrderService::amountDue($order)) . ' in cash from the customer.'
            : '';
        $what = $order['status'] === 'collected' ? "Order {$order['order_number']} collected." : "The ready parts of order {$order['order_number']} were collected; the code stays valid for the rest.";
        return $this->success("$what Stock has been deducted.$cod", '/admin/collections');
    }

    public function reissue(Request $req): Response
    {
        // The new code goes to the customer's own phone and email, never to the desk: station staff may send it.
        $id = $this->intParam($req, 'id');
        (new CollectionService())->reissueCode($id, $this->actor());
        return $this->success('A new collection code has been sent to the customer. The old code no longer works.', "/admin/collections/$id");
    }

    /** POST /admin/collections/{id}/arrived: the parcel reached the station; the customer is sent their code. */
    public function arrived(Request $req): Response
    {
        $id = $this->intParam($req, 'id');
        $o = (new StationReturnService())->arrived($id, $this->actor());
        return $this->success("{$o['order_number']} is ready for collection. The customer has been sent the station, their collection code, a reminder to bring ID and the last day to collect.",
            '/admin/collections?tab=arriving');
    }

    /** POST /admin/collections/{id}/return: send a waiting parcel back to the warehouse now (e.g. the customer refused it). */
    public function startReturn(Request $req): Response
    {
        $id = $this->intParam($req, 'id');
        $o = (new StationReturnService())->start($id, $this->actor(), (string) $req->input('reason', ''), trim((string) $req->input('note', '')) ?: null);
        return $this->success("{$o['order_number']} is being returned to the warehouse and the order is cancelled" . ($o['payment_status'] === 'refund_due' ? '; a refund has been recorded for Finance' : '') . '. The customer has been told.',
            '/admin/collections?tab=returns');
    }

    /** POST /admin/collections/returns/{id}/received: the parcel is back at the warehouse; its stock is available again. */
    public function received(Request $req): Response
    {
        $r = (new StationReturnService())->receive($this->intParam($req, 'id'), $this->actor(), trim((string) $req->input('note', '')) ?: null);
        return $this->success('Received back. ' . $r['items_released'] . ' item line' . ($r['items_released'] === 1 ? '' : 's') . ' returned to available stock.', '/admin/collections?tab=returns');
    }
}
