<?php

declare(strict_types=1);

namespace Waimart\Controllers\Admin;

use Waimart\Core\App;
use Waimart\Core\Auth;
use Waimart\Core\Csv;
use Waimart\Core\HttpException;
use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Core\Settings;
use Waimart\Services\AuditLogger;
use Waimart\Services\Brand;
use Waimart\Services\CommissionService;
use Waimart\Services\MarketplaceReportService;
use Waimart\Services\PayoutService;
use Waimart\Services\ReportService;
use Waimart\Services\SellerLedgerService;
use Waimart\Services\SellerService;

/** Marketplace money: commission rules, seller ledgers and the payout desk. */
final class FinanceController extends AdminController
{
    private const PAYOUT_STATUSES = ['requested', 'approved', 'paid', 'rejected', 'cancelled'];

    // ------------------------------------------------------------------ commission (commissions.manage)

    public function commissions(Request $req): Response
    {
        $db = App::db();
        [$from, $to] = ReportService::range($req->query['from'] ?? null, $req->query['to'] ?? null);
        $report = (new CommissionService())->report($from, $to);
        if (($req->query['format'] ?? '') === 'csv') {
            AuditLogger::log('report.exported', 'report', 'commission', null, ['from' => $from, 'to' => $to], $this->actor());
            return Csv::response(Brand::slug() . '-commission-' . substr($from, 0, 10) . '-to-' . substr($to, 0, 10) . '.csv',
                ['Seller', 'Gross sales received', 'Commission', 'Commission reversed', 'Sales reversed'],
                array_map(fn ($r) => [$r['seller_name'], $r['gross_sales'], $r['commission'], $r['commission_reversed'], $r['sales_reversed']], $report));
        }
        return $this->view('finance/commissions', [
            'title' => 'Commission', 'rules' => (new CommissionService())->rules(),
            'provisional' => Settings::get('marketplace_commission_provisional', '1') === '1',
            'sellers' => $db->all('SELECT id, name FROM sellers WHERE is_platform = 0 ORDER BY name'),
            'categories' => $db->all('SELECT c.id, c.name, p.name AS parent_name FROM categories c LEFT JOIN categories p ON p.id = c.parent_id ORDER BY COALESCE(p.name, c.name), c.parent_id IS NOT NULL, c.name'),
            'report' => $report, 'from' => substr($from, 0, 10), 'to' => substr($to, 0, 10),
        ]);
    }

    public function saveCommission(Request $req): Response
    {
        $scope = (string) $req->input('scope', '');
        $target = (string) $req->input($scope === 'seller' ? 'seller_id' : 'category_id', '');
        (new CommissionService())->setRule($scope, ctype_digit($target) ? (int) $target : null, (string) $req->input('rate', ''),
            (string) $req->input('note', ''), $this->actor(), !empty($req->post['confirm_rate']));
        return $this->success('Commission rate saved. It applies to new orders only; existing orders keep the rate they were placed with.', '/admin/commissions');
    }

    public function retireCommission(Request $req): Response
    {
        (new CommissionService())->retire($this->intParam($req, 'id'), $this->actor());
        return $this->success('Rule removed. New orders use the next applicable rate.', '/admin/commissions');
    }

    // ------------------------------------------------------------------ payouts (payouts.view / payouts.manage)

    public function payouts(Request $req): Response
    {
        $status = (string) ($req->query['status'] ?? 'requested');
        $status = in_array($status, self::PAYOUT_STATUSES, true) ? $status : 'requested';
        $rows = (new PayoutService())->list(null, $status);
        if (($req->query['format'] ?? '') === 'csv') {
            AuditLogger::log('report.exported', 'report', 'payouts', null, ['status' => $status], $this->actor());
            return Csv::response(Brand::slug() . "-payouts-$status.csv", ['Payout', 'Seller', 'Amount', 'Status', 'Requested', 'Destination', 'Paid method', 'Paid reference', 'Paid at'],
                array_map(fn ($p) => [$p['payout_number'], $p['seller_name'], $p['amount'], $p['status'], $p['requested_at'], $p['destination_masked'],
                    $p['paid_method'], $p['paid_reference'], $p['paid_at']], $rows));
        }
        return $this->view('finance/payouts', [
            'title' => 'Seller payouts', 'status' => $status, 'rows' => $rows,
            'counts' => array_map('intval', array_column(App::db()->all('SELECT status, COUNT(*) AS n FROM seller_payouts GROUP BY status'), 'n', 'status')),
            'liability' => App::db()->one("SELECT COALESCE(SUM(CASE WHEN bucket = 'pending' THEN amount END), 0) AS pending,
                    COALESCE(SUM(CASE WHEN bucket = 'available' THEN amount END), 0) AS available,
                    COALESCE(SUM(CASE WHEN bucket = 'reserved' THEN amount END), 0) AS reserved,
                    COALESCE(SUM(CASE WHEN bucket = 'paid' THEN amount END), 0) AS paid FROM seller_ledger_entries"),
        ]);
    }

    public function payout(Request $req): Response
    {
        $svc = new PayoutService();
        $p = $svc->find($this->intParam($req, 'id'));
        $show = Auth::can('payouts.manage') && $p['status'] === 'approved' && ($req->query['details'] ?? '') === '1';
        return $this->view('finance/payout', [
            'title' => 'Payout ' . $p['payout_number'], 'p' => $p, 'history' => $svc->history((int) $p['id']),
            'balances' => (new SellerLedgerService())->balances((int) $p['seller_id']),
            'destination' => $show ? $svc->destinationFor((int) $p['id'], $this->actor()) : null,
        ]);
    }

    public function approvePayout(Request $req): Response
    {
        $id = $this->intParam($req, 'id');
        (new PayoutService())->approve($id, $this->actor(), trim((string) $req->input('note', '')) ?: null);
        return $this->success('Payout approved. Pay the seller, then record the payment reference here.', "/admin/payouts/$id");
    }

    public function rejectPayout(Request $req): Response
    {
        $id = $this->intParam($req, 'id');
        (new PayoutService())->reject($id, $this->actor(), (string) $req->input('reason', ''));
        return $this->success('Payout rejected; the amount is back in the seller\'s available balance.', "/admin/payouts/$id");
    }

    public function paidPayout(Request $req): Response
    {
        $id = $this->intParam($req, 'id');
        (new PayoutService())->markPaid($id, $this->actor(), (string) $req->input('paid_method', ''), (string) $req->input('paid_reference', ''), (string) $req->input('paid_amount', ''));
        return $this->success('Payout recorded as paid.', "/admin/payouts/$id");
    }

    // ------------------------------------------------------------------ marketplace report (reports.view)

    public function marketplaceReport(Request $req): Response
    {
        [$from, $to] = ReportService::range($req->query['from'] ?? null, $req->query['to'] ?? null);
        $svc = new MarketplaceReportService();
        $rows = $svc->sellerPerformance($from, $to);
        if (($req->query['format'] ?? '') === 'csv') {
            AuditLogger::log('report.exported', 'report', 'marketplace', null, ['from' => $from, 'to' => $to], $this->actor());
            return Csv::response(Brand::slug() . '-marketplace-' . substr($from, 0, 10) . '-to-' . substr($to, 0, 10) . '.csv',
                ['Seller', 'Status', 'Orders', 'Completed', 'Declined', 'Cancelled', 'Sales received', 'Commission (net)', 'Refunds and reversals', 'Seller net',
                 'Pending now', 'Available now', 'In payout requests now', 'Paid out (all time)'],
                array_map(fn ($r) => [$r['seller_name'], $r['seller_status'], $r['parts'], $r['completed'], $r['declined'], $r['cancelled'], $r['sales'], $r['commission'],
                    $r['refunds_reversals'], $r['net'], $r['balances']['pending'], $r['balances']['available'], $r['balances']['reserved'], $r['balances']['paid']], $rows));
        }
        return $this->view('finance/marketplace', [
            'title' => 'Marketplace report', 'rows' => $rows, 'liability' => $svc->liability(), 'checks' => $svc->reconcile(),
            'from' => substr($from, 0, 10), 'to' => substr($to, 0, 10),
        ]);
    }

    public function ledger(Request $req): Response
    {
        $seller = (new SellerService())->find($this->intParam($req, 'id')) ?? throw HttpException::notFound();
        $ledger = new SellerLedgerService();
        return $this->view('finance/ledger', [
            'title' => 'Ledger — ' . $seller['name'], 's' => $seller,
            'balances' => $ledger->balances((int) $seller['id']), 'summary' => $ledger->summary((int) $seller['id']),
            'entries' => $ledger->entries((int) $seller['id'], 500),
            'payouts' => (new PayoutService())->list((int) $seller['id'], null, 50),
        ]);
    }
}
