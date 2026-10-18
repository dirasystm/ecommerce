<?php
use Waimart\Core\Settings;

try {
    $storePhone = (string) Settings::get('store_phone', '');
    $storeEmail = (string) Settings::get('store_email', '');
} catch (\Throwable) {
    $storePhone = $storeEmail = '';
}
?>
<footer class="wm-footer">
    <div class="wm-footer-top">
        <div class="container-wm d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="wm-footer-brand">
                <img src="<?= e(asset('img/logo-wordmark.png')) ?>" alt="Waimart" width="142" height="28">
                <span>Everything You Need. One Marketplace.</span>
            </div>
            <div class="d-flex flex-wrap gap-4 text-white small">
                <span><i class="bi bi-truck me-1" aria-hidden="true"></i>Home delivery</span>
                <span><i class="bi bi-shop me-1" aria-hidden="true"></i>Pickup stations</span>
                <span><i class="bi bi-phone me-1" aria-hidden="true"></i>M-Pesa &amp; COD</span>
            </div>
        </div>
    </div>

    <div class="wm-footer-main">
        <div class="container-wm">
            <div class="row g-4">
                <div class="col-6 col-md-3">
                    <h3>Need help?</h3>
                    <ul>
                        <li><a href="<?= e(url('/help')) ?>">Help Center</a></li>
                        <li><a href="<?= e(url('/contact')) ?>">Contact us</a></li>
                        <li><a href="<?= e(url('/track-order')) ?>">Track your order</a></li>
                        <li><a href="<?= e(url('/returns-policy')) ?>">Returns &amp; refunds</a></li>
                    </ul>
                </div>
                <div class="col-6 col-md-3">
                    <h3>Shopping</h3>
                    <ul>
                        <li><a href="<?= e(url('/how-to-pay')) ?>">How to pay</a></li>
                        <li><a href="<?= e(url('/delivery-pickup')) ?>">Delivery &amp; pickup</a></li>
                        <li><a href="<?= e(url('/account')) ?>">My account</a></li>
                        <li><a href="<?= e(url('/cart')) ?>">Cart</a></li>
                    </ul>
                </div>
                <div class="col-6 col-md-3">
                    <h3>About Waimart</h3>
                    <ul>
                        <li><a href="<?= e(url('/about')) ?>">About us</a></li>
                        <li><a href="<?= e(url('/terms')) ?>">Terms &amp; conditions</a></li>
                        <li><a href="<?= e(url('/privacy')) ?>">Privacy policy</a></li>
                    </ul>
                </div>
                <div class="col-6 col-md-3">
                    <h3>Payment methods</h3>
                    <div class="wm-pay-badges mb-3">
                        <span class="wm-pay-badge mpesa">M-PESA</span>
                        <span class="wm-pay-badge">Till</span>
                        <span class="wm-pay-badge">Paybill</span>
                        <span class="wm-pay-badge">Cash on Delivery</span>
                    </div>
                    <?php if ($storePhone !== '' || $storeEmail !== ''): ?>
                        <h3>Contact</h3>
                        <ul>
                            <?php if ($storePhone !== ''): ?><li><i class="bi bi-telephone me-1" aria-hidden="true"></i><?= e($storePhone) ?></li><?php endif; ?>
                            <?php if ($storeEmail !== ''): ?><li><i class="bi bi-envelope me-1" aria-hidden="true"></i><?= e($storeEmail) ?></li><?php endif; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="wm-footer-bottom">
        <div class="container-wm d-flex flex-wrap justify-content-between gap-2">
            <span>&copy; <?= date('Y') ?> Waimart. All rights reserved.</span>
            <span>Prices in Kenya Shillings (KSh), VAT inclusive where applicable.</span>
        </div>
    </div>
</footer>
