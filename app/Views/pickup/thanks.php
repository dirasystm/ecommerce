<?php /** @var array{reference:string,business:string,email:string} $done */ ?>
<div class="container-wm">
    <section class="wm-card wm-page my-3 text-center" style="max-width:720px;margin-left:auto;margin-right:auto">
        <div class="wm-ps-done-icon" aria-hidden="true"><i class="bi bi-check-lg"></i></div>
        <h1 class="h3 mt-3">Application received</h1>
        <p class="lead mb-2">Thank you. We have received the application for <strong><?= e($done['business']) ?></strong>.</p>
        <p class="mb-1">Your reference number is</p>
        <p class="wm-ps-ref" aria-label="Reference <?= e(implode(' ', str_split($done['reference']))) ?>"><?= e($done['reference']) ?></p>
        <p class="small text-muted">We have also sent it to <?= e($done['email']) ?> and by SMS. Keep it to check your status.</p>
        <ol class="wm-ps-steps text-start mx-auto mt-4" style="max-width:520px">
            <li class="is-done"><strong>Application sent</strong><span>Just now.</span></li>
            <li><strong>Verification</strong><span>Our team checks your details and may call or visit, usually within 3 working days.</span></li>
            <li><strong>Decision</strong><span>We email and SMS you as soon as a decision is made.</span></li>
        </ol>
        <div class="d-flex flex-wrap gap-2 justify-content-center mt-3">
            <a class="btn btn-wm" href="<?= e(url('/become-a-pickup-station/check')) ?>">Check application status</a>
            <a class="btn btn-outline-wm" href="<?= e(url('/')) ?>">Back to the shop</a>
        </div>
    </section>
</div>
