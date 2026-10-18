<?php
use Waimart\Core\View;

/** @var array<string,mixed> $settings */
ob_start(); ?>
<p>These terms apply to purchases made on the Waimart website. By placing an order you agree to them.</p>

<h2>1. Orders and stock</h2>
<p>An order is an offer to buy. When you place an order, the items are reserved for a limited time
    (currently <?= (int) $settings['reservation_minutes'] ?> minutes) while payment is completed and verified, or until a cash-on-delivery order is confirmed.
    If the reservation expires, the items may be sold to other customers and your order may be cancelled or require further confirmation.</p>

<h2>2. Prices</h2>
<p>Prices are in Kenya Shillings. The amount payable is the total shown at checkout, calculated by our system at the time of your order,
    including any delivery or pickup fee and applicable discounts.</p>

<h2>3. Payment</h2>
<p>We accept M-Pesa (Till, Paybill, Send Money) and, where available, cash on delivery. M-Pesa payments are verified manually.
    Submitting a transaction code does not by itself confirm payment.</p>

<h2>4. Delivery and collection</h2>
<p>Delivery ranges are estimates. Pickup orders must be collected by the customer or an authorised person presenting the collection code,
    within the holding period communicated to you.</p>

<h2>5. Cancellations and returns</h2>
<p>You may request cancellation before dispatch or before your order is ready for collection. Returns are handled under our
    <a href="<?= e(url('/returns-policy')) ?>">returns policy</a>.</p>

<h2>6. Contact</h2>
<p>For questions about these terms, please <a href="<?= e(url('/contact')) ?>">contact us</a>.</p>
<?php
$body = (string) ob_get_clean();
echo View::partial('pages/_shell', compact('title', 'slug', 'body'));
