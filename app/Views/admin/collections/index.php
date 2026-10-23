<?php
use Waimart\Core\View;
use Waimart\Services\OrderPresenter;

/**
 * @var string $tab arriving | ready | returns
 * @var array<string,mixed> $counts
 * @var list<array<string,mixed>> $rows @var int $holdDays @var list<array<string,mixed>> $points @var \Waimart\Core\ListParams $lp @var string $point
 */
$path = '/admin/collections';
$pointOpts = ['' => 'All pickup points'];
foreach ($points as $p) {
    $pointOpts[(string) $p['id']] = $p['name'] . ' (' . $p['code'] . ')';
}
$tabs = ['arriving' => ['On the way to the station', 'truck'], 'ready' => ['Ready to hand over', 'box-seam'], 'returns' => ['Returns to warehouse', 'arrow-return-left']];
?>
<div class="wm-admin-head"><h1>Collection desk</h1></div>
<ul class="nav nav-tabs mb-3">
    <?php foreach ($tabs as $k => [$label, $icon]): $n = (int) ($counts[$k] ?? 0); ?>
        <li class="nav-item"><a class="nav-link<?= $tab === $k ? ' active' : '' ?>" href="<?= e(url($path, ['tab' => $k])) ?>" <?= $tab === $k ? 'aria-current="page"' : '' ?>>
            <i class="bi bi-<?= $icon ?>" aria-hidden="true"></i> <?= e($label) ?><?php if ($n > 0): ?> <span class="badge <?= $k === 'returns' ? 'text-bg-danger' : 'text-bg-secondary' ?>"><?= $n ?></span><?php endif; ?></a></li>
    <?php endforeach; ?>
</ul>
<p class="small text-muted">
    <?php if ($tab === 'arriving'): ?>
        Pickup orders being prepared and sent to the stations. When a parcel arrives, click <strong>Arrived</strong>: the customer is sent the station, their collection code, a reminder to bring ID and the last day to collect (<?= (int) $holdDays ?> days).
    <?php elseif ($tab === 'ready'): ?>
        Parcels waiting at the stations. Customers get a reminder the day before their last day; parcels not collected within <?= (int) $holdDays ?> days are returned automatically and the order cancelled.
    <?php else: ?>
        Parcels going back from the stations (not collected, or sent back). Their stock stays held until you click <strong>Received back</strong>.
    <?php endif; ?>
</p>
<?= View::partial('partials/table/toolbar', ['id' => 'collections', 'path' => $path, 'lp' => $lp, 'label' => 'Find an order to hand over',
    'placeholder' => \Waimart\Services\Brand::orderPrefix() . '-XXXXXXXX or customer name', 'keep' => ['tab' => $tab],
    'filters' => [['name' => 'point', 'label' => 'Pickup point', 'value' => $point, 'options' => $pointOpts]]]) ?>
<div class="wm-panel">
    <div id="collections" class="wm-table-region" data-table-region data-loading-text="Loading orders…">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <?php if ($tab === 'arriving'): ?>
                    <caption class="visually-hidden">Pickup orders on the way to the stations</caption>
                    <thead><tr><?= sort_th('Order', 'order', $lp, $path) ?><th scope="col">Customer</th><th scope="col">Pickup point</th><th scope="col">Payment</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td class="fw-semibold"><?= e($r['order_number']) ?></td>
                            <td><?= e(OrderPresenter::firstName($r['customer_name'])) ?></td>
                            <td class="small"><?= e($r['pickup_name']) ?></td>
                            <td class="small"><?= $r['payment_method'] === 'cod' ? 'Cash at collection' : e(OrderPresenter::payment($r['payment_status'])) ?></td>
                            <td class="text-end">
                                <form method="post" action="<?= e(url('/admin/collections/' . $r['id'] . '/arrived')) ?>" class="d-inline"
                                      data-confirm="Has <?= e($r['order_number']) ?> arrived at <?= e($r['pickup_name']) ?>? The customer will be sent their collection code now.">
                                    <?= csrf_field() ?><button class="btn btn-sm btn-wm-green" type="submit"><i class="bi bi-check2-circle" aria-hidden="true"></i> Arrived<span class="visually-hidden"> <?= e($r['order_number']) ?></span>, notify customer</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?><?= empty_row(5, $lp->q !== '' || $point !== '', 'No pickup orders on the way.') ?><?php endif; ?>
                    </tbody>
                <?php elseif ($tab === 'ready'): ?>
                    <caption class="visually-hidden">Orders ready for collection</caption>
                    <thead><tr><?= sort_th('Order', 'order', $lp, $path) ?><th scope="col">Customer</th><th scope="col">Pickup point</th><?= sort_th('Ready since', 'ready', $lp, $path) ?><th scope="col">Collect by</th><th scope="col">Payment</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r): $by = strtotime($r['ready_at']) + $holdDays * 86400; ?>
                        <tr>
                            <td class="fw-semibold"><?= e($r['order_number']) ?></td>
                            <td><?= e(OrderPresenter::firstName($r['customer_name'])) ?></td>
                            <td class="small"><?= e($r['pickup_name']) ?></td>
                            <td class="small text-nowrap"><?= e(date('d M H:i', strtotime($r['ready_at']))) ?></td>
                            <td class="small text-nowrap"><?= e(date('D d M', $by)) ?><?= (int) $r['overdue'] ? ' <span class="badge badge-soft-danger">Overdue</span>' : ($by - time() < 86400 ? ' <span class="badge badge-soft-warning">Last day</span>' : '') ?></td>
                            <td class="small"><?= $r['payment_method'] === 'cod' ? '<span class="badge badge-soft-warning">Collect ' . e(money($r['grand_total'])) . ' cash</span>' : e(OrderPresenter::payment($r['payment_status'])) ?></td>
                            <td class="text-end"><a class="btn btn-sm btn-wm-green" href="<?= e(url('/admin/collections/' . $r['id'])) ?>">Hand over<span class="visually-hidden"> <?= e($r['order_number']) ?></span></a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?><?= empty_row(7, $lp->q !== '' || $point !== '', 'No orders waiting for collection.') ?><?php endif; ?>
                    </tbody>
                <?php else: ?>
                    <caption class="visually-hidden">Parcels returning to the warehouse</caption>
                    <thead><tr><?= sort_th('Order', 'order', $lp, $path) ?><th scope="col">Customer</th><th scope="col">From station</th><?= sort_th('Return started', 'since', $lp, $path) ?><th scope="col">Why</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td class="fw-semibold"><a href="<?= e(url('/admin/orders/' . $r['id'])) ?>"><?= e($r['order_number']) ?></a></td>
                            <td><?= e(OrderPresenter::firstName($r['customer_name'])) ?></td>
                            <td class="small"><?= e($r['pickup_name']) ?></td>
                            <td class="small text-nowrap"><?= e(date('d M H:i', strtotime($r['return_started']))) ?><?= $r['started_by'] === null ? ' <span class="badge badge-soft-muted">automatic</span>' : '' ?></td>
                            <td class="small"><?= e(\Waimart\Services\StationReturnService::REASONS[$r['return_reason']] ?? $r['return_reason']) ?><?= $r['payment_status'] === 'refund_due' ? '<br><span class="badge badge-soft-warning">Refund due</span>' : '' ?></td>
                            <td><?= $r['return_status'] === 'received' ? '<span class="badge badge-soft-success">Received ' . e(date('d M', strtotime($r['received_at']))) . '</span>' : '<span class="badge badge-soft-danger">On the way back</span>' ?></td>
                            <td class="text-end">
                                <?php if ($r['return_status'] === 'awaiting_return'): ?>
                                    <form method="post" action="<?= e(url('/admin/collections/returns/' . $r['return_id'] . '/received')) ?>" class="d-inline"
                                          data-confirm="Is the parcel for <?= e($r['order_number']) ?> back at the warehouse? Its items become available for sale again.">
                                        <?= csrf_field() ?><button class="btn btn-sm btn-outline-success" type="submit"><i class="bi bi-box-arrow-in-down" aria-hidden="true"></i> Received back<span class="visually-hidden"> <?= e($r['order_number']) ?></span></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?><?= empty_row(7, $lp->q !== '' || $point !== '', 'No parcels returning from the stations.') ?><?php endif; ?>
                    </tbody>
                <?php endif; ?>
            </table>
        </div>
        <?= View::partial('partials/table/footer', ['lp' => $lp, 'path' => $path, 'id' => 'collections', 'noun' => 'orders']) ?>
    </div>
</div>
