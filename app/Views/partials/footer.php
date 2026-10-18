<?php
use Waimart\Core\Settings;
use Waimart\Core\Validator;

// Contact details and social links: Admin → Settings → Contact & business / Social media.
try {
    $storePhone = (string) Settings::get('store_phone', '');
    $storeEmail = (string) Settings::get('store_email', '');
    $whatsapp = (string) Settings::get('whatsapp_number', '');
    $hours = (string) Settings::get('business_hours', '');
    $legalName = trim((string) Settings::get('legal_name', ''));
    $social = [];
    foreach (['facebook' => ['Facebook', 'facebook'], 'instagram' => ['Instagram', 'instagram'], 'x' => ['X', 'twitter-x'],
              'tiktok' => ['TikTok', 'tiktok'], 'youtube' => ['YouTube', 'youtube'], 'linkedin' => ['LinkedIn', 'linkedin']] as $k => [$label, $icon]) {
        $u = (string) Settings::get('social_' . $k, '');
        if (str_starts_with(strtolower($u), 'https://')) {
            $social[] = [$u, $label, $icon];
        }
    }
} catch (\Throwable) {
    $storePhone = $storeEmail = $whatsapp = $hours = $legalName = '';
    $social = [];
}
$tagline = \Waimart\Services\Brand::tagline();
$menus = new \Waimart\Services\SiteContentService();
$footerNote = trim((string) Settings::get('footer_note', ''));
?>
<footer class="wm-footer">
    <div class="wm-footer-top">
        <div class="container-wm d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="wm-footer-brand">
                <img src="<?= e(\Waimart\Services\Brand::asset('brand_logo_header')) ?>" alt="<?= e(brand_name()) ?>" height="28">
                <?php if ($tagline !== ''): ?><span><?= e($tagline) ?></span><?php endif; ?>
            </div>
            <div class="d-flex flex-wrap gap-4 text-white small">
                <?php foreach ($menus->menu('footer_highlights') as $m): ?>
                    <?php if ($m['href'] !== ''): ?><a class="text-white" href="<?= e($m['href']) ?>"><?php else: ?><span><?php endif; ?><?php if ($m['icon']): ?><i class="bi bi-<?= e($m['icon']) ?> me-1" aria-hidden="true"></i><?php endif; ?><?= e($m['label']) ?><?= $m['href'] !== '' ? '</a>' : '</span>' ?>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="wm-footer-main">
        <div class="container-wm">
            <div class="row g-4">
                <?php // Admin → Content → Menus: the three footer link columns (menu title = column heading). ?>
                <?php foreach (['footer_col_1', 'footer_col_2', 'footer_col_3'] as $col): $links = $menus->menu($col); ?>
                    <div class="col-6 col-md-3">
                        <?php if ($links): ?>
                            <h3><?= e($menus->menuTitle($col)) ?></h3>
                            <ul>
                                <?php foreach ($links as $m): ?>
                                    <li><a href="<?= e($m['href']) ?>"<?= $m['new_tab'] ? ' target="_blank" rel="noopener"' : '' ?>><?= e($m['label']) ?></a></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <div class="col-6 col-md-3">
                    <h3>Payment methods</h3>
                    <div class="wm-pay-badges mb-3">
                        <span class="wm-pay-badge mpesa">M-PESA</span>
                        <span class="wm-pay-badge">Till</span>
                        <span class="wm-pay-badge">Paybill</span>
                        <span class="wm-pay-badge">Cash on Delivery</span>
                    </div>
                    <?php if ($storePhone !== '' || $storeEmail !== '' || $whatsapp !== ''): ?>
                        <h3>Contact</h3>
                        <ul>
                            <?php if ($storePhone !== ''): ?><li><i class="bi bi-telephone me-1" aria-hidden="true"></i><?= e($storePhone) ?></li><?php endif; ?>
                            <?php if ($storeEmail !== ''): ?><li><i class="bi bi-envelope me-1" aria-hidden="true"></i><a href="mailto:<?= e($storeEmail) ?>"><?= e($storeEmail) ?></a></li><?php endif; ?>
                            <?php if ($whatsapp !== ''): ?><li><i class="bi bi-whatsapp me-1" aria-hidden="true"></i><a href="https://wa.me/<?= e($whatsapp) ?>" rel="noopener" target="_blank">WhatsApp <?= e(Validator::displayPhone($whatsapp)) ?></a></li><?php endif; ?>
                            <?php if ($hours !== ''): ?><li class="small"><?= e($hours) ?></li><?php endif; ?>
                        </ul>
                    <?php endif; ?>
                    <?php if ($social): ?>
                        <h3>Follow us</h3>
                        <ul class="list-inline wm-social mb-0">
                            <?php foreach ($social as [$u, $label, $icon]): ?>
                                <li class="list-inline-item"><a href="<?= e($u) ?>" rel="noopener" target="_blank" aria-label="<?= e(brand_name() . ' on ' . $label) ?>"><i class="bi bi-<?= e($icon) ?> fs-5" aria-hidden="true"></i></a></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="wm-footer-bottom">
        <div class="container-wm d-flex flex-wrap justify-content-between gap-2">
            <span>&copy; <?= date('Y') ?> <?= e($legalName !== '' ? $legalName : brand_name()) ?>. All rights reserved.</span>
            <?php if ($footerNote !== ''): ?><span><?= e($footerNote) ?></span><?php endif; ?>
        </div>
    </div>
</footer>
