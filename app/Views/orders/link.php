<?php
use Waimart\Core\View;

/** @var string $step */
ob_start(); ?>
<p class="small text-muted">Placed an order as a guest? Add it to your account. To protect your privacy we send a one-time code to the phone number
    (and email, if given) used for that order — the order is added only after you enter the code.</p>
<?php if ($step === 'number'): ?>
    <form method="post" action="<?= e(url('/account/orders/link')) ?>" style="max-width:420px" novalidate>
        <?= csrf_field() ?>
        <label class="form-label" for="order_number">Order number</label>
        <input class="form-control text-uppercase mb-3" id="order_number" name="order_number" required maxlength="11" placeholder="WM-XXXXXXXX">
        <button class="btn btn-wm" type="submit">Send code</button>
    </form>
<?php else: ?>
    <form method="post" action="<?= e(url('/account/orders/link/verify')) ?>" style="max-width:420px" novalidate>
        <?= csrf_field() ?>
        <label class="form-label" for="code">6-digit code</label>
        <input class="form-control mb-1<?= invalid('code') ?>" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required>
        <?= field_error('code') ?>
        <button class="btn btn-wm mt-3" type="submit">Add order</button>
    </form>
    <form method="post" action="<?= e(url('/account/orders/link/cancel')) ?>" class="mt-2">
        <?= csrf_field() ?><button class="btn btn-link btn-sm px-0" type="submit">Use a different order number</button>
    </form>
<?php endif; ?>
<?php
$body = (string) ob_get_clean();
echo View::partial('account/_layout', compact('title', 'section', 'body'));
