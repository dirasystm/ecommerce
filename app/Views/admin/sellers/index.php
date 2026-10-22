<?php /** @var ?string $status @var list<array<string,mixed>> $rows @var \Waimart\Core\ListParams $lp */ ?>
<div class="wm-admin-head">
    <h1>Sellers</h1>
    <a class="btn btn-wm-green" href="<?= e(url('/admin/sellers/applications')) ?>"><i class="bi bi-inbox" aria-hidden="true"></i> Applications</a>
</div>
<ul class="nav nav-pills mb-3 small">
    <?php foreach (['' => 'All', 'active' => 'Active', 'suspended' => 'Suspended'] as $k => $l): ?>
        <li class="nav-item"><a class="nav-link<?= (string) $status === $k ? ' active' : '' ?>" href="<?= e(url('/admin/sellers', array_filter(['status' => $k]))) ?>"><?= e($l) ?></a></li>
    <?php endforeach; ?>
</ul>
<?= \Waimart\Core\View::partial('partials/table/toolbar', ['id' => 'sellers', 'path' => '/admin/sellers', 'lp' => $lp, 'label' => 'Search sellers', 'placeholder' => 'Store name, address or owner email', 'keep' => ['status' => (string) $status]]) ?>
<div class="wm-panel"><div id="sellers" class="wm-table-region" data-table-region data-loading-text="Loading sellers…"><div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
        <caption class="visually-hidden">Sellers</caption>
        <thead><tr><?= sort_th('Store', 'name', $lp, '/admin/sellers') ?><th scope="col">Owner</th><?= sort_th('Products', 'products', $lp, '/admin/sellers', 'text-end') ?><?= sort_th('Approved', 'approved', $lp, '/admin/sellers') ?><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $s): ?>
            <tr>
                <td><span class="fw-semibold"><?= e($s['name']) ?></span>
                    <?= (int) $s['is_platform'] ? ' <span class="badge badge-soft-muted">Platform</span>' : '' ?>
                    <?= (int) $s['is_verified'] ? ' <span class="badge badge-soft-success">Verified</span>' : '' ?>
                    <div class="small text-muted">/store/<?= e($s['slug']) ?></div></td>
                <td class="small"><?= e((string) $s['owner_email']) ?: '—' ?></td>
                <td class="text-end"><?= (int) $s['products'] ?></td>
                <td class="small text-nowrap"><?= $s['approved_at'] ? e(date('d M Y', strtotime($s['approved_at']))) : '—' ?></td>
                <td><?= $s['status'] === 'active' ? '<span class="badge badge-soft-success">Active</span>' : '<span class="badge badge-soft-danger">' . e(ucfirst($s['status'])) . '</span>' ?></td>
                <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/admin/sellers/' . $s['id'])) ?>">Manage</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><?= empty_row(6, $lp->q !== '', 'No sellers here.') ?><?php endif; ?>
        </tbody>
    </table>
</div>
<?= \Waimart\Core\View::partial('partials/table/footer', ['lp' => $lp, 'path' => '/admin/sellers', 'id' => 'sellers', 'noun' => 'sellers']) ?>
</div></div>
