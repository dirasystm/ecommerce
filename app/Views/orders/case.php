<?php
use Waimart\Services\DisputeService;

/** @var array<string,mixed> $order @var array<string,mixed> $d @var list<array<string,mixed>> $messages */
$back = \Waimart\Services\OrderAccess::isOwner($order) ? '/account/orders/' . $order['order_number'] : '/track/' . $order['order_number'];
$open = in_array($d['status'], DisputeService::OPEN, true);
$who = fn (array $m) => match ($m['author_role']) { 'customer' => 'You', 'seller' => $d['seller_name'], default => 'Waimart' };
?>
<div class="container-wm">
    <nav class="wm-breadcrumb" aria-label="Breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= e(url('/')) ?>">Home</a></li>
            <li class="breadcrumb-item"><a href="<?= e(url($back)) ?>"><?= e($order['order_number']) ?></a></li>
            <li class="breadcrumb-item active" aria-current="page">Case <?= e($d['dispute_number']) ?></li>
        </ol>
    </nav>
    <div class="wm-card wm-page" style="max-width:820px">
        <h1>Case <?= e($d['dispute_number']) ?></h1>
        <p class="mb-1"><span class="badge <?= $open ? 'badge-soft-warning' : 'badge-soft-success' ?>"><?= e(DisputeService::STATUS_LABELS[$d['status']]) ?></span></p>
        <p class="small text-muted">Items sold by <strong><?= e($d['seller_name']) ?></strong> · <?= e(DisputeService::REASONS[$d['reason']] ?? $d['reason']) ?> · opened <?= e(date('d M Y', strtotime((string) $d['created_at']))) ?></p>
        <?php if ($d['status'] === 'resolved'): ?>
            <div class="alert alert-success small"><strong>Decision:</strong> <?= e(DisputeService::RESOLUTIONS[$d['resolution']] ?? '') ?>. <?= e((string) $d['resolution_note']) ?></div>
        <?php endif; ?>
        <h2 class="fs-6 fw-bold mt-3">Messages</h2>
        <ol class="list-unstyled">
            <li class="border rounded p-2 mb-2 small"><div class="text-muted">You · <?= e(date('d M Y H:i', strtotime((string) $d['created_at']))) ?></div><?= nl2br(e((string) $d['details'])) ?></li>
            <?php foreach ($messages as $m): ?>
                <li class="border rounded p-2 mb-2 small"><div class="text-muted"><?= e($who($m)) ?> · <?= e(date('d M Y H:i', strtotime((string) $m['created_at']))) ?></div><?= nl2br(e((string) $m['body'])) ?></li>
            <?php endforeach; ?>
        </ol>
        <?php if ($open): ?>
            <form method="post" action="<?= e(url('/order/' . $order['order_number'] . '/cases/' . $d['id'] . '/message')) ?>" novalidate>
                <?= csrf_field() ?>
                <label class="form-label" for="body">Add a message</label>
                <textarea class="form-control<?= invalid('body') ?>" id="body" name="body" rows="3" maxlength="2000"></textarea>
                <?= field_error('body') ?>
                <button class="btn btn-wm mt-2" type="submit">Send</button>
            </form>
            <?php if ($d['raised_by_role'] === 'customer'): ?>
                <form class="mt-3" method="post" action="<?= e(url('/order/' . $order['order_number'] . '/cases/' . $d['id'] . '/withdraw')) ?>" data-confirm="Withdraw this case? You can contact customer care if the problem comes back.">
                    <?= csrf_field() ?><button class="btn btn-link btn-sm p-0 text-danger" type="submit">Withdraw this case (problem solved)</button></form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
