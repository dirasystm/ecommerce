<?php
use Waimart\Core\View;

/** @var list<array<string,mixed>> $rows @var int $total @var int $page @var int $pages @var string $filter */
$req = View::shared('request');
$tabs = ['' => 'All', 'low' => 'Low stock', 'out' => 'Out of stock', 'reserved' => 'Has reservations'];
?>
<div class="wm-admin-head"><h1>Inventory</h1></div>

<div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
    <ul class="nav nav-pills">
        <?php foreach ($tabs as $k => $label): ?>
            <li class="nav-item"><a class="nav-link<?= $filter === $k ? ' active' : '' ?>" href="<?= e(url('/admin/inventory', array_filter(['filter' => $k, 'q' => $req->query['q'] ?? null]))) ?>"><?= e($label) ?></a></li>
        <?php endforeach; ?>
    </ul>
    <form class="ms-auto d-flex gap-2" method="get" action="<?= e(url('/admin/inventory')) ?>">
        <?php if ($filter): ?><input type="hidden" name="filter" value="<?= e($filter) ?>"><?php endif; ?>
        <label class="visually-hidden" for="q">Search product or SKU</label>
        <input class="form-control form-control-sm" id="q" name="q" placeholder="Product or SKU" value="<?= e($req->query['q'] ?? '') ?>">
        <button class="btn btn-sm btn-outline-secondary" type="submit">Search</button>
    </form>
</div>

<div class="wm-panel">
    <div class="table-responsive">
        <table class="table table-hover">
            <thead><tr><th>SKU</th><th>Product / variant</th><th class="text-end">On hand</th><th class="text-end">Reserved</th><th class="text-end">Available</th><th>Status</th><th></th></tr></thead>
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
            <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">Nothing here.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= View::partial('partials/pagination', ['page' => $page, 'pages' => $pages, 'path' => '/admin/inventory']) ?>
</div>
<p class="small text-muted mt-2">Available = on hand − reserved. Reserved units belong to placed orders and are released or consumed only through the order workflow.</p>
