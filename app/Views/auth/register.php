<div class="container-wm">
    <div class="wm-auth-card wm-card">
        <h1>Create your account</h1>
        <p class="text-muted small">Save addresses, track orders and checkout faster.</p>
        <form method="post" action="<?= e(url('/register')) ?>" novalidate>
            <?= csrf_field() ?>
            <div class="row g-3">
                <div class="col-sm-6">
                    <label class="form-label" for="first_name">First name</label>
                    <input class="form-control<?= invalid('first_name') ?>" id="first_name" name="first_name" autocomplete="given-name" required maxlength="80" value="<?= e(old('first_name')) ?>">
                    <?= field_error('first_name') ?>
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="last_name">Last name</label>
                    <input class="form-control<?= invalid('last_name') ?>" id="last_name" name="last_name" autocomplete="family-name" required maxlength="80" value="<?= e(old('last_name')) ?>">
                    <?= field_error('last_name') ?>
                </div>
                <div class="col-12">
                    <label class="form-label" for="email">Email address</label>
                    <input class="form-control<?= invalid('email') ?>" id="email" name="email" type="email" autocomplete="email" required value="<?= e(old('email')) ?>">
                    <?= field_error('email') ?>
                </div>
                <div class="col-12">
                    <label class="form-label" for="phone">Phone number</label>
                    <input class="form-control<?= invalid('phone') ?>" id="phone" name="phone" type="tel" autocomplete="tel" inputmode="tel" placeholder="0712 345 678" required value="<?= e(old('phone')) ?>">
                    <?= field_error('phone') ?>
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="password">Password</label>
                    <input class="form-control<?= invalid('password') ?>" id="password" name="password" type="password" autocomplete="new-password" required aria-describedby="pwHelp">
                    <?= field_error('password') ?>
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="password_confirmation">Confirm password</label>
                    <input class="form-control<?= invalid('password_confirmation') ?>" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
                    <?= field_error('password_confirmation') ?>
                </div>
                <div class="col-12"><div id="pwHelp" class="form-text mt-0">At least 8 characters, including a letter and a number.</div></div>
                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input<?= invalid('accept_terms') ?>" type="checkbox" id="accept_terms" name="accept_terms" value="1" required <?= old('accept_terms') ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="accept_terms">I agree to the <a href="<?= e(url('/terms')) ?>" target="_blank">terms</a> and <a href="<?= e(url('/privacy')) ?>" target="_blank">privacy policy</a>.</label>
                        <?= field_error('accept_terms') ?>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="marketing_opt_in" name="marketing_opt_in" value="1" <?= old('marketing_opt_in') ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="marketing_opt_in">Send me deals and offers (optional).</label>
                    </div>
                </div>
            </div>
            <button class="btn btn-wm w-100 mt-4" type="submit">Create account</button>
        </form>
        <p class="small text-center mt-3 mb-0">Already have an account? <a href="<?= e(url('/login')) ?>">Sign in</a></p>
    </div>
</div>
