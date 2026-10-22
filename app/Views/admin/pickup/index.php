<?php
use Waimart\Core\View;

/** @var list<array<string,mixed>> $rows @var string $status @var \Waimart\Core\ListParams $lp */
$path = '/admin/pickup-points';
?>
<div class="wm-admin-head">
    <h1>Pickup points</h1>
    <div class="d-flex flex-wrap gap-2">
        <?php if (\Waimart\Core\Auth::can('pickup_points.approve')): $pendingApps = (int) \Waimart\Core\App::db()->value("SELECT COUNT(*) FROM pickup_station_applications WHERE status = 'pending'"); ?>
            <a class="btn btn-outline-secondary" href="<?= e(url('/admin/pickup-points/applications')) ?>"><i class="bi bi-shop" aria-hidden="true"></i> Applications<?= $pendingApps ? ' <span class="badge text-bg-warning">' . $pendingApps . ' to review</span>' : '' ?></a>
        <?php endif; ?>
        <a class="btn btn-wm-green" href="<?= e(url('/admin/pickup-points/new')) ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i> New pickup point</a>
    </div>
</div>
<?= View::partial('partials/table/toolbar', ['id' => 'points', 'path' => $path, 'lp' => $lp, 'label' => 'Search pickup points', 'placeholder' => 'Name, code, town or county',
    'filters' => [['name' => 'status', 'label' => 'Status', 'value' => $status, 'options' => ['' => 'Any status', 'active' => 'Active', 'inactive' => 'Inactive']]]]) ?>
<div class="wm-panel">
    <div id="points" class="wm-table-region" data-table-region data-loading-text="Loading pickup points…">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">Pickup points</caption>
            <thead><tr><?= sort_th('Code', 'code', $lp, $path) ?><?= sort_th('Name', 'name', $lp, $path) ?><?= sort_th('Location', 'location', $lp, $path) ?><th scope="col" class="text-end">Fee</th><th scope="col">COD</th><?= sort_th('Awaiting collection', 'waiting', $lp, $path, 'text-end') ?><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
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
            <?php if (!$rows): ?><?= empty_row(8, $lp->q !== '' || $status !== '', 'No pickup points yet.') ?><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= \Waimart\Core\View::partial('partials/table/footer', ['lp' => $lp, 'path' => '/admin/pickup-points', 'id' => 'points', 'noun' => 'pickup points']) ?>
    </div>
</div>
