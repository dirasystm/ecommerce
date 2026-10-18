<?php
/**
 * @var array<string,mixed> $p  Card data from CatalogueRepository::toCard()
 * @var bool $flash             Show flash-sale stock bar
 */
$flash ??= false;
$out = $p['available'] <= 0;
?>
<article class="wm-product<?= $out ? ' wm-out-of-stock' : '' ?>">
    <div class="wm-product-img">
        <img src="<?= e(media($p['image'])) ?>" alt="" loading="lazy" width="300" height="300">
    </div>
    <?php if ($p['discount_percent'] > 0): ?>
        <span class="wm-badge-discount">-<?= (int) $p['discount_percent'] ?>%</span>
    <?php endif; ?>
    <h3 class="wm-product-name">
        <a class="wm-product-link" href="<?= e(url('/product/' . $p['slug'])) ?>"><?= e($p['name']) ?></a>
    </h3>
    <div class="wm-price"><?= e(money($p['price'])) ?><?= $p['has_options'] ? '<span class="visually-hidden"> (from)</span>' : '' ?></div>
    <?php if ($p['was'] !== null): ?>
        <div class="wm-price-old"><span class="visually-hidden">Was </span><?= e(money($p['was'])) ?></div>
    <?php endif; ?>

    <?php if ($flash): ?>
        <div class="small mt-1 <?= $out || $p['low_stock'] ? 'wm-stock-note' : '' ?>"><?= $out ? 'Sold out' : (int) $p['available'] . ' items left' ?></div>
    <?php elseif ($out): ?>
        <div class="wm-stock-note">Out of stock</div>
    <?php elseif ($p['low_stock']): ?>
        <div class="wm-stock-note">Only <?= (int) $p['available'] ?> left</div>
    <?php endif; ?>

    <?php if ($p['rating_count'] > 0): ?>
        <div class="wm-rating mt-1"><?= stars($p['rating']) ?> <span>(<?= (int) $p['rating_count'] ?>)</span></div>
    <?php endif; ?>
</article>
