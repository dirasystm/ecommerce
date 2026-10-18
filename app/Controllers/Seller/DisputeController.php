<?php

declare(strict_types=1);

namespace Waimart\Controllers\Seller;

use Waimart\Core\App;
use Waimart\Core\Auth;
use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Services\DisputeService;
use Waimart\Services\SellerContext;

/** Returns and cases (disputes) on the seller's own parts. Ids are scoped to the current seller: others are 404. */
final class DisputeController extends SellerController
{
    public function index(Request $req): Response
    {
        $filter = in_array($req->query['show'] ?? 'open', ['open', 'closed', 'all'], true) ? (string) ($req->query['show'] ?? 'open') : 'open';
        $returns = App::db()->all(
            "SELECT r.id, r.return_number, r.status, r.reason, r.requested_at, r.refund_amount, so.id AS so_id, so.sub_number,
                    (SELECT SUM(quantity) FROM return_items ri WHERE ri.return_id = r.id) AS units
               FROM return_requests r JOIN seller_orders so ON so.id = r.seller_order_id
              WHERE so.seller_id = ? ORDER BY r.status IN ('requested','approved','received') DESC, r.requested_at DESC LIMIT 100",
            [$this->sellerId()]
        );
        return $this->view('disputes/index', [
            'title' => 'Returns & cases', 'filter' => $filter,
            'cases' => (new DisputeService())->list($this->sellerId(), $filter),
            'returns' => $returns,
        ]);
    }

    public function show(Request $req): Response
    {
        $svc = new DisputeService();
        $d = $svc->findForSeller($this->sellerId(), $this->id($req));
        return $this->view('disputes/show', ['title' => 'Case ' . $d['dispute_number'], 'd' => $d, 'messages' => $svc->messages((int) $d['id'], false),
            'canReply' => in_array(SellerContext::role(), ['owner', 'manager'], true) && $this->seller()['status'] === 'active']);
    }

    public function message(Request $req): Response
    {
        SellerContext::requireRole('owner', 'manager');
        $svc = new DisputeService();
        $d = $svc->findForSeller($this->sellerId(), $this->id($req));   // 404 for anyone else's case
        $svc->message((int) $d['id'], 'seller', (int) Auth::id(), (string) $req->input('body', ''));
        return $this->success('Reply sent.', '/seller/disputes/' . $d['id']);
    }

    private function id(Request $req): int
    {
        $v = (string) $req->param('id');
        return ctype_digit($v) ? (int) $v : 0;
    }
}
