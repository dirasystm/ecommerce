<?php /** @var string $token @var bool $valid */ ?>
<div class="container-wm">
    <div class="wm-auth-card wm-card">
        <h1>Set a new password</h1>
        <?php if (!$valid): ?>
            <div class="alert alert-warning" role="alert">This reset link is invalid, has already been used, or has expired.</div>
            <a class="btn btn-wm w-100" href="<?= e(url('/forgot-password')) ?>">Request a new link</a>
        <?php else: ?>
            <form method="post" action="<?= e(url('/reset-password')) ?>" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="token" value="<?= e($token) ?>">
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
                <button class="btn btn-wm w-100" type="submit">Change password</button>
            </form>
        <?php endif; ?>
    </div>
</div>
