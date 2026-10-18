<?php /** @var list<array<string,mixed>> $rows @var list<array<string,mixed>> $roles */ ?>
<div class="wm-admin-head">
    <h1>Staff</h1>
    <a class="btn btn-wm-green" href="<?= e(url('/admin/staff/new')) ?>"><i class="bi bi-person-plus" aria-hidden="true"></i> Add staff member</a>
</div>
<div class="wm-panel mb-4">
    <div class="table-responsive">
        <table class="table table-hover">
            <thead><tr><th>Name</th><th>Email</th><th>Roles</th><th>Last sign-in</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $s): ?>
                <tr>
                    <td class="fw-semibold"><?= e($s['first_name'] . ' ' . $s['last_name']) ?><?= (int) $s['id'] === \Waimart\Core\Auth::id() ? ' <span class="badge badge-soft-muted">You</span>' : '' ?></td>
                    <td class="small"><?= e($s['email']) ?></td>
                    <td class="small"><?= e((string) $s['roles']) ?: '<span class="text-danger">No role</span>' ?></td>
                    <td class="small text-nowrap"><?= $s['last_login_at'] ? e(date('d M Y H:i', strtotime($s['last_login_at']))) : 'Never' ?></td>
                    <td><?= $s['status'] === 'active' ? '<span class="badge badge-soft-success">Active</span>' : '<span class="badge badge-soft-danger">Suspended</span>' ?></td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/admin/staff/' . $s['id'] . '/edit')) ?>">Manage</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
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
