<?php

declare(strict_types=1);

namespace Waimart\Controllers\Seller;

use Waimart\Core\App;
use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Services\SellerService;

final class DashboardController extends SellerController
{
    public function index(Request $req): Response
    {
        $s = $this->seller();
        $db = App::db();
        $payout = (new SellerService())->activePayoutMethod($this->sellerId());
        return $this->view('dashboard', [
            'title' => 'Seller Centre',
            'payout' => $payout,
            'balances' => (new \Waimart\Services\SellerLedgerService($db))->balances($this->sellerId()),
            'awaitingReply' => (int) $db->value("SELECT COUNT(*) FROM seller_disputes d JOIN seller_orders so ON so.id = d.seller_order_id WHERE so.seller_id = ? AND d.status = 'awaiting_seller'", [$this->sellerId()]),
            'orderCounts' => (new \Waimart\Services\SellerOrderService($db))->sellerCounts($this->sellerId()),
            'productCounts' => (new \Waimart\Services\SellerProductService($db))->counts($this->sellerId()),
            // Store setup checklist: real state only, no placeholder figures.
            'checklist' => [
                ['Store description', trim((string) $s['description']) !== '', '/seller/profile'],
                ['Store logo', (string) $s['logo_path'] !== '', '/seller/profile'],
                ['Payout details on file', $payout !== null, null],
                ['First product published', (bool) $db->value("SELECT 1 FROM products WHERE seller_id = ? AND moderation_status = 'published'", [$this->sellerId()]), '/seller/products'],
            ],
        ]);
    }
}
