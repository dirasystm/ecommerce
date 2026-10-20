<?php /** @var string $for what the customer typed (email or phone) @var int $minutes */ ?>
<div class="container-wm">
    <div class="wm-auth-card wm-card">
        <h1>Enter your reset code</h1>
        <p class="text-muted small" id="codeHelp">We sent a 6-digit code by SMS to the phone number on the account for <strong><?= e($for) ?></strong>, if there is one.
            It works for <?= (int) $minutes ?> minutes. Never share it: our staff will never ask you for it.</p>
        <form method="post" action="<?= e(url('/forgot-password/code')) ?>" novalidate>
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label" for="code">6-digit code</label>
                <input class="form-control form-control-lg text-center<?= invalid('code') ?>" id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code"
                       pattern="[0-9 ]*" maxlength="7" required aria-describedby="codeHelp" style="letter-spacing:.3em;font-weight:700">
                <?= field_error('code') ?>
            </div>
            <button class="btn btn-wm w-100" type="submit">Continue</button>
        </form>
        <p class="small text-center mt-3 mb-0">Didn't get it? <a href="<?= e(url('/forgot-password')) ?>">Send a new code or use email instead</a></p>
    </div>
</div>
