<?php
use Waimart\Core\View;

/** @var array<string,mixed> $settings */
ob_start(); ?>
<p>If something is not right with your order, you can request a return within
    <strong><?= (int) $settings['return_days'] ?> days</strong> of delivery or collection.</p>

<h2>How to request a return</h2>
<ol>
    <li>Sign in and open the order, or use your order tracking link.</li>
    <li>Select <strong>Request a return</strong>, choose the items and quantity, and tell us why.</li>
    <li>Our team reviews the request and lets you know the next steps.</li>
    <li>Returned items are inspected when we receive them.</li>
</ol>

<h2>Eligibility</h2>
<ul>
    <li>Items should be returned in their original condition and packaging, with all accessories.</li>
    <li>For hygiene reasons some items (for example opened personal-care products and undergarments) can only be returned if faulty or incorrect.</li>
    <li>Items damaged through misuse are not eligible.</li>
</ul>

<h2>Refunds</h2>
<ul>
    <li>Refunds are approved after inspection. We will tell you the outcome.</li>
    <li>Approved refunds for M-Pesa payments are sent to the paying M-Pesa number. Refunds for cash-on-delivery orders are arranged with you directly.</li>
    <li>If an order cannot be fulfilled after you have paid, we will contact you and record a refund for you.</li>
</ul>
<?php
$body = (string) ob_get_clean();
echo View::partial('pages/_shell', compact('title', 'slug', 'body'));
