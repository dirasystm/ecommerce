<?php
use Waimart\Core\Auth;
use Waimart\Core\View;

/** @var array<string,mixed> $order @var bool $full @var ?string $linkToken */
$trackUrl = $linkToken ? rtrim((string) config('app.url'), '/') . '/track/' . $order['order_number'] . '?t=' . $linkToken : null;
?>
<div class="container-wm">
    <div class="wm-card wm-confirm-hero my-3">
        <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
        <div>
            <h1>Thank you! Your order has been placed.</h1>
            <p class="mb-0">Order number <strong class="wm-order-no"><?= e($order['order_number']) ?></strong>. We've sent the details to your phone<?= $order['customer_email'] ? ' and email' : '' ?>.</p>
        </div>
    </div>

    <?php if ($trackUrl && !Auth::check()): ?>
        <div class="alert alert-info small" role="note">
            <strong>Save your private tracking link.</strong> Anyone with this link can see your order status, so keep it to yourself:
            <input class="form-control form-control-sm mt-2" type="text" readonly value="<?= e($trackUrl) ?>" aria-label="Private tracking link" data-select-on-focus>
            You can also track your order any time with your order number and phone number on the <a href="<?= e(url('/track-order')) ?>">Track order</a> page.
        </div>
    <?php endif; ?>

    <?= View::partial('orders/_detail', ['order' => $order, 'full' => $full, 'collectionCode' => $collectionCode ?? null]) ?>

    <div class="d-flex flex-wrap gap-2 mt-3">
        <a class="btn btn-wm" href="<?= e(url('/')) ?>">Continue shopping</a>
        <?php if (Auth::check()): ?>
            <a class="btn btn-outline-wm" href="<?= e(url('/account/orders/' . $order['order_number'])) ?>">View in my account</a>
        <?php else: ?>
            <a class="btn btn-outline-wm" href="<?= e(url('/register')) ?>">Create an account</a>
        <?php endif; ?>
    </div>
</div>
