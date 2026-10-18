<?php
use Waimart\Core\View;

/** @var array<string,mixed> $settings */
ob_start(); ?>
<p>Our customer care team is happy to help with orders, payments, delivery, pickup and returns.</p>
<div class="row g-3 mt-1">
    <div class="col-md-6">
        <div class="border rounded p-3 h-100">
            <h2 class="fs-6 mt-0"><i class="bi bi-telephone me-1" aria-hidden="true"></i> Phone</h2>
            <p class="mb-0"><?= $settings['store_phone'] !== '' ? e($settings['store_phone']) : 'Our phone line will be published here soon.' ?></p>
        </div>
    </div>
    <div class="col-md-6">
        <div class="border rounded p-3 h-100">
            <h2 class="fs-6 mt-0"><i class="bi bi-envelope me-1" aria-hidden="true"></i> Email</h2>
            <p class="mb-0"><?= $settings['store_email'] !== '' ? e($settings['store_email']) : 'Our support email will be published here soon.' ?></p>
        </div>
    </div>
</div>
<p class="mt-3">When contacting us about an order, please have your <strong>order number</strong> ready.
    For your security, never share your M-Pesa PIN or your pickup collection code with anyone claiming to be from Waimart.</p>
<?php
$body = (string) ob_get_clean();
echo View::partial('pages/_shell', compact('title', 'slug', 'body'));
