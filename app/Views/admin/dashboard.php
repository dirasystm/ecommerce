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
// Priority actions: one coloured, clickable tile per queue with work waiting, most urgent first, and only queues this
// person can act on (the red menu counts follow the same rule). Tiers: urgent = customers or money waiting on us now;
// action = orders to move today; approval = a decision someone is waiting for; follow = chase up; stock.
$alerts = \Waimart\Services\AdminAlerts::counts();
$priority = [
    ['urgent', (int) $queues['cod_to_confirm'], 'COD orders to confirm', 'Call the customer and confirm', '/admin/orders?status=pending_confirmation', 'orders.manage'],
    ['urgent', (int) $queues['payments_to_verify'], 'M-Pesa payments to verify', 'Check the code on the M-Pesa statement', '/admin/payments', 'payments.verify'],
    ['urgent', (int) $queues['resolutions'], 'Orders needing resolution', 'A payment or stock problem to sort out', '/admin/resolutions', 'resolutions.manage'],
    ['urgent', (int) $queues['refunds_due'], 'Refunds due', 'Money owed back to customers', '/admin/refunds', 'refunds.manage'],
    ['action', (int) $queues['to_prepare'], 'Orders to prepare', 'Confirmed, waiting to be packed', '/admin/orders?status=confirmed', 'orders.view'],
    ['action', (int) $queues['to_dispatch'], 'Packed, ready to dispatch', 'Hand over for delivery', '/admin/orders?status=packed', 'orders.deliver'],
    ['action', (int) ($alerts['/admin/seller-orders'] ?? 0), 'Seller parcels to receive', 'Packed by sellers', '/admin/seller-orders', 'orders.view'],
    ['action', (int) $queues['overdue_pickups'], 'Pickups past hold period', 'Remind the customer or restock', '/admin/collections', 'orders.collect'],
    ['action', (int) ($alerts['/admin/returns'] ?? 0), 'Returns to handle', 'Requested, or received back', '/admin/returns', 'returns.manage'],
    ['approval', (int) ($alerts['/admin/sellers/applications'] ?? 0), 'Seller applications', 'Waiting for approval', '/admin/sellers/applications', 'sellers.view'],
    ['approval', (int) ($alerts['/admin/moderation/products'] ?? 0), 'Products to approve', 'Submitted by sellers', '/admin/moderation/products', 'products.moderate'],
    ['approval', (int) ($alerts['/admin/pickup-points/applications'] ?? 0), 'Station applications', 'Pickup stations waiting for approval', '/admin/pickup-points/applications', 'pickup_points.approve'],
    ['approval', (int) ($alerts['/admin/reviews'] ?? 0), 'Reviews to moderate', 'Publish or reject', '/admin/reviews', 'reviews.moderate'],
    ['approval', (int) ($alerts['/admin/disputes'] ?? 0), 'Open seller cases', 'Waiting for a decision', '/admin/disputes', 'disputes.manage'],
    ['follow', (int) $queues['awaiting_payment'], 'New orders awaiting payment', 'Not paid yet: follow up with the customer', '/admin/orders?status=awaiting_payment', 'orders.view'],
    ['follow', (int) $queues['cod_unrecorded'], 'COD cash not recorded', 'Delivered, cash not yet entered', '/admin/cod', 'cod.collect'],
    ['stock', (int) $queues['out_of_stock'], 'Variants out of stock', 'Customers cannot buy these', '/admin/inventory?filter=out', 'inventory.view'],
    ['stock', (int) $queues['low_stock'], 'Variants low on stock', 'Reorder soon', '/admin/inventory?filter=low', 'inventory.view'],
];
$tierLabel = ['urgent' => 'Urgent', 'action' => 'Do today', 'approval' => 'Approval', 'follow' => 'Follow up', 'stock' => 'Stock'];
$mine = array_values(array_filter($priority, fn ($p) => Auth::can($p[5])));
$waiting = array_values(array_filter($mine, fn ($p) => $p[1] > 0));
$clear = array_column(array_filter($mine, fn ($p) => $p[1] <= 0), 2);
$total = array_sum(array_column($waiting, 1));
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

<?php if ($mine): ?>
    <section class="wm-panel mb-3" aria-labelledby="priorityTitle">
        <div class="wm-panel-head"><span id="priorityTitle"><i class="bi bi-lightning-charge-fill text-danger me-1" aria-hidden="true"></i>Priority actions</span>
            <span class="small fw-normal text-muted"><?= $total ? $total . ' item' . ($total === 1 ? '' : 's') . ' waiting' : 'All caught up' ?></span></div>
        <div class="wm-panel-body">
            <?php if ($waiting): ?>
                <div class="wm-priority-grid">
                    <?php foreach ($waiting as [$tier, $n, $label, $hint, $href]): ?>
                        <a class="wm-priority-tile wm-tier-<?= e($tier) ?>" href="<?= e(url($href)) ?>">
                            <span class="wm-priority-top"><span class="wm-priority-tier"><?= e($tierLabel[$tier]) ?></span><span class="wm-priority-count"><?= $n ?></span></span>
                            <span class="wm-priority-label"><?= e($label) ?></span>
                            <span class="wm-priority-hint"><?= e($hint) ?> <i class="bi bi-arrow-right" aria-hidden="true"></i></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="mb-0 text-success fw-semibold"><i class="bi bi-check-circle-fill me-1" aria-hidden="true"></i>Nothing is waiting for you. New orders and approvals will appear here.</p>
            <?php endif; ?>
            <?php if ($clear): ?>
                <p class="small text-muted mb-0 mt-3"><i class="bi bi-check-circle text-success" aria-hidden="true"></i> All clear: <?= e(implode(' · ', $clear)) ?></p>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>

<div class="row g-3">
    <div class="col-xl-8">
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

        <?php if (!empty($daily)): ?>
            <section class="wm-panel mb-3" aria-labelledby="chartTitle">
                <div class="wm-panel-head"><span id="chartTitle">Booked sales per day — last 14 days</span>
                    <?php if (Auth::can('reports.view')): ?><a class="small fw-normal" href="<?= e(url('/admin/reports')) ?>">Reports</a><?php endif; ?></div>
                <div class="wm-panel-body"><?= View::partial('admin/partials/daily-chart', ['daily' => $daily]) ?></div>
            </section>
        <?php endif; ?>
    </div>

    <div class="col-xl-4">
        <?php if (Auth::can('reports.view') && (int) $queues['refunds_due'] > 0): ?>
            <div class="wm-panel wm-panel-body small mb-3">Refunds owed to customers: <strong><?= e(money((string) $queues['refunds_due_amount'])) ?></strong></div>
        <?php endif; ?>

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
