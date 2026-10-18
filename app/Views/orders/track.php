<?php
use Waimart\Core\View;

/** @var array<string,mixed> $order @var bool $full */
?>
<div class="container-wm">
    <nav class="wm-breadcrumb" aria-label="Breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= e(url('/')) ?>">Home</a></li>
            <?php if ($full): ?>
                <li class="breadcrumb-item"><a href="<?= e(url('/account/orders')) ?>">My orders</a></li>
            <?php else: ?>
                <li class="breadcrumb-item"><a href="<?= e(url('/track-order')) ?>">Track order</a></li>
            <?php endif; ?>
            <li class="breadcrumb-item active" aria-current="page"><?= e($order['order_number']) ?></li>
        </ol>
    </nav>
    <h1 class="visually-hidden">Order <?= e($order['order_number']) ?></h1>
    <?= View::partial('orders/_detail', ['order' => $order, 'full' => $full, 'collectionCode' => $collectionCode ?? null]) ?>
</div>
