<?php
use Waimart\Services\RoleService;

/**
 * @var array<string,mixed>|null $role @var array<string,array<string,string>> $catalog @var list<string> $mine permissions the viewer holds
 * @var string|null $blocked why the viewer cannot edit this role
 */
$isNew = $role === null;
$locked = $blocked !== null;
$oldPerms = fv('permissions', null);
$checked = is_array($oldPerms) ? $oldPerms : ($role['permissions'] ?? []);
?>
<div class="wm-admin-head"><h1><?= $isNew ? 'New role' : e($role['name']) ?></h1></div>
<p><a href="<?= e(url('/admin/roles')) ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> Roles &amp; permissions</a></p>
<?php if ($locked): ?><div class="alert alert-info small"><?= e($blocked) ?></div><?php endif; ?>
<form method="post" action="<?= e(url($isNew ? '/admin/roles' : '/admin/roles/' . $role['id'])) ?>" style="max-width:960px" novalidate>
    <?= csrf_field() ?>
    <fieldset <?= $locked ? 'disabled' : '' ?>>
        <section class="wm-panel mb-3">
            <div class="wm-panel-body row g-3">
                <div class="col-md-5">
                    <label class="form-label" for="name">Name</label>
                    <input class="form-control<?= invalid('name') ?>" id="name" name="name" maxlength="100" value="<?= e((string) fv('name', $role['name'] ?? '')) ?>">
                    <?= field_error('name') ?>
                </div>
                <div class="col-md-7">
                    <label class="form-label" for="description">What this role is for <span class="text-muted fw-normal">(optional)</span></label>
                    <input class="form-control<?= invalid('description') ?>" id="description" name="description" maxlength="255" value="<?= e((string) fv('description', $role['description'] ?? '')) ?>">
                    <?= field_error('description') ?>
                </div>
            </div>
        </section>
        <section class="wm-panel mb-3">
            <div class="wm-panel-head">Permissions</div>
            <div class="wm-panel-body">
                <?= field_error('permissions') ?>
                <p class="small text-muted">Greyed-out permissions are ones you do not have yourself, so you cannot add them.</p>
                <div class="row g-3">
                    <?php foreach ($catalog as $section => $perms): ?>
                        <fieldset class="col-md-6">
                            <legend class="fs-6 fw-semibold"><?= e($section) ?></legend>
                            <?php foreach ($perms as $slug => $desc):
                                $id = 'perm_' . str_replace('.', '_', $slug);
                                $on = in_array($slug, $checked, true);
                                $canAdd = in_array($slug, $mine, true) || in_array($slug, $role['permissions'] ?? [], true); ?>
                                <div class="form-check mb-1">
                                    <input class="form-check-input" type="checkbox" name="permissions[]" id="<?= e($id) ?>" value="<?= e($slug) ?>" <?= $on ? 'checked' : '' ?><?= $canAdd ? '' : ' disabled' ?>>
                                    <label class="form-check-label small" for="<?= e($id) ?>"><?= e($desc) ?>
                                        <?= in_array($slug, RoleService::SENSITIVE, true) ? '<span class="badge badge-soft-warning">sensitive</span>' : '' ?></label>
                                </div>
                            <?php endforeach; ?>
                        </fieldset>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
        <?php if (!$locked): ?><button class="btn btn-wm-green" type="submit"><?= $isNew ? 'Create role' : 'Save role' ?></button><?php endif; ?>
    </fieldset>
</form>
<?php if (!$isNew && !$locked && (int) $role['is_system'] === 0): ?>
    <form class="mt-3" method="post" action="<?= e(url('/admin/roles/' . $role['id'] . '/delete')) ?>" data-confirm="Delete this role? This cannot be undone.">
        <?= csrf_field() ?><button class="btn btn-sm btn-outline-danger" type="submit">Delete role</button>
    </form>
<?php endif; ?>
