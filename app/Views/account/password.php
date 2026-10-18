<?php
use Waimart\Core\View;

ob_start(); ?>
<form method="post" action="<?= e(url('/account/password')) ?>" style="max-width:420px" novalidate>
    <?= csrf_field() ?>
    <div class="mb-3">
        <label class="form-label" for="current_password">Current password</label>
        <input class="form-control<?= invalid('current_password') ?>" id="current_password" name="current_password" type="password" autocomplete="current-password" required>
        <?= field_error('current_password') ?>
    </div>
    <div class="mb-3">
        <label class="form-label" for="password">New password</label>
        <input class="form-control<?= invalid('password') ?>" id="password" name="password" type="password" autocomplete="new-password" required aria-describedby="pwHelp">
        <div id="pwHelp" class="form-text">At least 8 characters, including a letter and a number.</div>
        <?= field_error('password') ?>
    </div>
    <div class="mb-3">
        <label class="form-label" for="password_confirmation">Confirm new password</label>
        <input class="form-control<?= invalid('password_confirmation') ?>" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
        <?= field_error('password_confirmation') ?>
    </div>
    <p class="small text-muted">Changing your password signs you out on all other devices.</p>
    <button class="btn btn-wm" type="submit">Change password</button>
</form>
<?php
$body = (string) ob_get_clean();
echo View::partial('account/_layout', compact('title', 'section', 'body'));
