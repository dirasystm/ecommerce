<?php
use Waimart\Core\View;

$cartCount = (int) View::shared('cart_count', 0);
$items = [
    ['/', 'house', 'Home'],
    ['/categories', 'grid', 'Categories'],
    ['/account', 'person', 'Account'],
    ['/help', 'question-circle', 'Help'],
    ['/cart', 'cart3', 'Cart' . ($cartCount ? " ($cartCount)" : '')],
];
?>
<nav class="wm-bottom-nav" aria-label="Mobile navigation">
    <?php foreach ($items as [$path, $icon, $label]): ?>
        <a href="<?= e(url($path)) ?>" class="<?= is_current($path) ? 'active' : '' ?>" <?= is_current($path) ? 'aria-current="page"' : '' ?>>
            <i class="bi bi-<?= e($icon) ?>" aria-hidden="true"></i><span><?= e($label) ?></span>
        </a>
    <?php endforeach; ?>
</nav>
