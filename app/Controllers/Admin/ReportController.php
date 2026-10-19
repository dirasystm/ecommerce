<?php

declare(strict_types=1);

namespace Waimart\Controllers\Admin;

use Waimart\Core\Csv;
use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Services\AuditLogger;
use Waimart\Services\OrderPresenter;
use Waimart\Services\PaymentOptions;
use Waimart\Services\ReportService;

/** Reports with CSV export (reports.view). Exports are audited. */
final class ReportController extends AdminController
{
    private const STOCK_ON_SCREEN = 200;

    public const TABS = ['sales' => 'Sales', 'products' => 'Top products', 'stock' => 'Stock', 'payments' => 'M-Pesa payments', 'cod' => 'COD reconciliation', 'refunds' => 'Refunds'];

    public function index(Request $req): Response
    {
        $tab = (string) ($req->query['tab'] ?? 'sales');
        $tab = isset(self::TABS[$tab]) ? $tab : 'sales';
        [$from, $to] = ReportService::range($req->query['from'] ?? null, $req->query['to'] ?? null);
        $r = new ReportService();
        $csv = ($req->query['format'] ?? '') === 'csv';
        $data = match ($tab) {
            'sales' => ['summary' => $r->salesSummary($from, $to), 'daily' => $r->daily($from, $to),
                        'byPayment' => $r->byDimension($from, $to, 'payment_method'), 'byFulfilment' => $r->byDimension($from, $to, 'fulfilment_method'),
                        'byStatus' => $r->byDimension($from, $to, 'status')],
            'products' => ['rows' => $r->topProducts($from, $to, 50)],
            // On screen: the variants that need attention first; the CSV carries the full list.
            'stock' => ['rows' => $r->stock($csv ? null : self::STOCK_ON_SCREEN), 'stockTotal' => $csv ? 0 : $r->stockCount()],
            'payments' => ['rows' => $r->payments($from, $to)],
            'cod' => ['rows' => $r->cod($from, $to)],
            'refunds' => ['rows' => $r->refunds($from, $to)],
        };

        if ($csv) {
            AuditLogger::log('report.exported', 'report', $tab, null, ['from' => $from, 'to' => $to], $this->actor());
            return $this->csv($tab, $data, substr($from, 0, 10), substr($to, 0, 10));
        }
        return $this->view('reports/index', ['title' => 'Reports', 'tab' => $tab, 'from' => substr($from, 0, 10), 'to' => substr($to, 0, 10)] + $data);
    }

    private function csv(string $tab, array $data, string $from, string $to): Response
    {
        $name = \Waimart\Services\Brand::slug() . "-$tab-$from-to-$to.csv";
        return match ($tab) {
            'sales' => Csv::response($name, ['Day', 'Orders', 'Booked sales (KSh)'], array_map(fn ($d) => [$d['day'], $d['orders'], $d['booked']], $data['daily'])),
            'products' => Csv::response($name, ['Product', 'Units', 'Orders', 'Revenue (KSh)'], array_map(fn ($p) => [$p['product_name'], $p['units'], $p['orders'], $p['revenue']], $data['rows'])),
            'stock' => Csv::response($name, ['SKU', 'Product', 'Variant', 'Price', 'On hand', 'Reserved', 'Available', 'Low-stock threshold', 'Active'],
                array_map(fn ($s) => [$s['sku'], $s['product'], $s['variant'], $s['price'], $s['on_hand'], $s['reserved'], $s['available'], $s['low_stock_threshold'], $s['is_active'] ? 'yes' : 'no'], $data['rows'])),
            'payments' => Csv::response($name, ['Code', 'Order', 'Method', 'Claimed', 'Status', 'Submitted', 'Confirmed amount', 'Decided at', 'Decided by', 'Note'],
                array_map(fn ($p) => [$p['transaction_code'], $p['order_number'], PaymentOptions::label((string) $p['method']), $p['amount_claimed'], $p['status'], $p['submitted_at'], $p['amount_confirmed'], $p['decided_at'], $p['decided_by'], $p['note']], $data['rows'])),
            'cod' => Csv::response($name, ['Order', 'Status', 'Expected', 'Collected', 'Difference', 'Collected at', 'Collected by', 'Note'],
                array_map(fn ($c) => [$c['order_number'], OrderPresenter::status($c['status']), $c['expected'], $c['collected'], $c['difference'], $c['collected_at'], $c['collected_by'], $c['note']], $data['rows'])),
            'refunds' => Csv::response($name, ['Order', 'Amount', 'Reason', 'Status', 'Method', 'Reference', 'Created', 'Paid'],
                array_map(fn ($f) => [$f['order_number'], $f['amount'], $f['reason'], $f['status'], $f['method'], $f['reference'], $f['created_at'], $f['paid_at']], $data['rows'])),
        };
    }
}
