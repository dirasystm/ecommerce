<?php

declare(strict_types=1);

namespace Waimart\Controllers\Admin;

use Waimart\Core\App;
use Waimart\Core\Auth;
use Waimart\Core\HttpException;
use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Core\ValidationException;
use Waimart\Repositories\OrderRepository;
use Waimart\Services\OrderNotifier;
use Waimart\Services\OrderWorkflow;

/**
 * Staff order management. Staff can only request workflow transitions — never set a status
 * directly — and each target status needs its own permission.
 */
final class OrderController extends AdminController
{
    /** target status => permission required. Pickup-specific steps arrive in Phase 8, payment confirmation in Phase 9. */
    public const ACTION_PERMISSIONS = [
        'confirmed' => 'orders.manage',
        'processing' => 'orders.manage',
        'packed' => 'orders.manage',
        'dispatched' => 'orders.deliver',
        'out_for_delivery' => 'orders.deliver',
        'delivered' => 'orders.deliver',
        'ready_for_collection' => 'orders.manage',
        'cancelled' => 'orders.cancel',
        // 'collected' is deliberately absent: it is only possible at the collection desk with the customer's code.
    ];

    private const PER_PAGE = 30;

    public function index(Request $req): Response
    {
        $db = App::db();
        $where = ['1 = 1'];
        $params = [];
        $status = (string) ($req->query['status'] ?? '');
        if (isset(OrderWorkflow::TRANSITIONS[$status])) {
            $where[] = 'o.status = ?';
            $params[] = $status;
        }
        $method = (string) ($req->query['method'] ?? '');
        if (in_array($method, ['delivery', 'pickup'], true)) {
            $where[] = 'o.fulfilment_method = ?';
            $params[] = $method;
        }
        $q = trim((string) ($req->query['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(o.order_number LIKE ? OR o.customer_phone LIKE ? OR o.customer_name LIKE ?)';
            $like = '%' . addcslashes(mb_substr($q, 0, 60), '%_\\') . '%';
            array_push($params, $like, '%' . (\Waimart\Core\Validator::normalizePhone($q) ?? $like) . '%', $like);
        }
        $w = implode(' AND ', $where);
        $total = (int) $db->value("SELECT COUNT(*) FROM orders o WHERE $w", $params);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($pages, (int) ($req->query['page'] ?? 1)));
        $rows = $db->all(
            "SELECT o.id, o.order_number, o.status, o.payment_method, o.payment_status, o.fulfilment_method, o.customer_name,
                    o.grand_total, o.placed_at,
                    (SELECT MIN(expires_at) FROM stock_reservations r WHERE r.order_id = o.id AND r.status = 'active') AS hold_until
               FROM orders o WHERE $w ORDER BY o.placed_at DESC, o.id DESC
              LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE),
            $params
        );
        $counts = array_column($db->all('SELECT status, COUNT(*) AS n FROM orders GROUP BY status'), 'n', 'status');
        return $this->view('orders/index', compact('rows', 'total', 'page', 'pages', 'counts', 'status') + ['title' => 'Orders']);
    }

    /** Staff step on one seller's part of an order (hand-over, dispatch, delivery, pickup readiness, part cancellation). */
    public function sellerOrderTransition(Request $req): Response
    {
        $id = $this->intParam($req, 'id');
        $to = (string) $req->input('to', '');
        \Waimart\Core\Auth::authorize(match ($to) {
            'dispatched', 'out_for_delivery', 'delivered' => 'orders.deliver',
            'cancelled' => 'orders.cancel',
            default => 'orders.manage',    // hand-over, ready for collection, the Official Store's own accept/prepare/pack
        });
        $so = (new \Waimart\Services\SellerOrderWorkflow())->transition($id, $to, ['type' => 'staff', 'id' => $this->actor()], [
            'reason' => (string) $req->input('reason', ''), 'note' => (string) $req->input('note', ''),
            'tracking_reference' => (string) $req->input('tracking_reference', ''),
        ]);
        return $this->success('Updated ' . $so['sub_number'] . ': ' . strtolower(\Waimart\Services\OrderPresenter::sellerOrderStatus((string) $so['status'])) . '.',
            '/admin/orders/' . $so['order_id']);
    }

    /** All sellers' parts that need Waimart's attention (oversight). */
    public function sellerOrders(Request $req): Response
    {
        $db = App::db();
        $status = (string) ($req->query['status'] ?? 'packed');
        $statuses = array_keys(\Waimart\Services\OrderPresenter::SELLER_ORDER_LABELS);
        $status = in_array($status, $statuses, true) ? $status : 'packed';
        $params = [$status];
        $where = 'so.status = ? AND o.confirmed_at IS NOT NULL';
        $seller = (string) ($req->query['seller'] ?? '');
        if (ctype_digit($seller)) {
            $where .= ' AND so.seller_id = ?';
            $params[] = (int) $seller;
        }
        return $this->view('orders/seller-orders', [
            'title' => 'Seller orders', 'status' => $status,
            'counts' => array_map('intval', array_column($db->all(
                'SELECT so.status, COUNT(*) AS n FROM seller_orders so JOIN orders o ON o.id = so.order_id WHERE o.confirmed_at IS NOT NULL GROUP BY so.status'), 'n', 'status')),
            'rows' => $db->all(
                "SELECT so.id, so.order_id, so.sub_number, so.status, so.items_subtotal, so.updated_at, so.dispatch_note, s.name AS seller_name, s.id AS seller_id,
                        o.fulfilment_method, o.order_number, f.pickup_name, f.town, f.county_name
                   FROM seller_orders so JOIN orders o ON o.id = so.order_id JOIN sellers s ON s.id = so.seller_id
                   LEFT JOIN order_fulfilment f ON f.order_id = o.id
                  WHERE $where ORDER BY so.updated_at LIMIT 300", $params),
            'sellers' => $db->all('SELECT id, name FROM sellers ORDER BY is_platform DESC, name'),
        ]);
    }

    public function show(Request $req): Response
    {
        $db = App::db();
        $order = $db->one('SELECT * FROM orders WHERE id = ?', [$this->intParam($req, 'id')]) ?? throw HttpException::notFound();
        $details = (new OrderRepository($db))->details($order);
        $details['history'] = $db->all(
            'SELECT h.*, u.first_name, u.last_name FROM order_status_history h LEFT JOIN users u ON u.id = h.actor_user_id
              WHERE h.order_id = ? ORDER BY h.id',
            [$order['id']]
        );
        $reservations = $db->all(
            'SELECT r.*, v.sku FROM stock_reservations r JOIN product_variants v ON v.id = r.variant_id WHERE r.order_id = ? ORDER BY r.id',
            [$order['id']]
        );
        $movements = $db->all(
            'SELECT m.*, v.sku FROM stock_movements m JOIN product_variants v ON v.id = m.variant_id WHERE m.order_id = ? ORDER BY m.id',
            [$order['id']]
        );
        $actions = array_values(array_filter(
            OrderWorkflow::allowedTargets($order),
            fn ($to) => isset(self::ACTION_PERMISSIONS[$to]) && Auth::can(self::ACTION_PERMISSIONS[$to])
                // M-Pesa orders are confirmed through payment verification (Phase 9), not this button.
                && !($to === 'confirmed' && $order['payment_method'] !== 'cod')
        ));
        // Orders with marketplace sellers: fulfilment happens per seller part, so order-level fulfilment buttons go.
        $marketplace = \Waimart\Services\SellerOrderService::hasMarketplaceSeller((int) $order['id'], $db);
        $partActions = [];
        if ($marketplace) {
            $actions = array_values(array_intersect($actions, ['confirmed', 'cancelled']));
            $wf = new \Waimart\Services\SellerOrderWorkflow($db);
            foreach ($details['groups'] as $g) {
                $partActions[(int) $g['id']] = array_values(array_filter($wf->allowedNext($g, false), fn ($to) => Auth::can(match ($to) {
                    'dispatched', 'out_for_delivery', 'delivered' => 'orders.deliver',
                    'cancelled' => 'orders.cancel',
                    'collected' => 'orders.collect',
                    default => 'orders.manage',
                }) && $to !== 'collected'));   // collection happens at the collection desk (code check)
            }
        }
        return $this->view('orders/show', [
            'title' => 'Order ' . $order['order_number'], 'order' => $details, 'reservations' => $reservations,
            'movements' => $movements, 'actions' => $actions, 'marketplace' => $marketplace, 'partActions' => $partActions,
        ]);
    }

    public function transition(Request $req): Response
    {
        $id = $this->intParam($req, 'id');
        $to = (string) $req->input('to', '');
        if (!isset(self::ACTION_PERMISSIONS[$to])) {
            throw ValidationException::withMessage('That action is not available here.');
        }
        Auth::authorize(self::ACTION_PERMISSIONS[$to]);       // per-target permission (A6, H)
        $reason = trim((string) $req->input('reason', ''));
        if ($to === 'cancelled' && mb_strlen($reason) < 3) {
            throw new ValidationException(['reason' => 'Give a reason for cancelling (it is shown to the customer and audited).']);
        }
        $order = App::db()->one('SELECT * FROM orders WHERE id = ?', [$id]) ?? throw HttpException::notFound();
        if ($to === 'confirmed' && $order['payment_method'] !== 'cod') {
            throw ValidationException::withMessage('M-Pesa orders are confirmed by verifying the payment.');
        }
        if ($to === 'ready_for_collection') {
            (new \Waimart\Services\CollectionService())->markReady($id, $this->actor());     // generates and sends the code
            return $this->success('Marked ready for collection. The customer has been sent their collection code.', "/admin/orders/$id");
        }
        $updated = (new OrderWorkflow())->transition($id, $to, ['type' => 'staff', 'id' => $this->actor()],
            trim((string) $req->input('note', '')) ?: null,
            ['reason' => $reason, 'confirmation' => trim((string) $req->input('confirmation', '')) ?: 'Confirmed by staff']
        );
        OrderNotifier::statusChanged($updated);
        return $this->success('Order updated: ' . \Waimart\Services\OrderPresenter::status($to) . '.', "/admin/orders/$id");
    }
}
