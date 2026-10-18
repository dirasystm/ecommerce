<div class="container-wm">
    <div class="wm-auth-card wm-card">
        <h1>Sign in</h1>
        <p class="text-muted small">Welcome back! Sign in to see your orders, saved addresses and wishlist.</p>
        <form method="post" action="<?= e(url('/login')) ?>" novalidate>
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label" for="email">Email address</label>
                <input class="form-control" id="email" name="email" type="email" autocomplete="email" required value="<?= e(old('email')) ?>">
            </div>
            <div class="mb-2">
                <label class="form-label" for="password">Password</label>
                <input class="form-control" id="password" name="password" type="password" autocomplete="current-password" required>
            </div>
            <div class="text-end mb-3"><a class="small" href="<?= e(url('/forgot-password')) ?>">Forgot your password?</a></div>
            <button class="btn btn-wm w-100" type="submit">Sign in</button>
        </form>
        <hr>
        <p class="small text-center mb-2">New to <?= e(brand_name()) ?>?</p>
        <a class="btn btn-outline-wm w-100" href="<?= e(url('/register')) ?>">Create an account</a>
        <p class="small text-muted text-center mt-3 mb-0">You can also <a href="<?= e(url('/cart')) ?>">check out as a guest</a> without an account.</p>
    </div>
</div>
