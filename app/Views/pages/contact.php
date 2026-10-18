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
    <?php if ($settings['whatsapp'] !== ''): ?>
        <div class="col-md-6">
            <div class="border rounded p-3 h-100">
                <h2 class="fs-6 mt-0"><i class="bi bi-whatsapp me-1" aria-hidden="true"></i> WhatsApp</h2>
                <p class="mb-0"><a href="https://wa.me/<?= e($settings['whatsapp']) ?>" rel="noopener" target="_blank"><?= e(\Waimart\Core\Validator::displayPhone((string) $settings['whatsapp'])) ?></a></p>
            </div>
        </div>
    <?php endif; ?>
    <?php if ($settings['address'] !== '' || $settings['postal'] !== ''): ?>
        <div class="col-md-6">
            <div class="border rounded p-3 h-100">
                <h2 class="fs-6 mt-0"><i class="bi bi-geo-alt me-1" aria-hidden="true"></i> Address</h2>
                <?php if ($settings['address'] !== ''): ?><p class="mb-1"><?= nl2br(e((string) $settings['address'])) ?></p><?php endif; ?>
                <?php if ($settings['postal'] !== ''): ?><p class="mb-0 small text-muted"><?= e((string) $settings['postal']) ?></p><?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php if ($settings['hours'] !== ''): ?><p class="mt-3 mb-0"><strong>Customer care hours:</strong> <?= e((string) $settings['hours']) ?></p><?php endif; ?>
<p class="mt-3">When contacting us about an order, please have your <strong>order number</strong> ready.
    For your security, never share your M-Pesa PIN or your pickup collection code with anyone claiming to be from <?= e(brand_name()) ?>.</p>
<?php
$body = (string) ob_get_clean();
echo View::partial('pages/_shell', compact('title', 'slug', 'body'));
