<?php
use Waimart\Core\Auth;
use Waimart\Core\View;

$u = Auth::user();
ob_start(); ?>
<form method="post" action="<?= e(url('/account/notifications')) ?>" style="max-width:560px">
    <?= csrf_field() ?>
    <fieldset class="mb-3">
        <legend class="fs-6 fw-semibold">Order updates</legend>
        <p class="small text-muted">Messages about your orders: confirmation, payment verification, dispatch, ready-for-collection and delivery.</p>
        <div class="form-check">
            <input class="form-check-input" type="checkbox" id="notify_email" name="notify_email" value="1" <?= (int) $u['notify_email'] ? 'checked' : '' ?>>
            <label class="form-check-label" for="notify_email">Email</label>
        </div>
        <div class="form-check">
            <input class="form-check-input" type="checkbox" id="notify_sms" name="notify_sms" value="1" <?= (int) $u['notify_sms'] ? 'checked' : '' ?>>
            <label class="form-check-label" for="notify_sms">SMS</label>
        </div>
        <p class="small text-muted mt-2 mb-0">Some messages, such as password resets and pickup collection codes, are always sent because they are needed to keep your account and orders secure.</p>
    </fieldset>
    <fieldset class="mb-3">
        <legend class="fs-6 fw-semibold">Marketing</legend>
        <div class="form-check">
            <input class="form-check-input" type="checkbox" id="marketing_opt_in" name="marketing_opt_in" value="1" <?= (int) $u['marketing_opt_in'] ? 'checked' : '' ?>>
            <label class="form-check-label" for="marketing_opt_in">Send me deals and offers</label>
        </div>
    </fieldset>
    <button class="btn btn-wm" type="submit">Save preferences</button>
</form>
<?php
$body = (string) ob_get_clean();
echo View::partial('account/_layout', compact('title', 'section', 'body'));
