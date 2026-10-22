<?php
use Waimart\Services\Marketing\Consent;

/** Newsletter sign-up above the footer: email consent required, SMS separate and optional (never pre-ticked). */
$u = \Waimart\Core\Auth::user();
if ($u !== null && ((int) ($u['marketing_email'] ?? 0) === 1 || !empty($u['is_staff']))) {
    return;     // already subscribed (or staff)
}
?>
<section class="wm-newsletter" aria-labelledby="nlTitle">
    <div class="container-wm">
        <div class="row g-3 align-items-center">
            <div class="col-lg-5">
                <h2 id="nlTitle" class="h5 mb-1"><i class="bi bi-envelope-paper" aria-hidden="true"></i> Get offers and new arrivals first</h2>
                <p class="small mb-0">Hand-picked deals from <?= e(brand_name()) ?>. No spam, unsubscribe in one click.</p>
            </div>
            <div class="col-lg-7">
                <form method="post" action="<?= e(url('/newsletter')) ?>" class="row g-2" novalidate>
                    <?= csrf_field() ?>
                    <div class="wm-hp" aria-hidden="true"><label for="nl_website">Leave this empty</label><input type="text" id="nl_website" name="website" tabindex="-1" autocomplete="off"></div>
                    <div class="col-sm-7">
                        <label class="visually-hidden" for="nl_email">Email address</label>
                        <input class="form-control" type="email" id="nl_email" name="email" required autocomplete="email" placeholder="Your email address" value="<?= e($u['email'] ?? '') ?>">
                    </div>
                    <div class="col-sm-5"><button class="btn btn-wm w-100" type="submit">Sign up</button></div>
                    <div class="col-12">
                        <div class="form-check small">
                            <input class="form-check-input" type="checkbox" id="nl_consent" name="consent_email" value="1" required>
                            <label class="form-check-label" for="nl_consent"><?= e(Consent::WORDING['email']) ?> <a href="<?= e(url('/privacy')) ?>">Privacy policy</a></label>
                        </div>
                        <details class="small mt-1"><summary>Also get SMS offers (optional)</summary>
                            <div class="row g-2 mt-1">
                                <div class="col-sm-6"><label class="visually-hidden" for="nl_phone">Phone number</label>
                                    <input class="form-control form-control-sm" type="tel" id="nl_phone" name="phone" inputmode="tel" autocomplete="tel" placeholder="0712 345 678"></div>
                                <div class="col-sm-6"><div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="nl_sms" name="consent_sms" value="1">
                                    <label class="form-check-label" for="nl_sms"><?= e(Consent::WORDING['sms']) ?></label></div></div>
                            </div>
                        </details>
                    </div>
                </form>
            </div>
        </div>
    </div>
</section>
