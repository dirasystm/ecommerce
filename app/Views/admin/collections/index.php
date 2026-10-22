<?php
use Waimart\Core\View;
use Waimart\Services\OrderPresenter;

/** @var list<array<string,mixed>> $rows @var int $holdDays @var list<array<string,mixed>> $points @var \Waimart\Core\ListParams $lp @var string $point */
$path = '/admin/collections';
$pointOpts = ['' => 'All pickup points'];
foreach ($points as $p) {
    $pointOpts[(string) $p['id']] = $p['name'] . ' (' . $p['code'] . ')';
}
?>
<div class="wm-admin-head"><h1>Collection desk</h1></div>
<p class="small text-muted">Orders waiting at pickup points. Overdue = waiting more than <?= (int) $holdDays ?> days.</p>
<?= View::partial('partials/table/toolbar', ['id' => 'collections', 'path' => $path, 'lp' => $lp, 'label' => 'Find an order to hand over',
    'placeholder' => \Waimart\Services\Brand::orderPrefix() . '-XXXXXXXX or customer name',
    'filters' => [['name' => 'point', 'label' => 'Pickup point', 'value' => $point, 'options' => $pointOpts]]]) ?>
<div class="wm-panel">
    <div id="collections" class="wm-table-region" data-table-region data-loading-text="Loading orders…">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <caption class="visually-hidden">Orders ready for collection</caption>
                <thead><tr><?= sort_th('Order', 'order', $lp, $path) ?><th scope="col">Customer</th><th scope="col">Pickup point</th><?= sort_th('Ready since', 'ready', $lp, $path) ?><th scope="col">Payment</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="fw-semibold"><?= e($r['order_number']) ?></td>
                        <td><?= e(OrderPresenter::firstName($r['customer_name'])) ?></td>
                        <td class="small"><?= e($r['pickup_name']) ?></td>
                        <td class="small text-nowrap"><?= e(date('d M H:i', strtotime($r['ready_at']))) ?><?= (int) $r['overdue'] ? ' <span class="badge badge-soft-danger">Overdue</span>' : '' ?></td>
                        <td class="small"><?= $r['payment_method'] === 'cod' ? '<span class="badge badge-soft-warning">Collect ' . e(money($r['grand_total'])) . ' cash</span>' : e(OrderPresenter::payment($r['payment_status'])) ?></td>
                        <td class="text-end"><a class="btn btn-sm btn-wm-green" href="<?= e(url('/admin/collections/' . $r['id'])) ?>">Hand over<span class="visually-hidden"> <?= e($r['order_number']) ?></span></a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?><?= empty_row(6, $lp->q !== '' || $point !== '', 'No orders waiting for collection.') ?><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?= View::partial('partials/table/footer', ['lp' => $lp, 'path' => $path, 'id' => 'collections', 'noun' => 'orders']) ?>
    </div>
</div>
<p class="small text-muted mt-2">Overdue orders can be cancelled from the order page; cancelling releases the reserved stock.</p>
