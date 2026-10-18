<?php
use Waimart\Core\View;

/** @var list<array<string,mixed>> $rows @var string $status */
$req = View::shared('request');
?>
<div class="wm-admin-head">
    <h1>Pickup points</h1>
    <a class="btn btn-wm-green" href="<?= e(url('/admin/pickup-points/new')) ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i> New pickup point</a>
</div>
<form class="d-flex flex-wrap gap-2 mb-3" method="get" action="<?= e(url('/admin/pickup-points')) ?>">
    <label class="visually-hidden" for="q">Search</label>
    <input class="form-control form-control-sm" id="q" name="q" placeholder="Name, code, town or county" value="<?= e($req->query['q'] ?? '') ?>" style="max-width:300px">
    <select class="form-select form-select-sm" name="status" aria-label="Status" style="max-width:160px">
        <option value="">All</option>
        <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
        <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
    </select>
    <button class="btn btn-sm btn-outline-secondary" type="submit">Search</button>
</form>
<div class="wm-panel">
    <div class="table-responsive">
        <table class="table table-hover">
            <thead><tr><th>Code</th><th>Name</th><th>Location</th><th class="text-end">Fee</th><th>COD</th><th class="text-end">Awaiting collection</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $p): ?>
                <tr>
                    <td><code><?= e($p['code']) ?></code></td>
                    <td class="fw-semibold"><?= e($p['name']) ?></td>
                    <td class="small"><?= e($p['town']) ?>, <?= e($p['county']) ?></td>
                    <td class="text-end"><?= (float) $p['pickup_fee'] > 0 ? e(money($p['pickup_fee'])) : 'Free' ?></td>
                    <td><?= (int) $p['cod_allowed'] ? 'Yes' : 'No' ?></td>
                    <td class="text-end"><?= (int) $p['awaiting_collection'] ?></td>
                    <td><?= (int) $p['is_active'] ? '<span class="badge badge-soft-success">Active</span>' : '<span class="badge badge-soft-muted">Inactive</span>' ?></td>
                    <td class="text-end text-nowrap">
                        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/admin/pickup-points/' . $p['id'] . '/edit')) ?>">Edit</a>
                        <form class="d-inline" method="post" action="<?= e(url('/admin/pickup-points/' . $p['id'] . '/toggle')) ?>"
                              data-confirm="<?= (int) $p['is_active'] ? 'Deactivate this pickup point? It will be hidden from checkout.' : 'Activate this pickup point?' ?>">
                            <?= csrf_field() ?><input type="hidden" name="active" value="<?= (int) $p['is_active'] ? '0' : '1' ?>">
                            <button class="btn btn-sm <?= (int) $p['is_active'] ? 'btn-outline-danger' : 'btn-outline-success' ?>" type="submit"><?= (int) $p['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-4">No pickup points found.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
