<?php

declare(strict_types=1);

namespace Waimart\Controllers\Seller;

use Waimart\Core\Auth;
use Waimart\Core\HttpException;
use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Services\SellerContext;
use Waimart\Services\SellerOrderService;
use Waimart\Services\SellerOrderWorkflow;

/**
 * The seller's parts of customer orders. Ids are always looked up within the current seller (SellerContext), so
 * another seller's part — or an order not yet confirmed — is a 404.
 */
final class OrderController extends SellerController
{
    public function index(Request $req): Response
    {
        $tab = (string) ($req->query['tab'] ?? 'new');
        $tab = isset(SellerOrderService::SELLER_TABS[$tab]) ? $tab : 'new';
        $svc = new SellerOrderService();
        return $this->view('orders/index', [
            'title' => 'Orders', 'tab' => $tab,
            'rows' => $svc->forSeller($this->sellerId(), $tab),
            'counts' => $svc->sellerCounts($this->sellerId()),
        ]);
    }

    public function show(Request $req): Response
    {
        $so = (new SellerOrderService())->sellerView($this->sellerId(), $this->id($req)) ?? throw HttpException::notFound();
        return $this->view('orders/show', [
            'title' => 'Order ' . $so['sub_number'], 'so' => $so,
            'next' => (new SellerOrderWorkflow())->allowedNext($so, true),
            'returns' => \Waimart\Core\App::db()->all('SELECT return_number, status, refund_amount, requested_at FROM return_requests WHERE seller_order_id = ? ORDER BY id', [$so['id']]),
            'cases' => \Waimart\Core\App::db()->all('SELECT id, dispute_number, status FROM seller_disputes WHERE seller_order_id = ? ORDER BY id', [$so['id']]),
        ]);
    }

    public function transition(Request $req): Response
    {
        $id = $this->id($req);
        $to = (string) $req->input('to', '');
        if (in_array($to, ['accepted', 'rejected'], true)) {
            SellerContext::requireRole('owner', 'manager');      // committing to (or declining) an order
        }
        // The seller id comes from the server-side context; the workflow 404s for anyone else's part.
        (new SellerOrderWorkflow())->transition($id, $to, ['type' => 'seller', 'id' => (int) Auth::id(), 'seller_id' => $this->sellerId()], [
            'reason' => (string) $req->input('reason', ''), 'note' => (string) $req->input('note', ''),
        ]);
        $msg = match ($to) {
            'accepted' => 'Order accepted. Prepare the items, then mark them packed.',
            'processing' => 'Marked as being prepared.',
            'packed' => 'Marked as packed. Hand the parcel to ' . brand_name() . '; we will confirm when we receive it.',
            'rejected' => 'You declined this order. The customer has been told, and ' . brand_name() . ' will refund them for these items if they had already paid.',
            default => 'Order updated.',
        };
        return $this->success($msg, "/seller/orders/$id");
    }

    private function id(Request $req): int
    {
        $v = (string) $req->param('id');
        return ctype_digit($v) ? (int) $v : 0;
    }
}
