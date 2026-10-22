<?php
use Waimart\Core\Settings;
use Waimart\Services\Marketing\Tracker;

/** Guests in "ask first" mode see this until they choose; it never blocks the page. */
if (!Tracker::needsGuestChoice() || Settings::get('tracking_consent_mode', 'opt_in') !== 'opt_in') {
    return;
}
?>
<div class="wm-privacy-banner" role="region" aria-label="Personalised suggestions" data-privacy-banner>
    <p class="mb-2 mb-md-0 small">May we remember what you browse here to suggest products you may like? We use a random code, not your name or contact details.
        <a href="<?= e(url('/privacy-choices')) ?>">Learn more</a></p>
    <form method="post" action="<?= e(url('/privacy-choices')) ?>" class="d-flex gap-2 flex-shrink-0" data-privacy-form>
        <?= csrf_field() ?>
        <button class="btn btn-sm btn-wm" type="submit" name="personalisation" value="1">Allow</button>
        <button class="btn btn-sm btn-outline-secondary bg-white" type="submit" name="personalisation" value="0">No thanks</button>
    </form>
</div>
