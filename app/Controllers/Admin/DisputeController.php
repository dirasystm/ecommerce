<?php

declare(strict_types=1);

namespace Waimart\Controllers\Admin;

use Waimart\Core\App;
use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Services\DisputeService;
use Waimart\Services\RefundService;

/** Waimart's desk for seller disputes (disputes.manage). */
final class DisputeController extends AdminController
{
    public function index(Request $req): Response
    {
        $filter = in_array($req->query['show'] ?? 'open', ['open', 'closed', 'all'], true) ? (string) ($req->query['show'] ?? 'open') : 'open';
        $lp = \Waimart\Core\ListParams::from($req, ['status' => "FIELD(d.status, 'open', 'awaiting_seller', 'awaiting_customer', 'resolved', 'closed')", 'updated' => 'd.updated_at'], 'status', 'asc');
        $rows = (new DisputeService())->page(null, $filter, $lp);
        return $this->view('disputes/index', ['title' => 'Seller cases', 'filter' => $filter, 'rows' => $rows, 'lp' => $lp]);
    }

    public function show(Request $req): Response
    {
        $svc = new DisputeService();
        $d = $svc->find($this->intParam($req, 'id'));
        return $this->view('disputes/show', [
            'title' => 'Case ' . $d['dispute_number'], 'd' => $d, 'messages' => $svc->messages((int) $d['id'], true),
            'part' => App::db()->one('SELECT so.status, so.items_subtotal, so.discount_share, so.earnings_status, o.payment_status, o.customer_phone
                                        FROM seller_orders so JOIN orders o ON o.id = so.order_id WHERE so.id = ?', [$d['seller_order_id']]),
            'refundable' => (new RefundService())->refundableForPart((int) $d['seller_order_id']),
            'refunds' => App::db()->all('SELECT * FROM refunds WHERE seller_order_id = ? ORDER BY id', [$d['seller_order_id']]),
        ]);
    }

    public function message(Request $req): Response
    {
        $id = $this->intParam($req, 'id');
        (new DisputeService())->message($id, 'staff', $this->actor(), (string) $req->input('body', ''), !empty($req->post['internal']));
        return $this->success(!empty($req->post['internal']) ? 'Internal note added.' : 'Message sent to the customer and the seller.', "/admin/disputes/$id");
    }

    public function status(Request $req): Response
    {
        $id = $this->intParam($req, 'id');
        (new DisputeService())->setStatus($id, $this->actor(), (string) $req->input('status', ''));
        return $this->success('Case status updated.', "/admin/disputes/$id");
    }

    public function resolve(Request $req): Response
    {
        $id = $this->intParam($req, 'id');
        (new DisputeService())->resolve($id, $this->actor(), (string) $req->input('resolution', ''), (string) $req->input('resolution_note', ''), (string) $req->input('refund_amount', ''));
        return $this->success('Case resolved. The customer and the seller have been told.', "/admin/disputes/$id");
    }
}
