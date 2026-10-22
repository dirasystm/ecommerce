<?php /** @var list<array<string,mixed>> $rows @var list<array<string,mixed>> $roles @var \Waimart\Core\ListParams $lp @var string $status @var string $role */
$roleOpts = ['' => 'Any role'];
foreach ($roles as $r) {
    $roleOpts[(string) $r['id']] = (string) $r['name'];
}
?>
<div class="wm-admin-head">
    <h1>Staff</h1>
    <a class="btn btn-wm-green" href="<?= e(url('/admin/staff/new')) ?>"><i class="bi bi-person-plus" aria-hidden="true"></i> Add staff member</a>
</div>
<?= \Waimart\Core\View::partial('partials/table/toolbar', ['id' => 'staff', 'path' => '/admin/staff', 'lp' => $lp, 'label' => 'Search staff', 'placeholder' => 'Name or email', 'filters' => [['name' => 'role', 'label' => 'Role', 'value' => $role, 'options' => $roleOpts], ['name' => 'status', 'label' => 'Status', 'value' => $status, 'options' => ['' => 'Any status', 'active' => 'Active', 'suspended' => 'Suspended']]]]) ?>
<div class="wm-panel mb-4">
    <div id="staff" class="wm-table-region" data-table-region data-loading-text="Loading staff…">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">Staff</caption>
            <thead><tr><?= sort_th('Name', 'name', $lp, '/admin/staff') ?><th scope="col">Email</th><th scope="col">Roles</th><th scope="col">Two-step</th><?= sort_th('Last sign-in', 'login', $lp, '/admin/staff') ?><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $s): ?>
                <tr>
                    <td class="fw-semibold"><?= e($s['first_name'] . ' ' . $s['last_name']) ?><?= (int) $s['id'] === \Waimart\Core\Auth::id() ? ' <span class="badge badge-soft-muted">You</span>' : '' ?></td>
                    <td class="small"><?= e($s['email']) ?></td>
                    <td class="small"><?= e((string) $s['roles']) ?: '<span class="text-danger">No role</span>' ?><?= (int) $s['grants'] ? ' <span class="badge badge-soft-warning">+' . (int) $s['grants'] . ' extra</span>' : '' ?></td>
                    <td class="small"><?= $s['mfa_enabled_at'] ? '<span class="badge badge-soft-success">On</span>' : '<span class="text-muted">Off</span>' ?><?= (int) $s['must_change_password'] ? ' <span class="badge badge-soft-warning">new password due</span>' : '' ?></td>
                    <td class="small text-nowrap"><?= $s['last_login_at'] ? e(date('d M Y H:i', strtotime($s['last_login_at']))) : 'Never' ?></td>
                    <td><?= $s['status'] === 'active' ? '<span class="badge badge-soft-success">Active</span>' : '<span class="badge badge-soft-danger">Suspended</span>' ?></td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/admin/staff/' . $s['id'] . '/edit')) ?>">Manage</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><?= empty_row(7, true, 'No staff.', 'No staff match your search.') ?><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= \Waimart\Core\View::partial('partials/table/footer', ['lp' => $lp, 'path' => '/admin/staff', 'id' => 'staff', 'noun' => 'staff members']) ?>
    </div>
</div>
<?php if (\Waimart\Core\Auth::can('roles.manage')): ?><p class="small"><a href="<?= e(url('/admin/roles')) ?>">Roles &amp; permissions: change what each role can do, or create a role</a></p><?php endif; ?>
<section class="wm-panel">
    <div class="wm-panel-head">What each role can do</div>
    <div class="table-responsive"><table class="table table-sm mb-0">
        <tbody>
        <?php foreach ($roles as $r): ?>
            <tr><th scope="row" class="text-nowrap"><?= e($r['name']) ?></th><td class="small"><?= e($r['description'] ?? '') ?><div class="text-muted"><?= $r['slug'] === 'super_admin' ? 'All permissions' : e((string) $r['perms']) ?></div></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>
