<?php
use Waimart\Core\View;

/** @var list<array<string,mixed>> $rows @var \Waimart\Core\ListParams $lp @var string $filter */
$tabs = ['' => 'All stock levels', 'low' => 'Low stock', 'out' => 'Out of stock', 'reserved' => 'Has reservations'];
?>
<div class="wm-admin-head"><h1>Inventory</h1></div>

<?= View::partial('partials/table/toolbar', ['id' => 'inventory', 'path' => '/admin/inventory', 'lp' => $lp, 'label' => 'Search stock', 'placeholder' => 'Product, variant or SKU',
    'filters' => [['name' => 'filter', 'label' => 'Stock level', 'value' => $filter, 'options' => $tabs]]]) ?>

<div class="wm-panel">
    <div id="inventory" class="wm-table-region" data-table-region data-loading-text="Loading stock…">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">Stock levels</caption>
            <thead><tr><?= sort_th('SKU', 'sku', $lp, '/admin/inventory') ?><?= sort_th('Product / variant', 'product', $lp, '/admin/inventory') ?><?= sort_th('On hand', 'on_hand', $lp, '/admin/inventory', 'text-end') ?><th scope="col" class="text-end">Reserved</th><?= sort_th('Available', 'available', $lp, '/admin/inventory', 'text-end') ?><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r):
                $avail = (int) $r['available'];
                $badge = $avail <= 0 ? '<span class="badge badge-soft-danger">Out</span>'
                    : ($avail <= (int) $r['low_stock_threshold'] ? '<span class="badge badge-soft-warning">Low</span>' : '<span class="badge badge-soft-success">OK</span>');
                ?>
                <tr>
                    <td class="text-nowrap"><code><?= e($r['sku']) ?></code></td>
                    <td><?= e($r['product_name']) ?><?= $r['variant_name'] !== 'Default' ? ' <span class="text-muted">· ' . e($r['variant_name']) . '</span>' : '' ?><?= (int) $r['is_active'] ? '' : ' <span class="badge badge-soft-muted">Inactive</span>' ?></td>
                    <td class="text-end"><?= (int) $r['on_hand'] ?></td>
                    <td class="text-end"><?= (int) $r['reserved'] ?></td>
                    <td class="text-end fw-semibold"><?= $avail ?></td>
                    <td><?= $badge ?></td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/admin/inventory/' . $r['id'])) ?>">Open</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><?= empty_row(7, $lp->q !== '' || $filter !== '', 'No stock records.') ?><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= \Waimart\Core\View::partial('partials/table/footer', ['lp' => $lp, 'path' => '/admin/inventory', 'id' => 'inventory', 'noun' => 'variants']) ?>
    </div>
</div>
<p class="small text-muted mt-2">Available = on hand − reserved. Reserved units belong to placed orders and are released or consumed only through the order workflow.</p>
