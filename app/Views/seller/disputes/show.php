<?php
use Waimart\Services\DisputeService;

/** @var array<string,mixed> $d @var list<array<string,mixed>> $messages @var bool $canReply */
$open = in_array($d['status'], DisputeService::OPEN, true);
$who = fn (array $m) => match ($m['author_role']) { 'customer' => $d['customer_name'] . ' (customer)', 'seller' => 'Your store', default => brand_name() };
?>
<div class="wm-admin-head">
    <h1>Case <?= e($d['dispute_number']) ?></h1>
    <span class="badge fs-6 <?= $open ? 'badge-soft-warning' : 'badge-soft-success' ?>"><?= e(brand_text(DisputeService::STATUS_LABELS[$d['status']])) ?></span>
</div>
<p><a href="<?= e(url('/seller/disputes')) ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> Returns &amp; cases</a></p>
<div class="row g-3">
    <div class="col-lg-8">
        <section class="wm-panel">
            <div class="wm-panel-head">Messages</div>
            <div class="wm-panel-body">
                <div class="border rounded p-2 mb-2 small"><div class="text-muted"><?= e($d['raised_by_role'] === 'customer' ? $d['customer_name'] . ' (customer)' : brand_name()) ?> · <?= e(date('d M Y H:i', strtotime((string) $d['created_at']))) ?></div><?= nl2br(e((string) $d['details'])) ?></div>
                <?php foreach ($messages as $m): ?>
                    <div class="border rounded p-2 mb-2 small"><div class="text-muted"><?= e($who($m)) ?> · <?= e(date('d M Y H:i', strtotime((string) $m['created_at']))) ?></div><?= nl2br(e((string) $m['body'])) ?></div>
                <?php endforeach; ?>
                <?php if ($open && $canReply): ?>
                    <form method="post" action="<?= e(url('/seller/disputes/' . $d['id'] . '/message')) ?>" novalidate>
                        <?= csrf_field() ?>
                        <label class="form-label small" for="body">Your reply (the customer and <?= e(brand_name()) ?> see it)</label>
                        <textarea class="form-control<?= invalid('body') ?>" id="body" name="body" rows="3" maxlength="2000"></textarea>
                        <?= field_error('body') ?>
                        <button class="btn btn-wm-green mt-2" type="submit">Send reply</button>
                    </form>
                <?php elseif ($open): ?>
                    <p class="small text-muted mb-0">Only the store owner or a manager can reply.</p>
                <?php endif; ?>
            </div>
        </section>
    </div>
    <div class="col-lg-4">
        <section class="wm-panel">
            <div class="wm-panel-head">Details</div>
            <dl class="row small wm-panel-body mb-0">
                <dt class="col-5">Order</dt><dd class="col-7"><a href="<?= e(url('/seller/orders/' . $d['seller_order_id'])) ?>"><?= e($d['sub_number']) ?></a></dd>
                <dt class="col-5">Customer</dt><dd class="col-7"><?= e($d['customer_name']) ?></dd>
                <dt class="col-5">Problem</dt><dd class="col-7"><?= e(DisputeService::REASONS[$d['reason']] ?? $d['reason']) ?></dd>
                <dt class="col-5">Opened</dt><dd class="col-7"><?= e(date('d M Y', strtotime((string) $d['created_at']))) ?></dd>
                <?php if ($d['status'] === 'resolved'): ?>
                    <dt class="col-5">Decision</dt><dd class="col-7"><?= e(DisputeService::RESOLUTIONS[$d['resolution']] ?? '') ?><div class="text-muted"><?= e((string) $d['resolution_note']) ?></div></dd>
                <?php endif; ?>
            </dl>
        </section>
    </div>
</div>
