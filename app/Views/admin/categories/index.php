<?php /** @var list<array<string,mixed>> $rows */ ?>
<div class="wm-admin-head">
    <h1>Categories</h1>
    <a class="btn btn-wm-green" href="<?= e(url('/admin/categories/new')) ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i> New category</a>
</div>
<div class="wm-panel">
    <div class="table-responsive">
        <table class="table table-hover">
            <thead><tr><th>Name</th><th>Slug</th><th>Icon</th><th class="text-end">Products</th><th class="text-end">Order</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $c): ?>
                <tr>
                    <td><?= $c['parent_id'] ? '<span class="text-muted ms-3" aria-hidden="true">↳</span> ' : '<strong>' ?><?= e($c['name']) ?><?= $c['parent_id'] ? '' : '</strong>' ?></td>
                    <td class="small text-muted"><?= e($c['slug']) ?></td>
                    <td><?php if ($c['icon']): ?><i class="bi bi-<?= e($c['icon']) ?>" aria-hidden="true"></i> <span class="small text-muted"><?= e($c['icon']) ?></span><?php endif; ?></td>
                    <td class="text-end"><?= (int) $c['product_count'] ?></td>
                    <td class="text-end"><?= (int) $c['sort_order'] ?></td>
                    <td><?= (int) $c['is_active'] ? '<span class="badge badge-soft-success">Active</span>' : '<span class="badge badge-soft-muted">Hidden</span>' ?></td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/admin/categories/' . $c['id'] . '/edit')) ?>">Edit</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
