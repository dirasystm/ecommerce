<?php
/**
 * @var array<string,mixed> $p  Card data from CatalogueRepository::toCard()
 * @var bool $flash             Show flash-sale stock bar
 */
$flash ??= false;
$rec ??= null;          // recommendation placement: the link carries it so clicks and sales can be measured
$dismiss ??= false;     // show "Not interested" (personal recommendations only)
$out = $p['available'] <= 0;
$href = url('/product/' . $p['slug']) . ($rec !== null ? '?rec=' . rawurlencode($rec) : '');
?>
<article class="wm-product<?= $out ? ' wm-out-of-stock' : '' ?>">
    <div class="wm-product-img">
        <img src="<?= e(media($p['image'])) ?>" alt="" loading="lazy" width="300" height="300">
    </div>
    <?php if ($p['discount_percent'] > 0): ?>
        <span class="wm-badge-discount">-<?= (int) $p['discount_percent'] ?>%</span>
    <?php endif; ?>
    <h3 class="wm-product-name">
        <a class="wm-product-link" href="<?= e($href) ?>"><?= e($p['name']) ?></a>
    </h3>
    <?php if ($dismiss): ?>
        <form class="wm-rec-dismiss" method="post" action="<?= e(url('/recommendations/' . (int) $p['id'] . '/dismiss')) ?>" data-rec-dismiss>
            <?= csrf_field() ?><input type="hidden" name="placement" value="<?= e((string) $rec) ?>">
            <button type="submit" title="Not interested" aria-label="Not interested in <?= e($p['name']) ?>"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </form>
    <?php endif; ?>
    <?php if (!($p['seller_is_platform'] ?? true) && !empty($p['seller_name'])): ?>
        <div class="wm-product-seller">Sold by <?= e($p['seller_name']) ?></div>
    <?php endif; ?>
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
