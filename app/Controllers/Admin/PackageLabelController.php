<?php

declare(strict_types=1);

namespace Waimart\Controllers\Admin;

use Waimart\Core\App;
use Waimart\Core\HttpException;
use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Core\View;

/**
 * Printable package labels (100 x 150 mm stickers, or A4): store, customer, phone, customer number, pickup station or
 * delivery address, the products, and a Code 128 barcode of the order number that the pickup station scans on arrival
 * and at hand-over.
 */
final class PackageLabelController extends AdminController
{
    /** GET /admin/orders/{id}/label — one label. */
    public function one(Request $req): Response
    {
        return $this->render([$this->intParam($req, 'id')]);
    }

    /** GET /admin/labels?orders=1,2,3 — several labels, one per page. */
    public function many(Request $req): Response
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) ($req->query['orders'] ?? ''))))));
        if (!$ids) {
            \Waimart\Core\Session::flash('info', 'Choose the parcels to print labels for: "Print all labels on this page", or "Label" on one of them.');
            return Response::redirect('/admin/collections?tab=arriving');
        }
        return $this->render(array_slice($ids, 0, 100));
    }

    /** @param list<int> $ids */
    private function render(array $ids): Response
    {
        $db = App::db();
        $in = implode(',', array_fill(0, count($ids), '?'));
        $orders = $db->all(
            "SELECT o.id, o.order_number, o.user_id, o.customer_name, o.customer_phone, o.payment_method, o.payment_status, o.grand_total, o.placed_at, o.status,
                    f.method, f.county_name, f.town, f.location, f.delivery_instructions, f.pickup_code, f.pickup_name, f.pickup_town, f.pickup_county
               FROM orders o JOIN order_fulfilment f ON f.order_id = o.id WHERE o.id IN ($in) ORDER BY FIELD(o.id, $in)",
            array_merge($ids, $ids)
        );
        if (!$orders) {
            throw HttpException::notFound();
        }
        foreach ($orders as &$o) {
            $o['items'] = $db->all('SELECT product_name, variant_name, quantity FROM order_items WHERE order_id = ? ORDER BY id', [$o['id']]);
            $o['amount_due'] = $o['payment_method'] === 'cod' ? \Waimart\Services\SellerOrderService::amountDue($o) : '0.00';
        }
        unset($o);
        \Waimart\Services\AuditLogger::log('order.label_printed', 'order', count($orders) === 1 ? (int) $orders[0]['id'] : null, null,
            ['orders' => array_column($orders, 'order_number')], $this->actor());
        return Response::html(View::render('admin/orders/labels', ['orders' => $orders], null))
            ->withHeader('Cache-Control', 'no-store, private')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
