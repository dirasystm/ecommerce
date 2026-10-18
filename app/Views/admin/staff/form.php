<?php
use Waimart\Core\Auth;

/** @var array<string,mixed>|null $staff @var list<string> $current @var list<array<string,mixed>> $roles */
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
