<?php
use Waimart\Controllers\Admin\ReportController;
use Waimart\Core\View;
use Waimart\Services\OrderPresenter;
use Waimart\Services\PaymentOptions;

/** @var string $tab @var string $from @var string $to */
$presets = ['Today' => [date('Y-m-d'), date('Y-m-d')], 'Last 7 days' => [date('Y-m-d', strtotime('-6 days')), date('Y-m-d')],
            'Last 30 days' => [date('Y-m-d', strtotime('-29 days')), date('Y-m-d')], 'This month' => [date('Y-m-01'), date('Y-m-d')],
            'Last 90 days' => [date('Y-m-d', strtotime('-89 days')), date('Y-m-d')]];
$dated = $tab !== 'stock';
$money = fn ($v) => $v === null ? '—' : e(money((string) $v));
?>
<div class="wm-admin-head"><h1>Reports</h1></div>

<ul class="nav nav-tabs mb-3 flex-nowrap overflow-auto">
    <?php foreach (ReportController::TABS as $k => $label): ?>
        <li class="nav-item"><a class="nav-link text-nowrap<?= $tab === $k ? ' active' : '' ?>" href="<?= e(url('/admin/reports', ['tab' => $k, 'from' => $from, 'to' => $to])) ?>"><?= e($label) ?></a></li>
    <?php endforeach; ?>
</ul>

<!-- Filters: one row, above everything they scope; date range first, presets before custom. -->
<form class="d-flex flex-wrap gap-2 align-items-end mb-3" method="get" action="<?= e(url('/admin/reports')) ?>">
    <input type="hidden" name="tab" value="<?= e($tab) ?>">
    <?php if ($dated): ?>
        <div class="btn-group btn-group-sm flex-wrap" role="group" aria-label="Date presets">
            <?php foreach ($presets as $label => [$pf, $pt]): ?>
                <a class="btn btn-outline-secondary<?= $pf === $from && $pt === $to ? ' active' : '' ?>" href="<?= e(url('/admin/reports', ['tab' => $tab, 'from' => $pf, 'to' => $pt])) ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </div>
        <div><label class="form-label small mb-0" for="from">From</label><input class="form-control form-control-sm" type="date" id="from" name="from" value="<?= e($from) ?>"></div>
        <div><label class="form-label small mb-0" for="to">To</label><input class="form-control form-control-sm" type="date" id="to" name="to" value="<?= e($to) ?>"></div>
        <button class="btn btn-sm btn-outline-secondary" type="submit">Apply</button>
    <?php endif; ?>
    <a class="btn btn-sm btn-wm-green ms-auto" href="<?= e(url('/admin/reports', ['tab' => $tab, 'from' => $from, 'to' => $to, 'format' => 'csv'])) ?>"><i class="bi bi-download" aria-hidden="true"></i> Export CSV</a>
</form>

<?php if ($tab === 'sales'): /** @var array<string,mixed> $summary */ ?>
    <div class="row g-3 mb-3">
        <?php foreach ([['Booked sales', money($summary['booked']), $summary['orders'] . ' orders'], ['Cash received', money($summary['cash_total']), 'M-Pesa ' . money($summary['cash_mpesa']) . ' · COD ' . money($summary['cash_cod'])],
                        ['Average order', money($summary['avg_order']), 'Discounts ' . money($summary['discounts'])], ['Lost orders', (string) ($summary['cancelled'] + $summary['expired']), $summary['cancelled'] . ' cancelled · ' . $summary['expired'] . ' expired']] as [$l, $v, $s]): ?>
            <div class="col-6 col-xl-3"><div class="wm-panel wm-stat"><div class="wm-stat-label"><?= e($l) ?></div><div class="wm-stat-value"><?= e($v) ?></div><div class="small text-muted"><?= e($s) ?></div></div></div>
        <?php endforeach; ?>
    </div>
    <?php if (count($daily) <= 62): ?>
        <section class="wm-panel mb-3"><div class="wm-panel-head">Booked sales per day</div>
            <div class="wm-panel-body"><?= View::partial('admin/partials/daily-chart', ['daily' => $daily]) ?></div></section>
    <?php endif; ?>
    <div class="row g-3">
        <?php foreach ([['By payment method', $byPayment, fn ($l) => PaymentOptions::label((string) $l)], ['By fulfilment', $byFulfilment, fn ($l) => ucfirst($l)], ['By current status (all orders)', $byStatus, fn ($l) => OrderPresenter::status($l)]] as [$h, $rows, $fmt]): ?>
            <div class="col-lg-4"><section class="wm-panel h-100"><div class="wm-panel-head"><?= e($h) ?></div>
                <table class="table table-sm mb-0 wm-num"><thead><tr><th></th><th class="text-end">Orders</th><th class="text-end">Value</th></tr></thead><tbody>
                <?php foreach ($rows as $r): ?><tr><td><?= e($fmt($r['label'])) ?></td><td class="text-end"><?= (int) $r['orders'] ?></td><td class="text-end"><?= $money($r['booked']) ?></td></tr><?php endforeach; ?>
                <?php if (!$rows): ?><tr><td colspan="3" class="text-muted text-center">No orders</td></tr><?php endif; ?>
                </tbody></table></section></div>
        <?php endforeach; ?>
    </div>
    <p class="small text-muted mt-3"><strong>Booked sales</strong>: orders placed in the period, excluding cancelled and expired, including delivery fees, after discounts. <strong>Cash received</strong>: M-Pesa amounts verified + COD cash recorded in the period.</p>

<?php else: /** @var list<array<string,mixed>> $rows */ ?>
    <?php if ($tab === 'stock' && count($rows) < (int) ($stockTotal ?? 0)): ?>
        <p class="small text-muted">Showing the <?= count($rows) ?> variants with the least available stock, out of <?= number_format((int) $stockTotal) ?>. Download the CSV for the full list.</p>
    <?php endif; ?>
    <div class="wm-panel"><div class="table-responsive">
        <table class="table table-sm table-hover mb-0 wm-num">
            <?php if ($tab === 'products'): ?>
                <thead><tr><th>#</th><th>Product</th><th class="text-end">Units</th><th class="text-end">Orders</th><th class="text-end">Revenue</th></tr></thead><tbody>
                <?php foreach ($rows as $i => $r): ?><tr><td><?= $i + 1 ?></td><td><?= e($r['product_name']) ?></td><td class="text-end"><?= (int) $r['units'] ?></td><td class="text-end"><?= (int) $r['orders'] ?></td><td class="text-end"><?= $money($r['revenue']) ?></td></tr><?php endforeach; ?>
            <?php elseif ($tab === 'stock'): ?>
                <thead><tr><th>SKU</th><th>Product</th><th class="text-end">On hand</th><th class="text-end">Reserved</th><th class="text-end">Available</th><th>Status</th></tr></thead><tbody>
                <?php foreach ($rows as $r): $a = (int) $r['available']; ?><tr><td><code><?= e($r['sku']) ?></code></td><td><?= e($r['product']) ?><?= $r['variant'] !== 'Default' ? ' · ' . e($r['variant']) : '' ?></td>
                    <td class="text-end"><?= (int) $r['on_hand'] ?></td><td class="text-end"><?= (int) $r['reserved'] ?></td><td class="text-end fw-semibold"><?= $a ?></td>
                    <td><?= $a <= 0 ? '<span class="badge badge-soft-danger"><i class="bi bi-x-octagon"></i> Out</span>' : ($a <= (int) $r['low_stock_threshold'] ? '<span class="badge badge-soft-warning"><i class="bi bi-exclamation-triangle"></i> Low</span>' : '<span class="badge badge-soft-success">OK</span>') ?></td></tr><?php endforeach; ?>
            <?php elseif ($tab === 'payments'): ?>
                <thead><tr><th>Code</th><th>Order</th><th>Method</th><th class="text-end">Claimed</th><th class="text-end">Confirmed</th><th>Status</th><th>Decided by</th></tr></thead><tbody>
                <?php foreach ($rows as $r): ?><tr><td><code><?= e($r['transaction_code']) ?></code></td><td><?= e($r['order_number']) ?></td><td class="small"><?= e(PaymentOptions::label((string) $r['method'])) ?></td>
                    <td class="text-end"><?= $money($r['amount_claimed']) ?></td><td class="text-end"><?= $money($r['amount_confirmed']) ?></td><td><?= e($r['status']) ?></td><td class="small"><?= e((string) $r['decided_by']) ?></td></tr><?php endforeach; ?>
            <?php elseif ($tab === 'cod'): ?>
                <thead><tr><th>Order</th><th>Status</th><th class="text-end">Expected</th><th class="text-end">Collected</th><th class="text-end">Difference</th><th>By</th><th>Note</th></tr></thead><tbody>
                <?php foreach ($rows as $r): ?><tr class="<?= $r['collected'] === null ? 'table-warning' : ((float) $r['difference'] !== 0.0 ? 'table-danger' : '') ?>">
                    <td><?= e($r['order_number']) ?></td><td class="small"><?= e(OrderPresenter::status($r['status'])) ?></td><td class="text-end"><?= $money($r['expected']) ?></td>
                    <td class="text-end"><?= $r['collected'] === null ? '<span class="text-danger">Not recorded</span>' : $money($r['collected']) ?></td><td class="text-end"><?= $money($r['difference']) ?></td>
                    <td class="small"><?= e((string) $r['collected_by']) ?></td><td class="small"><?= e((string) $r['note']) ?></td></tr><?php endforeach; ?>
            <?php elseif ($tab === 'refunds'): ?>
                <thead><tr><th>Order</th><th class="text-end">Amount</th><th>Reason</th><th>Status</th><th>Created</th><th>Paid</th></tr></thead><tbody>
                <?php foreach ($rows as $r): ?><tr><td><?= e($r['order_number']) ?></td><td class="text-end"><?= $money($r['amount']) ?></td><td class="small"><?= e($r['reason']) ?></td>
                    <td><span class="badge <?= $r['status'] === 'due' ? 'badge-soft-warning' : ($r['status'] === 'paid' ? 'badge-soft-success' : 'badge-soft-muted') ?>"><?= e($r['status']) ?></span></td>
                    <td class="small"><?= e(date('d M Y', strtotime($r['created_at']))) ?></td><td class="small"><?= $r['paid_at'] ? e(date('d M Y', strtotime($r['paid_at']))) : '—' ?></td></tr><?php endforeach; ?>
            <?php endif; ?>
            <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-4">No data for this period.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div></div>
<?php endif; ?>
