<?php
use Waimart\Core\Auth;
use Waimart\Core\Money;
use Waimart\Core\View;
use Waimart\Services\OrderPresenter;

/**
 * @var array<string,mixed> $queues
 * @var array<string,mixed>|null $today
 * @var array<string,mixed>|null $week
 * @var array<string,mixed>|null $prevWeek
 * @var list<array<string,mixed>>|null $daily
 * @var list<array<string,mixed>>|null $lowStock
 * @var list<array<string,mixed>>|null $recentOrders
 */
$today ??= null;
$compactMoney = function (string $v): string {
    $f = (float) $v;
    if ($f >= 1_000_000) {
        return 'KSh ' . rtrim(rtrim(number_format($f / 1_000_000, 1), '0'), '.') . 'M';
    }
    if ($f >= 100_000) {
        return 'KSh ' . rtrim(rtrim(number_format($f / 1000, 1), '0'), '.') . 'K';
    }
    return Money::format($v);
};
$delta = function (string $now, string $before): ?array {
    if ((float) $before <= 0) {
        return null;
    }
    $pct = ((float) $now - (float) $before) / (float) $before * 100;
    return ['pct' => $pct, 'up' => $pct >= 0];
};
$work = [
    ['cod_to_confirm', 'COD orders to confirm', '/admin/orders?status=pending_confirmation', 'orders.manage', 'telephone'],
    ['payments_to_verify', 'M-Pesa payments to verify', '/admin/payments', 'payments.view', 'phone'],
    ['resolutions', 'Orders needing resolution', '/admin/resolutions', 'resolutions.manage', 'exclamation-diamond'],
    ['to_prepare', 'Orders to prepare', '/admin/orders?status=confirmed', 'orders.view', 'box'],
    ['to_dispatch', 'Packed, ready to dispatch', '/admin/orders?status=packed', 'orders.deliver', 'truck'],
    ['overdue_pickups', 'Pickups past hold period', '/admin/collections', 'orders.collect', 'clock-history'],
    ['cod_unrecorded', 'COD cash not recorded', '/admin/cod', 'cod.collect', 'cash-coin'],
    ['refunds_due', 'Refunds due', '/admin/refunds', 'refunds.manage', 'arrow-counterclockwise'],
    ['out_of_stock', 'Variants out of stock', '/admin/inventory?filter=out', 'inventory.view', 'x-octagon'],
    ['low_stock', 'Variants low on stock', '/admin/inventory?filter=low', 'inventory.view', 'exclamation-triangle'],
];
?>
<div class="wm-admin-head">
    <h1>Dashboard</h1>
    <span class="small text-muted"><?= e(date('l j F Y, H:i')) ?></span>
</div>

<?php if ($today !== null): $d = $delta((string) $week['booked'], (string) $prevWeek['booked']); ?>
    <div class="row g-3 mb-3">
        <div class="col-6 col-xl-3"><div class="wm-panel wm-stat">
            <div class="wm-stat-label">Booked sales today</div>
            <div class="wm-stat-value"><?= e($compactMoney((string) $today['booked'])) ?></div>
            <div class="small text-muted"><?= (int) $today['orders'] ?> order<?= (int) $today['orders'] === 1 ? '' : 's' ?></div>
        </div></div>
        <div class="col-6 col-xl-3"><div class="wm-panel wm-stat">
            <div class="wm-stat-label">Booked sales, last 7 days</div>
            <div class="wm-stat-value"><?= e($compactMoney((string) $week['booked'])) ?></div>
            <div class="small">
                <?php if ($d !== null): ?>
                    <span class="<?= $d['up'] ? 'text-success' : 'text-danger' ?>"><i class="bi bi-arrow-<?= $d['up'] ? 'up' : 'down' ?>-short" aria-hidden="true"></i><?= ($d['up'] ? '+' : '') . number_format($d['pct'], 0) ?>%</span>
                    <span class="text-muted">vs previous 7 days</span>
                <?php else: ?><span class="text-muted">No sales in the previous 7 days</span><?php endif; ?>
            </div>
        </div></div>
        <div class="col-6 col-xl-3"><div class="wm-panel wm-stat">
            <div class="wm-stat-label">Cash received, last 7 days</div>
            <div class="wm-stat-value"><?= e($compactMoney((string) $week['cash_total'])) ?></div>
            <div class="small text-muted">M-Pesa <?= e($compactMoney((string) $week['cash_mpesa'])) ?> · COD <?= e($compactMoney((string) $week['cash_cod'])) ?></div>
        </div></div>
        <div class="col-6 col-xl-3"><div class="wm-panel wm-stat">
            <div class="wm-stat-label">Average order, last 7 days</div>
            <div class="wm-stat-value"><?= e($compactMoney((string) $week['avg_order'])) ?></div>
            <div class="small text-muted"><?= (int) $week['cancelled'] ?> cancelled · <?= (int) $week['expired'] ?> expired</div>
        </div></div>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-xl-8">
        <?php if (!empty($daily)): ?>
            <section class="wm-panel mb-3" aria-labelledby="chartTitle">
                <div class="wm-panel-head"><span id="chartTitle">Booked sales per day — last 14 days</span>
                    <?php if (Auth::can('reports.view')): ?><a class="small fw-normal" href="<?= e(url('/admin/reports')) ?>">Reports</a><?php endif; ?></div>
                <div class="wm-panel-body"><?= View::partial('admin/partials/daily-chart', ['daily' => $daily]) ?></div>
            </section>
        <?php endif; ?>

        <?php if (!empty($recentOrders)): ?>
            <section class="wm-panel mb-3">
                <div class="wm-panel-head">Latest orders <a class="small fw-normal" href="<?= e(url('/admin/orders')) ?>">All orders</a></div>
                <div class="table-responsive"><table class="table table-hover table-sm mb-0">
                    <tbody>
                    <?php foreach ($recentOrders as $o): ?>
                        <tr>
                            <td><a class="fw-semibold" href="<?= e(url('/admin/orders/' . $o['id'])) ?>"><?= e($o['order_number']) ?></a></td>
                            <td class="small"><?= e($o['customer_name']) ?></td>
                            <td><span class="badge <?= OrderPresenter::badgeClass($o['status']) ?>"><?= e(OrderPresenter::status($o['status'])) ?></span></td>
                            <td class="small text-muted text-nowrap"><?= e(date('d M H:i', strtotime($o['placed_at']))) ?></td>
                            <td class="text-end text-nowrap wm-num"><?= e(money($o['grand_total'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </section>
        <?php endif; ?>
    </div>

    <div class="col-xl-4">
        <section class="wm-panel mb-3" aria-labelledby="workTitle">
            <div class="wm-panel-head"><span id="workTitle">Needs attention</span></div>
            <ul class="list-group list-group-flush">
                <?php $any = false; foreach ($work as [$key, $label, $href, $perm, $icon]):
                    if (!Auth::can($perm)) { continue; }
                    $n = (int) $queues[$key]; $any = true; ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <a class="<?= $n > 0 ? 'text-body fw-semibold' : 'text-muted' ?>" href="<?= e(url($href)) ?>"><i class="bi bi-<?= $icon ?> me-2" aria-hidden="true"></i><?= e($label) ?></a>
                        <span class="badge <?= $n > 0 ? 'text-bg-warning' : 'badge-soft-muted' ?>"><?= $n ?></span>
                    </li>
                <?php endforeach; ?>
                <?php if (!$any): ?><li class="list-group-item small text-muted">Nothing assigned to your role.</li><?php endif; ?>
            </ul>
            <?php if (Auth::can('reports.view') && (int) $queues['refunds_due'] > 0): ?>
                <div class="wm-panel-body small border-top">Refunds owed: <strong><?= e(money((string) $queues['refunds_due_amount'])) ?></strong></div>
            <?php endif; ?>
        </section>

        <?php if (!empty($lowStock)): ?>
            <section class="wm-panel">
                <div class="wm-panel-head">Stock alerts <a class="small fw-normal" href="<?= e(url('/admin/inventory?filter=low')) ?>">Inventory</a></div>
                <ul class="list-group list-group-flush small">
                    <?php foreach ($lowStock as $s): ?>
                        <li class="list-group-item d-flex justify-content-between">
                            <a class="text-body" href="<?= e(url('/admin/inventory/' . $s['id'])) ?>"><?= e($s['product']) ?><?= $s['variant'] !== 'Default' ? ' · ' . e($s['variant']) : '' ?></a>
                            <span class="text-nowrap ms-2 <?= (int) $s['available'] <= 0 ? 'text-danger fw-semibold' : 'text-body' ?>">
                                <?php if ((int) $s['available'] <= 0): ?><i class="bi bi-x-octagon" aria-hidden="true"></i> Out<?php else: ?><?= (int) $s['available'] ?> left<?php endif; ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>
    </div>
</div>
<?php if ($today !== null): ?>
    <p class="small text-muted mt-3 mb-0"><strong>Booked sales</strong> = orders placed (excluding cancelled and expired). <strong>Cash received</strong> = M-Pesa payments verified + COD cash recorded.</p>
<?php endif; ?>
