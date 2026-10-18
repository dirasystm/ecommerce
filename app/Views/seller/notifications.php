<?php
use Waimart\Core\Auth;

$u = Auth::user();
?>
<div class="wm-admin-head"><h1>Notification preferences</h1></div>
<form class="wm-panel" method="post" action="<?= e(url('/seller/notifications')) ?>" style="max-width:640px">
    <?= csrf_field() ?>
    <div class="wm-panel-body">
        <p class="small text-muted">Choose how we contact you about your store, such as new orders and application or account updates. These settings also apply to messages about your own purchases.</p>
        <div class="form-check">
            <input class="form-check-input" type="checkbox" id="notify_email" name="notify_email" value="1" <?= (int) $u['notify_email'] ? 'checked' : '' ?>>
            <label class="form-check-label" for="notify_email">Email (<?= e($u['email']) ?>)</label>
        </div>
        <div class="form-check">
            <input class="form-check-input" type="checkbox" id="notify_sms" name="notify_sms" value="1" <?= (int) $u['notify_sms'] ? 'checked' : '' ?>>
            <label class="form-check-label" for="notify_sms">SMS</label>
        </div>
        <p class="small text-muted mt-2 mb-3">Important account and security messages — such as approval decisions, suspensions and password resets — are always sent.</p>
        <button class="btn btn-wm-green" type="submit">Save preferences</button>
    </div>
</form>
