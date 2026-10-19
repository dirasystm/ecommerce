<?php
use Waimart\Core\Auth;

/**
 * @var array<string,mixed>|null $staff @var list<string> $current @var list<array<string,mixed>> $roles
 * Editing only: @var list<string> $grants @var array<string,array<string,string>> $catalog @var list<string> $mine @var list<string> $fromRoles
 * @var list<array<string,mixed>> $signIns @var list<array<string,mixed>> $actions @var int $recovery
 */
$self = $staff !== null && (int) $staff['id'] === Auth::id();
$old = (array) fv('roles', $current);
?>
<div class="wm-admin-head">
    <h1><?= $staff ? e($staff['first_name'] . ' ' . $staff['last_name']) : 'Add staff member' ?></h1>
    <a class="btn btn-outline-secondary" href="<?= e(url('/admin/staff')) ?>">All staff</a>
</div>
<div class="row g-3">
    <div class="col-lg-7">
        <form class="wm-panel wm-panel-body" method="post" action="<?= e(url($staff ? '/admin/staff/' . $staff['id'] . '/roles' : '/admin/staff')) ?>" novalidate>
            <?= csrf_field() ?>
            <?php if (!$staff): ?>
                <div class="row g-3 mb-3">
                    <div class="col-sm-6"><label class="form-label" for="first_name">First name</label><input class="form-control<?= invalid('first_name') ?>" id="first_name" name="first_name" required value="<?= e(fv('first_name')) ?>"><?= field_error('first_name') ?></div>
                    <div class="col-sm-6"><label class="form-label" for="last_name">Last name</label><input class="form-control<?= invalid('last_name') ?>" id="last_name" name="last_name" required value="<?= e(fv('last_name')) ?>"><?= field_error('last_name') ?></div>
                    <div class="col-sm-7"><label class="form-label" for="email">Work email</label><input class="form-control<?= invalid('email') ?>" id="email" name="email" type="email" required value="<?= e(fv('email')) ?>"><?= field_error('email') ?></div>
                    <div class="col-sm-5"><label class="form-label" for="phone">Phone</label><input class="form-control<?= invalid('phone') ?>" id="phone" name="phone" type="tel" value="<?= e(fv('phone')) ?>"><?= field_error('phone') ?></div>
                </div>
                <p class="small text-muted">They will receive an email link to set their own password. If the email belongs to an existing customer account, that account is given staff access.</p>
            <?php else: ?>
                <p class="small mb-3"><?= e($staff['email']) ?></p>
            <?php endif; ?>
            <fieldset <?= $self ? 'disabled' : '' ?>>
                <legend class="fs-6 fw-semibold">Roles</legend>
                <?= field_error('roles') ?>
                <?php foreach ($roles as $r): ?>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="roles[]" id="role_<?= e($r['slug']) ?>" value="<?= e($r['slug']) ?>" <?= in_array($r['slug'], $old, true) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="role_<?= e($r['slug']) ?>"><strong><?= e($r['name']) ?></strong> <span class="small text-muted d-block"><?= e((string) $r['description']) ?></span></label>
                    </div>
                <?php endforeach; ?>
            </fieldset>
            <?php if ($self): ?>
                <p class="small text-muted mb-0">You cannot change your own roles.</p>
            <?php else: ?>
                <button class="btn btn-wm-green mt-2" type="submit"><?= $staff ? 'Save roles' : 'Add staff member' ?></button>
            <?php endif; ?>
        </form>
    </div>
    <?php if ($staff && !$self): ?>
        <div class="col-lg-5">
            <div class="wm-panel wm-panel-body">
                <h2 class="fs-6 fw-bold">Access</h2>
                <form method="post" action="<?= e(url('/admin/staff/' . $staff['id'] . '/status')) ?>" class="mb-2"
                      data-confirm="<?= $staff['status'] === 'active' ? 'Suspend this staff member? They will be signed out everywhere immediately.' : 'Reactivate this staff member?' ?>">
                    <?= csrf_field() ?><input type="hidden" name="active" value="<?= $staff['status'] === 'active' ? '0' : '1' ?>">
                    <button class="btn btn-sm <?= $staff['status'] === 'active' ? 'btn-outline-danger' : 'btn-outline-success' ?>" type="submit"><?= $staff['status'] === 'active' ? 'Suspend' : 'Reactivate' ?></button>
                </form>
                <form method="post" action="<?= e(url('/admin/staff/' . $staff['id'] . '/revoke')) ?>" data-confirm="Remove all staff access for this person? Their audit history is kept.">
                    <?= csrf_field() ?><button class="btn btn-sm btn-link text-danger px-0" type="submit">Remove staff access</button>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php if ($staff): ?>
    <div class="row g-3 mt-1">
        <div class="col-lg-7">
            <form class="wm-panel" method="post" action="<?= e(url('/admin/staff/' . $staff['id'] . '/permissions')) ?>" novalidate>
                <?= csrf_field() ?>
                <div class="wm-panel-head">Extra permissions</div>
                <div class="wm-panel-body">
                    <p class="small text-muted">On top of their roles, for this person only. Ticked and greyed: already given by a role.
                        You can only give permissions you have yourself.</p>
                    <?= field_error('permissions') ?>
                    <fieldset <?= $self ? 'disabled' : '' ?>>
                        <legend class="visually-hidden">Extra permissions</legend>
                        <div class="row g-2">
                            <?php foreach ($catalog as $section => $perms): ?>
                                <div class="col-md-6">
                                    <p class="small fw-semibold mb-1"><?= e($section) ?></p>
                                    <?php foreach ($perms as $slug => $desc):
                                        $id = 'grant_' . str_replace('.', '_', $slug);
                                        $byRole = in_array($slug, $fromRoles, true);
                                        $granted = in_array($slug, $grants, true);
                                        $canChange = in_array($slug, $mine, true); ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="permissions[]" id="<?= e($id) ?>" value="<?= e($slug) ?>"
                                                <?= $granted || $byRole ? 'checked' : '' ?><?= $byRole || !$canChange ? ' disabled' : '' ?>>
                                            <label class="form-check-label small" for="<?= e($id) ?>"><?= e($desc) ?></label>
                                            <?php if ($granted && !$canChange): ?><input type="hidden" name="permissions[]" value="<?= e($slug) ?>"><?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                    <?php if ($self): ?><p class="small text-muted mb-0 mt-2">You cannot change your own permissions.</p>
                    <?php else: ?><button class="btn btn-wm-green mt-3" type="submit">Save extra permissions</button><?php endif; ?>
                </div>
            </form>
        </div>
        <div class="col-lg-5">
            <section class="wm-panel mb-3">
                <div class="wm-panel-head">Account security</div>
                <div class="wm-panel-body small">
                    <dl class="row mb-2">
                        <dt class="col-6 fw-normal text-muted">Last sign-in</dt><dd class="col-6 mb-1"><?= $staff['last_login_at'] ? e(date('d M Y H:i', strtotime((string) $staff['last_login_at']))) : 'Never' ?></dd>
                        <dt class="col-6 fw-normal text-muted">Two-step sign-in</dt><dd class="col-6 mb-1"><?= $staff['mfa_enabled_at'] ? 'On (' . (int) $recovery . ' recovery codes left)' : 'Off' ?></dd>
                        <dt class="col-6 fw-normal text-muted">Password change</dt><dd class="col-6 mb-1"><?= (int) $staff['must_change_password'] ? 'Required at next use' : 'Not required' ?></dd>
                    </dl>
                    <?php if (!$self): ?>
                        <?php $act = function (string $action, string $label, string $confirm, string $cls = 'btn-outline-secondary') use ($staff): string {
                            return '<form class="d-inline-block me-1 mb-1" method="post" action="' . e(url('/admin/staff/' . $staff['id'] . '/security')) . '" data-confirm="' . e($confirm) . '">'
                                . csrf_field() . '<input type="hidden" name="action" value="' . e($action) . '"><button class="btn btn-sm ' . $cls . '" type="submit">' . e($label) . '</button></form>';
                        }; ?>
                        <?= (int) $staff['must_change_password']
                            ? $act('clear_password', 'Don\'t require a password change', 'No longer require a password change?')
                            : $act('require_password', 'Require a password change', 'They will have to choose a new password the next time they use the admin.') ?>
                        <?= $act('send_reset', 'Email a password reset link', 'Email them a link to set a new password?') ?>
                        <?= $act('revoke_sessions', 'Sign out everywhere', 'Sign them out on every device now?') ?>
                        <?php if ($staff['mfa_enabled_at']): ?><?= $act('reset_mfa', 'Reset two-step sign-in', 'Reset two-step sign-in (for a lost phone)? They will be signed out and can set it up again.', 'btn-outline-danger') ?><?php endif; ?>
                    <?php else: ?>
                        <p class="mb-0"><a href="<?= e(url('/admin/account')) ?>">Manage your own password and two-step sign-in</a></p>
                    <?php endif; ?>
                </div>
            </section>
            <section class="wm-panel mb-3">
                <div class="wm-panel-head">Recent sign-ins</div>
                <ul class="list-group list-group-flush small">
                    <?php foreach ($signIns as $ev): ?>
                        <li class="list-group-item d-flex justify-content-between gap-2"><span><?= e(\Waimart\Services\AuthEvents::LABELS[$ev['event']] ?? $ev['event']) ?> <span class="text-muted"><?= e((string) $ev['ip_address']) ?></span></span>
                            <span class="text-muted text-nowrap"><?= e(date('d M H:i', strtotime((string) $ev['created_at']))) ?></span></li>
                    <?php endforeach; ?>
                    <?php if (!$signIns): ?><li class="list-group-item text-muted">None yet.</li><?php endif; ?>
                </ul>
            </section>
            <section class="wm-panel">
                <div class="wm-panel-head">Recent actions</div>
                <ul class="list-group list-group-flush small">
                    <?php foreach ($actions as $a): ?>
                        <li class="list-group-item d-flex justify-content-between gap-2"><span><code><?= e($a['action']) ?></code> <span class="text-muted"><?= e($a['entity_type'] . ($a['entity_id'] !== null ? ' #' . $a['entity_id'] : '')) ?></span></span>
                            <span class="text-muted text-nowrap"><?= e(date('d M H:i', strtotime((string) $a['created_at']))) ?></span></li>
                    <?php endforeach; ?>
                    <?php if (!$actions): ?><li class="list-group-item text-muted">None yet.</li><?php endif; ?>
                </ul>
            </section>
        </div>
    </div>
<?php endif; ?>
