<?php /** @var ?bool $choice @var bool $signedIn */ ?>
<div class="container-wm">
    <div class="wm-auth-card wm-card">
        <h1>Your privacy choices</h1>
        <?php if ($signedIn): ?>
            <p>You are signed in, so your choices are kept with your account.</p>
            <a class="btn btn-wm w-100" href="<?= e(url('/account/privacy')) ?>">Go to Privacy &amp; marketing</a>
        <?php else: ?>
            <p>With your permission, <?= e(brand_name()) ?> remembers the products you look at in this browser to suggest products you may like.
                We use a random code stored in this browser, not your name or contact details, and we do not share it.</p>
            <p class="small">Right now: <strong><?= $choice === true ? 'personalised suggestions are on' : ($choice === false ? 'personalised suggestions are off' : 'you have not chosen yet') ?></strong>.</p>
            <form method="post" action="<?= e(url('/privacy-choices')) ?>" class="d-flex flex-wrap gap-2"><?= csrf_field() ?>
                <button class="btn btn-wm" type="submit" name="personalisation" value="1">Allow personalised suggestions</button>
                <button class="btn btn-outline-secondary" type="submit" name="personalisation" value="0">No thanks</button>
            </form>
            <p class="small text-muted mt-3 mb-0">Saying no also deletes what this browser has viewed here. Read our <a href="<?= e(url('/privacy')) ?>">privacy policy</a>.</p>
        <?php endif; ?>
    </div>
</div>
