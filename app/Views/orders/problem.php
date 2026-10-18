<?php
/** @var array<string,mixed> $order @var list<array<string,mixed>> $parts @var array<string,string> $reasons */
$back = \Waimart\Services\OrderAccess::isOwner($order) ? '/account/orders/' . $order['order_number'] : '/track/' . $order['order_number'];
?>
<div class="container-wm">
    <nav class="wm-breadcrumb" aria-label="Breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= e(url('/')) ?>">Home</a></li>
            <li class="breadcrumb-item"><a href="<?= e(url($back)) ?>"><?= e($order['order_number']) ?></a></li>
            <li class="breadcrumb-item active" aria-current="page">Report a problem</li>
        </ol>
    </nav>
    <form class="wm-card wm-page" method="post" action="<?= e(url('/order/' . $order['order_number'] . '/problem')) ?>" style="max-width:720px" novalidate>
        <?= csrf_field() ?>
        <h1>Report a problem with a seller's items</h1>
        <p class="small text-muted"><?= e(brand_name()) ?> looks into every case with you and the seller. If you simply want to send items back, use <strong>Request a return</strong> on your order instead.</p>
        <fieldset class="mb-3">
            <legend class="form-label fs-6">Which items?</legend>
            <?php foreach ($parts as $i => $p): ?>
                <div class="form-check">
                    <input class="form-check-input<?= invalid('seller_order_id') ?>" type="radio" name="seller_order_id" id="so<?= (int) $p['id'] ?>" value="<?= (int) $p['id'] ?>" <?= (string) fv('seller_order_id', count($parts) === 1 ? (string) $p['id'] : '') === (string) $p['id'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="so<?= (int) $p['id'] ?>">Sold by <strong><?= e($p['seller_name']) ?></strong> <span class="text-muted small">(<?= e($p['sub_number']) ?>)</span></label>
                </div>
            <?php endforeach; ?>
            <?= field_error('seller_order_id') ?>
        </fieldset>
        <label class="form-label" for="reason">What is the problem?</label>
        <select class="form-select<?= invalid('reason') ?>" id="reason" name="reason" required>
            <option value="">Choose…</option>
            <?php foreach ($reasons as $k => $l): ?><option value="<?= e($k) ?>" <?= fv('reason', '') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
        </select>
        <?= field_error('reason') ?>
        <label class="form-label mt-3" for="details">Tell us what happened</label>
        <textarea class="form-control<?= invalid('details') ?>" id="details" name="details" rows="4" maxlength="2000" required><?= e(fv('details', '')) ?></textarea>
        <?= field_error('details') ?>
        <p class="small text-muted mt-2 mb-0">The seller sees your message and your first name only — never your phone number, email or address.</p>
        <button class="btn btn-wm mt-3" type="submit">Open a case</button>
    </form>
</div>
