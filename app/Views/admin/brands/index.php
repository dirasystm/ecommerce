<?php /** @var list<array<string,mixed>> $rows */ ?>
<div class="wm-admin-head">
    <h1>Brands</h1>
    <a class="btn btn-wm-green" href="<?= e(url('/admin/brands/new')) ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i> New brand</a>
</div>
<div class="wm-panel">
    <div class="table-responsive">
        <table class="table table-hover">
            <thead><tr><th>Name</th><th>Slug</th><th class="text-end">Products</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $b): ?>
                <tr>
                    <td class="fw-semibold"><?= e($b['name']) ?></td>
                    <td class="small text-muted"><?= e($b['slug']) ?></td>
                    <td class="text-end"><?= (int) $b['product_count'] ?></td>
                    <td><?= (int) $b['is_active'] ? '<span class="badge badge-soft-success">Active</span>' : '<span class="badge badge-soft-muted">Hidden</span>' ?></td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/admin/brands/' . $b['id'] . '/edit')) ?>">Edit</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="5" class="text-center text-muted py-4">No brands yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
