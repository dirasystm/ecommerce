<?php
use Waimart\Core\Auth;
use Waimart\Core\Money;

/** @var list<array<string,mixed>> $rows @var array<string,mixed> $liability @var list<array<string,mixed>> $checks @var string $from @var string $to */
$allOk = !array_filter($checks, fn ($c) => !$c['ok']);
$tot = ['sales' => '0.00', 'commission' => '0.00', 'refunds_reversals' => '0.00', 'net' => '0.00'];
foreach ($rows as $r) {
    foreach ($tot as $k => $v) {
        $tot[$k] = Money::add($v, (string) $r[$k]);
    }
}
?>
<div class="wm-admin-head"><h1>Marketplace report</h1></div>
<form class="d-flex flex-wrap gap-2 align-items-end mb-3" method="get" action="<?= e(url('/admin/reports/marketplace')) ?>">
    <div><label class="form-label small mb-0" for="from">From</label><input class="form-control form-control-sm" type="date" id="from" name="from" value="<?= e($from) ?>"></div>
    <div><label class="form-label small mb-0" for="to">To</label><input class="form-control form-control-sm" type="date" id="to" name="to" value="<?= e($to) ?>"></div>
    <button class="btn btn-sm btn-outline-secondary" type="submit">Show</button>
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/admin/reports/marketplace', ['from' => $from, 'to' => $to, 'format' => 'csv'])) ?>">CSV</a>
</form>

<div class="row g-3 mb-3">
    <?php foreach ([['Owed to sellers now', $liability['owed_to_sellers'], 'Pending + payable + in payout requests'],
                    ['Payable now', Money::add($liability['available'], $liability['owed_by_sellers']), 'Sellers can request this'],
                    ['Owed by sellers', $liability['owed_by_sellers'], $liability['sellers_owing'] . ' seller(s) refunded after being paid; recovered from later earnings'],
                    ['Paid out (all time)', $liability['paid'], 'With payment references']] as [$label, $value, $help]): ?>
        <div class="col-6 col-lg-3"><div class="wm-panel h-100"><div class="wm-panel-body">
            <div class="small text-muted"><?= e($label) ?></div><div class="fs-5 fw-bold"><?= e(money($value)) ?></div><div class="small text-muted"><?= e($help) ?></div>
        </div></div></div>
    <?php endforeach; ?>
</div>

<section class="wm-panel mb-3">
    <div class="wm-panel-head">Sellers, <?= e(date('d M Y', strtotime($from))) ?> – <?= e(date('d M Y', strtotime($to))) ?></div>
    <div class="table-responsive"><table class="table table-sm mb-0 align-middle">
        <thead><tr><th>Seller</th><th class="text-end">Orders</th><th class="text-end">Completed</th><th class="text-end">Declined</th><th class="text-end">Sales received</th>
            <th class="text-end">Commission (net)</th><th class="text-end">Refunds &amp; reversals</th><th class="text-end">Seller net</th><th class="text-end">Balance now</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $bal = Money::add($r['balances']['pending'], $r['balances']['available'], $r['balances']['reserved']); ?>
            <tr><td><?php if (Auth::can('payouts.view')): ?><a href="<?= e(url('/admin/sellers/' . $r['seller_id'] . '/ledger')) ?>"><?= e($r['seller_name']) ?></a><?php else: ?><?= e($r['seller_name']) ?><?php endif; ?>
                    <?= $r['seller_status'] !== 'active' ? '<span class="badge badge-soft-danger">' . e($r['seller_status']) . '</span>' : '' ?></td>
                <td class="text-end"><?= (int) $r['parts'] ?></td><td class="text-end"><?= (int) $r['completed'] ?></td>
                <td class="text-end"><?= (int) $r['declined'] ?><?= $r['decline_rate'] !== null ? ' <span class="small text-muted">(' . e((string) $r['decline_rate']) . '%)</span>' : '' ?></td>
                <td class="text-end"><?= e(money($r['sales'])) ?></td><td class="text-end"><?= e(money($r['commission'])) ?></td>
                <td class="text-end"><?= e(money($r['refunds_reversals'])) ?></td><td class="text-end fw-semibold"><?= e(money($r['net'])) ?></td>
                <td class="text-end <?= Money::cmp($r['balances']['available'], '0') < 0 ? 'text-danger' : '' ?>"><?= e(money($bal)) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="9" class="text-center text-muted py-3">No marketplace activity.</td></tr><?php endif; ?>
        </tbody>
        <?php if ($rows): ?><tfoot><tr class="fw-semibold"><td>Total</td><td colspan="3"></td><td class="text-end"><?= e(money($tot['sales'])) ?></td><td class="text-end"><?= e(money($tot['commission'])) ?></td>
            <td class="text-end"><?= e(money($tot['refunds_reversals'])) ?></td><td class="text-end"><?= e(money($tot['net'])) ?></td><td></td></tr></tfoot><?php endif; ?>
    </table></div>
    <div class="wm-panel-body small text-muted">Orders by confirmation date; money by the date it was recorded. Sales are sellers' merchandise — Waimart's marketplace revenue is the commission column. Official Store sales are in the main Reports.</div>
</section>

<section class="wm-panel">
    <div class="wm-panel-head">Reconciliation <span class="badge ms-2 <?= $allOk ? 'badge-soft-success' : 'badge-soft-danger' ?>"><?= $allOk ? 'All checks pass' : 'Needs attention' ?></span></div>
    <div class="table-responsive"><table class="table table-sm mb-0 align-middle">
        <thead><tr><th>Check</th><th class="text-end">From the records</th><th class="text-end">From the ledger</th><th>Result</th></tr></thead>
        <tbody>
        <?php foreach ($checks as $c): ?>
            <tr><td class="small"><?= e($c['check']) ?></td><td class="text-end"><?= e(money($c['expected'])) ?></td><td class="text-end"><?= e(money($c['actual'])) ?></td>
                <td><?= $c['ok'] ? '<span class="badge badge-soft-success"><i class="bi bi-check-lg" aria-hidden="true"></i> Matches</span>' : '<span class="badge badge-soft-danger"><i class="bi bi-x-lg" aria-hidden="true"></i> Differs by ' . e(money(Money::sub($c['actual'], $c['expected']))) . '</span>' ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <div class="wm-panel-body small text-muted">Each line recomputes a total independently from orders, refunds and payouts and compares it with the seller ledger (all time). Any difference should be investigated before paying sellers.</div>
</section>
