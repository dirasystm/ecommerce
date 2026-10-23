<div class="container-wm">
    <div class="wm-auth-card wm-card">
        <h1>Sign in</h1>
        <p class="text-muted small">Welcome back! Sign in to see your orders, saved addresses and wishlist.</p>
        <form method="post" action="<?= e(url('/login')) ?>" novalidate>
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label" for="login">Email or phone number</label>
                <input class="form-control" id="login" name="login" type="text" autocomplete="username" autocapitalize="none" spellcheck="false" required
                       placeholder="you@example.com or 0712 345 678" value="<?= e((string) (old('login') ?: old('email'))) ?>">
            </div>
            <div class="mb-2">
                <label class="form-label" for="password">Password</label>
                <input class="form-control" id="password" name="password" type="password" autocomplete="current-password" required>
            </div>
            <div class="text-end mb-3"><a class="small" href="<?= e(url('/forgot-password')) ?>">Forgot your password?</a></div>
            <div class="form-check mb-3">
                <input type="hidden" name="remember" value="0">
                <input class="form-check-input" type="checkbox" id="remember" name="remember" value="1" <?= old('remember', '1') === '1' ? 'checked' : '' ?>>
                <label class="form-check-label small" for="remember">Keep me signed in on this device <span class="text-muted">(untick on a shared phone or computer)</span></label>
            </div>
            <button class="btn btn-wm w-100" type="submit">Sign in</button>
        </form>
        <hr>
        <p class="small text-center mb-2">New to <?= e(brand_name()) ?>?</p>
        <?php if (\Waimart\Services\AccountService::registrationOpen()): ?><a class="btn btn-outline-wm w-100" href="<?= e(url('/register')) ?>">Create an account</a><?php endif; ?>
        <p class="small text-muted text-center mt-3 mb-0">You can also <a href="<?= e(url('/cart')) ?>">check out as a guest</a> without an account.</p>
    </div>
</div>
