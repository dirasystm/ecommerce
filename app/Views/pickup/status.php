<?php
use Waimart\Services\PickupApplicationService as P;

/** @var array<string,mixed>|null $application @var bool $searched */
$a = $application;
?>
<div class="container-wm">
    <nav class="wm-breadcrumb" aria-label="Breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= e(url('/')) ?>">Home</a></li>
            <li class="breadcrumb-item"><a href="<?= e(url('/become-a-pickup-station')) ?>">Become a pickup station</a></li>
            <li class="breadcrumb-item active" aria-current="page">Application status</li>
        </ol>
    </nav>
    <div class="wm-auth-card wm-card">
        <h1>Check your application</h1>
        <?php if ($a !== null): ?>
            <?php $tone = ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger'][$a['status']] ?? 'muted'; ?>
            <div class="wm-ps-status wm-ps-status-<?= e($tone) ?>" role="status">
                <div class="small text-uppercase fw-bold"><?= e((string) $a['reference']) ?></div>
                <div class="fs-5 fw-bold"><?= e(P::STATUSES[$a['status']] ?? (string) $a['status']) ?></div>
                <div class="small"><?= e((string) $a['business_name']) ?> · <?= e((string) $a['town']) ?> · applied <?= e(date('j M Y', strtotime((string) $a['created_at']))) ?></div>
            </div>
            <?php if ($a['status'] === 'pending'): ?>
                <p class="small mt-3 mb-0">Our team is verifying your details. This usually takes up to 3 working days. We may call you on the number you gave.</p>
            <?php elseif ($a['status'] === 'approved'): ?>
                <p class="small mt-3 mb-0">Congratulations, your shop is a <?= e(brand_name()) ?> pickup station. Our team will contact you to get started.</p>
            <?php else: ?>
                <p class="small mt-3 mb-1"><strong>Reason:</strong> <?= e((string) $a['decision_note']) ?></p>
                <p class="small mb-0">You are welcome to <a href="<?= e(url('/become-a-pickup-station')) ?>#apply">apply again</a> once this has been addressed.</p>
            <?php endif; ?>
            <hr>
        <?php elseif ($searched): ?>
            <div class="alert alert-warning small" role="alert">We could not find an application with those details. Check the reference (e.g. PS-7K3F9Q2X) and the phone number you applied with.</div>
        <?php endif; ?>
        <p class="text-muted small">Enter the reference we sent you and the phone number on the application.</p>
        <form method="post" action="<?= e(url('/become-a-pickup-station/check')) ?>" novalidate>
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label" for="reference">Reference number</label>
                <input class="form-control text-uppercase" id="reference" name="reference" required maxlength="12" autocomplete="off" placeholder="PS-XXXXXXXX" value="<?= e((string) fv('reference', $a['reference'] ?? '')) ?>">
            </div>
            <div class="mb-3">
                <label class="form-label" for="phone">Phone number</label>
                <input class="form-control" id="phone" name="phone" type="tel" inputmode="tel" required autocomplete="tel" placeholder="0712 345 678">
            </div>
            <button class="btn btn-wm w-100" type="submit">Check status</button>
        </form>
        <p class="small text-muted mt-3 mb-0">Not applied yet? <a href="<?= e(url('/become-a-pickup-station')) ?>">Become a pickup station</a>.</p>
    </div>
</div>
