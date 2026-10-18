<div class="container-wm">
    <div class="wm-auth-card wm-card">
        <h1>Forgot your password?</h1>
        <p class="text-muted small">Enter the email address on your account and we'll send you a link to set a new password.</p>
        <form method="post" action="<?= e(url('/forgot-password')) ?>" novalidate>
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label" for="email">Email address</label>
                <input class="form-control<?= invalid('email') ?>" id="email" name="email" type="email" autocomplete="email" required value="<?= e(old('email')) ?>">
                <?= field_error('email') ?>
            </div>
            <button class="btn btn-wm w-100" type="submit">Send reset link</button>
        </form>
        <p class="small text-center mt-3 mb-0"><a href="<?= e(url('/login')) ?>">Back to sign in</a></p>
    </div>
</div>
