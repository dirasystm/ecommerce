<?php
use Waimart\Core\View;

// Admin → Content → Menus → "Mobile bottom bar" (up to 5 items). A /cart item shows the cart count.
$cartCount = (int) View::shared('cart_count', 0);
$items = (new \Waimart\Services\SiteContentService())->menu('mobile_nav');
?>
<?php if ($items): ?>
<nav class="wm-bottom-nav" aria-label="Mobile navigation">
    <?php foreach ($items as $m): $path = (string) $m['link']; $isCart = $path === '/cart'; ?>
        <a href="<?= e($m['href']) ?>" class="<?= is_current($path) ? 'active' : '' ?>" <?= is_current($path) ? 'aria-current="page"' : '' ?>>
            <i class="bi bi-<?= e($m['icon'] ?: 'circle') ?>" aria-hidden="true"></i><span><?= e($m['label'] . ($isCart && $cartCount ? " ($cartCount)" : '')) ?></span>
        </a>
    <?php endforeach; ?>
</nav>
<?php endif; ?>
