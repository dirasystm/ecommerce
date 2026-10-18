<?php
use Waimart\Core\View;

/** @var array<string,mixed> $settings */
$na = '<em>shown at checkout</em>';
ob_start(); ?>
<p><?= e(brand_name()) ?> accepts <strong>M-Pesa</strong> and <strong>cash on delivery</strong>. We do not accept card payments online.</p>

<div class="wm-callout my-3">
    <strong>Important:</strong> submitting an M-Pesa code does not complete your payment on its own. Our team checks each payment
    against M-Pesa records. Your order is confirmed only after the payment is <strong>verified</strong>, and we will notify you.
    Items are held for <?= (int) $settings['reservation_minutes'] ?> minutes after you place your order, so please pay promptly.
</div>

<h2><i class="bi bi-shop me-1" aria-hidden="true"></i> Lipa na M-Pesa — Buy Goods (Till)</h2>
<div class="wm-pay-step"><span class="wm-step-num">1</span><div>Open M-Pesa and select <strong>Lipa na M-Pesa → Buy Goods and Services</strong>.</div></div>
<div class="wm-pay-step"><span class="wm-step-num">2</span><div>Enter Till Number <strong><?= $settings['till'] !== '' ? e($settings['till']) : $na ?></strong><?= $settings['till_name'] !== '' ? ' (' . e($settings['till_name']) . ')' : '' ?>.</div></div>
<div class="wm-pay-step"><span class="wm-step-num">3</span><div>Enter the <strong>exact order total</strong>, then your M-Pesa PIN.</div></div>
<div class="wm-pay-step"><span class="wm-step-num">4</span><div>Enter the 10-character transaction code from the M-Pesa SMS (e.g. <code>SGH7K2L9XQ</code>) on your order page.</div></div>

<h2><i class="bi bi-building me-1" aria-hidden="true"></i> Lipa na M-Pesa — Paybill</h2>
<div class="wm-pay-step"><span class="wm-step-num">1</span><div>Select <strong>Lipa na M-Pesa → Pay Bill</strong>.</div></div>
<div class="wm-pay-step"><span class="wm-step-num">2</span><div>Business number <strong><?= $settings['paybill'] !== '' ? e($settings['paybill']) : $na ?></strong>.</div></div>
<div class="wm-pay-step"><span class="wm-step-num">3</span><div>Account number: <strong><?= e($settings['paybill_hint'] ?: 'your order number') ?></strong>.</div></div>
<div class="wm-pay-step"><span class="wm-step-num">4</span><div>Pay the exact total, then enter the transaction code on your order page.</div></div>

<h2><i class="bi bi-send me-1" aria-hidden="true"></i> M-Pesa Send Money</h2>
<div class="wm-pay-step"><span class="wm-step-num">1</span><div>Select <strong>Send Money</strong> and send to <strong><?= $settings['phone'] !== '' ? e($settings['phone']) : $na ?></strong><?= $settings['phone_name'] !== '' ? ' (' . e($settings['phone_name']) . ')' : '' ?>.</div></div>
<div class="wm-pay-step"><span class="wm-step-num">2</span><div>Check the recipient name before confirming, then enter the transaction code on your order page.</div></div>

<h2><i class="bi bi-cash-coin me-1" aria-hidden="true"></i> Cash on delivery</h2>
<p>Where available for your location, pay in cash when you receive your order. Cash on delivery orders are confirmed by our team
    before dispatch. Please have the exact amount ready.</p>

<h2>Stay safe</h2>
<ul>
    <li><?= e(brand_name()) ?> staff will <strong>never</strong> ask for your M-Pesa PIN.</li>
    <li>Only pay to the Till, Paybill or phone number shown on this page or at checkout.</li>
    <li>Payment instructions are never sent from personal numbers.</li>
</ul>
<?php
$body = (string) ob_get_clean();
echo View::partial('pages/_shell', compact('title', 'slug', 'body'));
