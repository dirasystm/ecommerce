<?php /** @var bool $open */ ?>
<div class="container-wm">
    <div class="wm-auth-card wm-card">
        <h1>Create your account</h1>
<?php if (!($open ?? true)): ?>
        <div class="alert alert-info small">New accounts cannot be created at the moment. You can still <a href="<?= e(url('/cart')) ?>">check out as a guest</a> and track your order with its number.</div>
<?php else: ?>
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
                    <div class="form-check mt-2">
                        <input type="hidden" name="remember" value="0">
                        <input class="form-check-input" type="checkbox" id="remember" name="remember" value="1" <?= old('remember', '1') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="remember">Keep me signed in on this device</label>
                    </div>
                    <fieldset class="mt-2">
                        <legend class="small fw-semibold mb-1">Your choices (optional, change them any time)</legend>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="marketing_email" name="marketing_email" value="1" <?= old('marketing_email') ? 'checked' : '' ?>>
                            <label class="form-check-label small" for="marketing_email"><?= e(\Waimart\Services\Marketing\Consent::WORDING['email']) ?></label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="marketing_sms" name="marketing_sms" value="1" <?= old('marketing_sms') ? 'checked' : '' ?>>
                            <label class="form-check-label small" for="marketing_sms"><?= e(\Waimart\Services\Marketing\Consent::WORDING['sms']) ?></label>
                        </div>
                        <input type="hidden" name="personalisation_shown" value="1">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="personalisation" name="personalisation" value="1" <?= !old('personalisation_shown') || old('personalisation') ? 'checked' : '' ?>>
                            <label class="form-check-label small" for="personalisation"><?= e(\Waimart\Services\Marketing\Consent::WORDING['personalisation']) ?></label>
                        </div>
                    </fieldset>
                </div>
            </div>
            <button class="btn btn-wm w-100 mt-4" type="submit">Create account</button>
        </form>
<?php endif; ?>
        <p class="small text-center mt-3 mb-0">Already have an account? <a href="<?= e(url('/login')) ?>">Sign in</a></p>
    </div>
</div>
