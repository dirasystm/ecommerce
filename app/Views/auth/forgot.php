<?php /** @var bool $smsAvailable @var bool $staff from the staff sign-in page */
$channel = (string) (old('channel') ?: 'email'); ?>
<div class="container-wm">
    <div class="wm-auth-card wm-card">
        <h1>Forgot your password?</h1>
        <p class="text-muted small" id="forgotHelp">Enter the email address or phone number on your account<?= $smsAvailable ? ' and choose how you would like to reset your password' : ' and we will email you a link to set a new password' ?>.</p>
        <form method="post" action="<?= e(url('/forgot-password')) ?>" novalidate>
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label" for="login">Email or phone number</label>
                <input class="form-control<?= invalid('login') ?>" id="login" name="login" type="text" autocomplete="username" autocapitalize="none" spellcheck="false" required
                       aria-describedby="forgotHelp" placeholder="you@example.com or 0712 345 678" value="<?= e((string) (old('login') ?: old('email'))) ?>">
                <?= field_error('login') ?>
            </div>
            <?php if ($smsAvailable): ?>
                <fieldset class="mb-3">
                    <legend class="form-label fs-6 mb-2">Send me</legend>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio" name="channel" id="channelEmail" value="email" <?= $channel !== 'sms' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="channelEmail"><i class="bi bi-envelope" aria-hidden="true"></i> An email with a reset link
                            <span class="d-block small text-muted">Sent to the email address on your account. The link works for <?= (int) \Waimart\Services\AccountService::RESET_TTL_MINUTES ?> minutes.</span></label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="channel" id="channelSms" value="sms" <?= $channel === 'sms' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="channelSms"><i class="bi bi-phone" aria-hidden="true"></i> An SMS with a 6-digit code
                            <span class="d-block small text-muted">Sent to the phone number on your account. The code works for <?= (int) \Waimart\Services\AccountService::RESET_CODE_MINUTES ?> minutes.</span></label>
                    </div>
                    <?= field_error('channel') ?>
                </fieldset>
            <?php endif; ?>
            <button class="btn btn-wm w-100" type="submit">Continue</button>
        </form>
        <p class="small text-center mt-3 mb-0">
            <?php if ($staff): ?><a href="<?= e(url('/admin/login')) ?>">Back to staff sign in</a>
            <?php else: ?><a href="<?= e(url('/login')) ?>">Back to sign in</a><?php endif; ?>
        </p>
    </div>
</div>
