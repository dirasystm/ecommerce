<?php
use Waimart\Core\View;

/** @var array<string,mixed> $settings */
ob_start(); ?>
<p>Choose how you receive your order at checkout. Only options available for your order and location are shown.</p>

<h2><i class="bi bi-truck me-1" aria-hidden="true"></i> Home or office delivery</h2>
<ul>
    <li>Delivery is available to supported locations. The delivery fee and an estimated delivery range for your area are calculated at checkout.</li>
    <li>Some areas qualify for free delivery when your order reaches a minimum value. The threshold, if any, is shown at checkout.</li>
    <li>If your location is not yet supported, checkout will tell you and you can choose a pickup station instead.</li>
    <li>Delivery ranges are estimates, not guaranteed dates. We will keep you updated as your order is dispatched and out for delivery.</li>
</ul>

<h2><i class="bi bi-shop me-1" aria-hidden="true"></i> Pickup stations</h2>
<ol>
    <li>Choose a pickup station at checkout (<a href="<?= e(url('/pickup-stations')) ?>">see all stations</a>). You will see its address, opening hours and collection instructions.</li>
    <li>We notify you when your order is <strong>ready for collection</strong> and give you a private collection code.
        Please wait for this notice before going to the station.</li>
    <li>Bring your collection code, your order number and a valid ID. Staff will check them before handing over your order.</li>
    <li>Orders are held for <?= (int) $settings['hold_days'] ?> days after they are ready for collection.</li>
</ol>
<div class="wm-callout">Keep your collection code private. Anyone who has it, together with your order details, may be able to collect your order.</div>
<?php
$body = (string) ob_get_clean();
echo View::partial('pages/_shell', compact('title', 'slug', 'body'));
