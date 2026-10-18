<?php

declare(strict_types=1);

namespace Waimart\Controllers\Seller;

use Waimart\Core\Auth;
use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Services\PayoutService;
use Waimart\Services\SellerContext;
use Waimart\Services\SellerLedgerService;
use Waimart\Services\SellerService;

/** The seller's money: balances, ledger, payout requests (owners only request or cancel). */
final class FinanceController extends SellerController
{
    public function index(Request $req): Response
    {
        $sid = $this->sellerId();
        $ledger = new SellerLedgerService();
        return $this->view('finance', [
            'title' => 'Earnings & payouts',
            'balances' => $ledger->balances($sid), 'summary' => $ledger->summary($sid),
            'entries' => $ledger->entries($sid, 100),
            'payouts' => (new PayoutService())->list($sid, null, 50),
            'minimum' => PayoutService::minimum(),
            'method' => (new SellerService())->activePayoutMethod($sid),
            'isOwner' => SellerContext::role() === 'owner',
        ]);
    }

    public function report(Request $req): Response
    {
        [$from, $to] = \Waimart\Services\ReportService::range($req->query['from'] ?? null, $req->query['to'] ?? null);
        return $this->view('report', ['title' => 'Sales report', 'r' => (new \Waimart\Services\MarketplaceReportService())->sellerReport($this->sellerId(), $from, $to),
            'from' => substr($from, 0, 10), 'to' => substr($to, 0, 10)]);
    }

    public function request(Request $req): Response
    {
        SellerContext::requireRole('owner');
        $p = (new PayoutService())->request($this->sellerId(), (int) Auth::id(), (string) $req->input('amount', ''));
        return $this->success('Payout ' . $p['payout_number'] . ' requested. ' . brand_name() . ' will review it and tell you when it has been paid.', '/seller/finance');
    }

    public function cancel(Request $req): Response
    {
        SellerContext::requireRole('owner');
        $v = (string) $req->param('id');
        (new PayoutService())->cancel($this->sellerId(), (int) Auth::id(), ctype_digit($v) ? (int) $v : 0);
        return $this->success('Payout request cancelled; the amount is back in your available balance.', '/seller/finance');
    }
}
