<?php
use Waimart\Core\View;

/** @var list<array<string,mixed>> $rows @var \Waimart\Core\ListParams $lp @var string $status */
$path = '/admin/categories';
$tree = $lp->sort === 'tree' && $lp->q === '';
?>
<div class="wm-admin-head">
    <h1>Categories</h1>
    <a class="btn btn-wm-green" href="<?= e(url('/admin/categories/new')) ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i> New category</a>
</div>
<?= View::partial('partials/table/toolbar', ['id' => 'categories', 'path' => $path, 'lp' => $lp, 'label' => 'Search categories', 'placeholder' => 'Category, slug or parent category',
    'filters' => [['name' => 'status', 'label' => 'Status', 'value' => $status, 'options' => ['' => 'Any status', 'active' => 'Active', 'hidden' => 'Hidden']]]]) ?>
<div class="wm-panel">
    <div id="categories" class="wm-table-region" data-table-region data-loading-text="Loading categories…">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <caption class="visually-hidden">Categories</caption>
                <thead><tr><?= sort_th('Name', 'name', $lp, $path) ?><th scope="col">Slug</th><th scope="col">Icon</th><?= sort_th('Products', 'products', $lp, $path, 'text-end') ?><th scope="col" class="text-end">Order</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $c): ?>
                    <tr>
                        <td><?php if ($c['parent_id'] && $tree): ?><span class="text-muted ms-3" aria-hidden="true">↳</span> <?= e($c['name']) ?>
                            <?php elseif ($c['parent_id']): ?><?= e($c['name']) ?><div class="small text-muted">in <?= e((string) $c['parent_name']) ?></div>
                            <?php else: ?><strong><?= e($c['name']) ?></strong><?php endif; ?></td>
                        <td class="small text-muted"><?= e($c['slug']) ?></td>
                        <td><?php if ($c['icon']): ?><i class="bi bi-<?= e($c['icon']) ?>" aria-hidden="true"></i> <span class="small text-muted"><?= e($c['icon']) ?></span><?php endif; ?></td>
                        <td class="text-end"><?= (int) $c['product_count'] ?></td>
                        <td class="text-end"><?= (int) $c['sort_order'] ?></td>
                        <td><?= (int) $c['is_active'] ? '<span class="badge badge-soft-success">Active</span>' : '<span class="badge badge-soft-muted">Hidden</span>' ?></td>
                        <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/admin/categories/' . $c['id'] . '/edit')) ?>">Edit<span class="visually-hidden"> <?= e($c['name']) ?></span></a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?><?= empty_row(7, $lp->q !== '' || $status !== '', 'No categories yet.') ?><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?= View::partial('partials/table/footer', ['lp' => $lp, 'path' => $path, 'id' => 'categories', 'noun' => 'categories']) ?>
    </div>
</div>
