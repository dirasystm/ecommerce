<?php
use Waimart\Core\View;

ob_start(); ?>
<p class="lead"><?= e(brand_name()) ?> — <strong>Everything You Need. One Marketplace.</strong></p>
<p><?= e(brand_name()) ?> is an online store bringing phones, electronics, home appliances, fashion, beauty, groceries and more together in one place,
    with simple payment by M-Pesa or cash on delivery.</p>
<h2>What we promise</h2>
<ul>
    <li><strong>Honest stock:</strong> when you place an order, the items are reserved for you so they cannot be sold to someone else while you pay.</li>
    <li><strong>Clear payments:</strong> we confirm your order only after your payment is verified, and we tell you at every step.</li>
    <li><strong>Flexible delivery:</strong> delivery to your door or collection from a pickup station.</li>
    <li><strong>Support when you need it:</strong> easy cancellations before dispatch and a clear returns process.</li>
</ul>
<?php
$body = (string) ob_get_clean();
echo View::partial('pages/_shell', compact('title', 'slug', 'body'));
