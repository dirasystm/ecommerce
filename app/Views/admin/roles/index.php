<?php
use Waimart\Services\RoleService;

/** @var list<array<string,mixed>> $roles @var array<string,array<string,string>> $catalog */
?>
<div class="wm-admin-head">
    <h1>Roles &amp; permissions</h1>
    <a class="btn btn-wm-green" href="<?= e(url('/admin/roles/new')) ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i> New role</a>
</div>
<p class="small text-muted">A staff member can do only what their roles allow (plus any extra permission given to them personally on their page). Every page and action checks this on the server.
    You can only give permissions you have yourself, and nobody can change their own roles or permissions.</p>

<div class="wm-panel mb-3"><div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
        <thead><tr><th>Role</th><th>Permissions</th><th>Staff</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($roles as $r): ?>
            <tr>
                <td><a class="fw-semibold" href="<?= e(url('/admin/roles/' . $r['id'])) ?>"><?= e($r['name']) ?></a>
                    <?= (int) $r['is_system'] ? '<span class="badge badge-soft-muted ms-1">built-in</span>' : '<span class="badge badge-soft-success ms-1">custom</span>' ?>
                    <?php if ($r['description']): ?><div class="small text-muted"><?= e($r['description']) ?></div><?php endif; ?></td>
                <td class="small"><?= $r['slug'] === 'super_admin' ? 'All' : count($r['permissions']) ?></td>
                <td class="small"><?= (int) $r['people'] ?></td>
                <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/admin/roles/' . $r['id'])) ?>"><?= $r['slug'] === 'super_admin' ? 'View' : 'Edit' ?></a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div></div>

<section class="wm-panel">
    <div class="wm-panel-head">Permission matrix</div>
    <div class="table-responsive wm-matrix" tabindex="0" role="region" aria-label="Permission matrix: which role has which permission (scrolls sideways)">
        <table class="table table-sm mb-0 align-middle" aria-label="Which role has which permission">
            <thead><tr><th scope="col">Permission</th>
                <?php foreach ($roles as $r): ?><th scope="col" class="text-center small"><?= e($r['name']) ?></th><?php endforeach; ?></tr></thead>
            <tbody>
            <?php foreach ($catalog as $section => $perms): ?>
                <tr class="table-light"><th scope="rowgroup" colspan="<?= count($roles) + 1 ?>" class="small text-uppercase text-muted"><?= e($section) ?></th></tr>
                <?php foreach ($perms as $slug => $desc): ?>
                    <tr>
                        <th scope="row" class="fw-normal small"><?= e($desc) ?><?= in_array($slug, RoleService::SENSITIVE, true) ? ' <span class="badge badge-soft-warning">sensitive</span>' : '' ?>
                            <div class="text-muted"><code><?= e($slug) ?></code></div></th>
                        <?php foreach ($roles as $r): $has = in_array($slug, $r['permissions'], true); ?>
                            <td class="text-center"><?= $has ? '<i class="bi bi-check-lg text-success" aria-hidden="true"></i><span class="visually-hidden">Yes</span>' : '<span class="text-muted" aria-hidden="true">·</span><span class="visually-hidden">No</span>' ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
