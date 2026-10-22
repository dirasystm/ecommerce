<?php /** @var bool $done @var bool $valid @var string $channel @var string $action */
$what = $channel === 'sms' ? 'marketing SMS' : 'marketing emails'; ?>
<div class="container-wm">
    <div class="wm-auth-card wm-card text-center">
        <?php if ($done): ?>
            <h1>You are unsubscribed</h1>
            <p>You will not receive <?= e($what) ?> from <?= e(brand_name()) ?> any more. Messages about your orders and account are not affected.</p>
            <p class="small text-muted">Changed your mind? Sign in and go to My account → Privacy &amp; marketing.</p>
            <a class="btn btn-wm" href="<?= e(url('/')) ?>">Back to the shop</a>
        <?php elseif (!$valid): ?>
            <h1>Link not valid</h1>
            <p>This unsubscribe link is incomplete or has been changed. Use the link in the latest message, or sign in and change your choices under My account → Privacy &amp; marketing.</p>
            <a class="btn btn-outline-wm" href="<?= e(url('/login')) ?>">Sign in</a>
        <?php else: ?>
            <h1>Unsubscribe</h1>
            <p>Stop receiving <?= e($what) ?> from <?= e(brand_name()) ?>?</p>
            <form method="post" action="<?= e(url($action)) ?>"><?= csrf_field() ?>
                <button class="btn btn-wm w-100" type="submit">Yes, unsubscribe me</button></form>
            <p class="small text-muted mt-3 mb-0">Order and account messages will still be sent.</p>
        <?php endif; ?>
    </div>
</div>
