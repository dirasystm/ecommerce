<?php
use Waimart\Core\View;

/** @var list<array<string,mixed>> $rows @var \Waimart\Core\ListParams $lp @var string $status */
$path = '/admin/brands';
?>
<div class="wm-admin-head">
    <h1>Brands</h1>
    <a class="btn btn-wm-green" href="<?= e(url('/admin/brands/new')) ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i> New brand</a>
</div>
<?= View::partial('partials/table/toolbar', ['id' => 'brands', 'path' => $path, 'lp' => $lp, 'label' => 'Search brands', 'placeholder' => 'Brand name or slug',
    'filters' => [['name' => 'status', 'label' => 'Status', 'value' => $status, 'options' => ['' => 'Any status', 'active' => 'Active', 'hidden' => 'Hidden']]]]) ?>
<div class="wm-panel">
    <div id="brands" class="wm-table-region" data-table-region data-loading-text="Loading brands…">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <caption class="visually-hidden">Brands</caption>
                <thead><tr><?= sort_th('Name', 'name', $lp, $path) ?><th scope="col">Slug</th><?= sort_th('Products', 'products', $lp, $path, 'text-end') ?><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $b): ?>
                    <tr>
                        <td class="fw-semibold"><?= e($b['name']) ?></td>
                        <td class="small text-muted"><?= e($b['slug']) ?></td>
                        <td class="text-end"><?= (int) $b['product_count'] ?></td>
                        <td><?= (int) $b['is_active'] ? '<span class="badge badge-soft-success">Active</span>' : '<span class="badge badge-soft-muted">Hidden</span>' ?></td>
                        <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/admin/brands/' . $b['id'] . '/edit')) ?>">Edit<span class="visually-hidden"> <?= e($b['name']) ?></span></a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?><?= empty_row(5, $lp->q !== '' || $status !== '', 'No brands yet.') ?><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?= View::partial('partials/table/footer', ['lp' => $lp, 'path' => $path, 'id' => 'brands', 'noun' => 'brands']) ?>
    </div>
</div>
