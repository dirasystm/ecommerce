<?php
use Waimart\Core\Auth;
use Waimart\Core\Validator;
use Waimart\Core\View;

$u = Auth::user();
ob_start(); ?>
<form method="post" action="<?= e(url('/account/profile')) ?>" style="max-width:560px" novalidate>
    <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-sm-6">
            <label class="form-label" for="first_name">First name</label>
            <input class="form-control<?= invalid('first_name') ?>" id="first_name" name="first_name" autocomplete="given-name" value="<?= e(fv('first_name', $u['first_name'])) ?>">
            <?= field_error('first_name') ?>
        </div>
        <div class="col-sm-6">
            <label class="form-label" for="last_name">Last name</label>
            <input class="form-control<?= invalid('last_name') ?>" id="last_name" name="last_name" autocomplete="family-name" value="<?= e(fv('last_name', $u['last_name'])) ?>">
            <?= field_error('last_name') ?>
        </div>
        <div class="col-12">
            <label class="form-label" for="phone">Phone number</label>
            <input class="form-control<?= invalid('phone') ?>" id="phone" name="phone" type="tel" autocomplete="tel" value="<?= e(fv('phone', $u['phone'] ? Validator::displayPhone($u['phone']) : '')) ?>">
            <?= field_error('phone') ?>
        </div>
        <div class="col-12">
            <label class="form-label" for="email">Email address</label>
            <input class="form-control<?= invalid('email') ?>" id="email" name="email" type="email" autocomplete="email" value="<?= e(fv('email', $u['email'])) ?>">
            <?= field_error('email') ?>
        </div>
        <div class="col-12">
            <label class="form-label" for="current_password">Current password <span class="text-muted fw-normal">(only needed to change your email or phone number)</span></label>
            <input class="form-control<?= invalid('current_password') ?>" id="current_password" name="current_password" type="password" autocomplete="current-password">
            <?= field_error('current_password') ?>
        </div>
    </div>
    <button class="btn btn-wm mt-4" type="submit">Save changes</button>
</form>
<?php
$body = (string) ob_get_clean();
echo View::partial('account/_layout', compact('title', 'section', 'body'));
