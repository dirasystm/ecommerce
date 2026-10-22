<?php /** @var \Waimart\Core\ListParams $lp @var list<array<string,mixed>> $rows @var string $status @var array<string,string> $reasons */ ?>
<div class="wm-admin-head"><h1>Returns</h1></div>
<ul class="nav nav-pills mb-3 small">
    <?php foreach (['open' => 'Open', 'completed' => 'Completed', 'rejected' => 'Rejected', 'all' => 'All'] as $k => $l): ?>
        <li class="nav-item"><a class="nav-link<?= $status === $k ? ' active' : '' ?>" href="<?= e(url('/admin/returns', ['status' => $k])) ?>"><?= e($l) ?></a></li>
    <?php endforeach; ?>
</ul>
<?= \Waimart\Core\View::partial('partials/table/toolbar', ['id' => 'returns', 'path' => '/admin/returns', 'lp' => $lp, 'label' => 'Search returns',
    'placeholder' => 'Return or order number, customer or seller', 'keep' => ['status' => $status]]) ?>
<div class="wm-panel">
    <div id="returns" class="wm-table-region" data-table-region data-loading-text="Loading returns…">
        <div class="table-responsive">
    <table class="table table-hover mb-0">
        <caption class="visually-hidden">Returns</caption>
        <thead><tr><th scope="col">Return</th><th scope="col">Order</th><th scope="col">Seller</th><th scope="col">Customer</th><th scope="col">Reason</th><th scope="col" class="text-end">Units</th><?= sort_th('Requested', 'requested', $lp, '/admin/returns') ?><th scope="col">Status</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><a class="fw-semibold" href="<?= e(url('/admin/returns/' . $r['id'])) ?>"><?= e($r['return_number']) ?></a></td>
                <td><?= e($r['order_number']) ?></td><td class="small"><?= e((string) ($r['seller_name'] ?? '')) ?></td><td class="small"><?= e($r['customer_name']) ?></td>
                <td class="small"><?= e($reasons[$r['reason']] ?? $r['reason']) ?></td><td class="text-end"><?= (int) $r['units'] ?></td>
                <td class="small text-nowrap"><?= e(date('d M H:i', strtotime($r['requested_at']))) ?></td>
                <td><span class="badge <?= ['completed' => 'badge-soft-success', 'rejected' => 'badge-soft-danger'][$r['status']] ?? 'badge-soft-warning' ?>"><?= e($r['status']) ?></span></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><?= empty_row(8, $lp->q !== '', 'No returns.') ?><?php endif; ?>
        </tbody>
    </table>
</div>
        <?= \Waimart\Core\View::partial('partials/table/footer', ['lp' => $lp, 'path' => '/admin/returns', 'id' => 'returns', 'noun' => 'returns']) ?>
    </div>
</div>
