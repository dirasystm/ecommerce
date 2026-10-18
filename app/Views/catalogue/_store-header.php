<?php
use Waimart\Core\Validator;

/**
 * Public store profile shown above a store's products. Only public fields reach this template (StoreRepository::findPublic).
 * @var array<string,mixed> $store
 * @var int $total
 * @var ?string $searchQuery
 */
$since = $store['approved_at'] ?: $store['created_at'];
$place = trim(implode(', ', array_filter([(string) $store['town'], (string) $store['county_name']])));
?>
<section class="wm-card wm-store-head mb-3" aria-labelledby="wmStoreName">
    <div class="wm-store-logo">
        <?php if ($store['logo_path']): ?>
            <img src="<?= e(media($store['logo_path'])) ?>" alt="" width="96" height="96">
        <?php else: ?>
            <span aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) $store['name'], 0, 1))) ?></span>
        <?php endif; ?>
    </div>
    <div class="wm-store-info">
        <h1 id="wmStoreName" class="fs-4 fw-bold mb-1"><?= e($store['name']) ?></h1>
        <div class="d-flex flex-wrap align-items-center gap-2 small mb-2">
            <?php if ((int) $store['is_platform'] === 1): ?>
                <span class="badge badge-soft-success"><i class="bi bi-shield-check" aria-hidden="true"></i> Official <?= e(brand_name()) ?> store</span>
            <?php elseif ((int) $store['is_verified'] === 1): ?>
                <span class="badge badge-soft-success"><i class="bi bi-patch-check" aria-hidden="true"></i> Verified seller</span>
            <?php endif; ?>
            <?php if ($store['rating']['count'] > 0): ?>
                <span class="wm-rating"><?= stars((float) $store['rating']['avg']) ?> <?= e(number_format($store['rating']['avg'], 1)) ?> from <?= (int) $store['rating']['count'] ?> verified review<?= $store['rating']['count'] === 1 ? '' : 's' ?></span>
            <?php else: ?>
                <span class="text-muted">No reviews yet</span>
            <?php endif; ?>
        </div>
        <ul class="list-inline small text-muted mb-2">
            <?php if ($place !== ''): ?><li class="list-inline-item"><i class="bi bi-geo-alt" aria-hidden="true"></i> <?= e($place) ?></li><?php endif; ?>
            <?php if ($since): ?><li class="list-inline-item"><i class="bi bi-calendar-check" aria-hidden="true"></i> Selling on <?= e(brand_name()) ?> since <?= e(date('F Y', strtotime($since))) ?></li><?php endif; ?>
            <li class="list-inline-item"><i class="bi bi-box-seam" aria-hidden="true"></i> <?= number_format($total) ?> product<?= $total === 1 ? '' : 's' ?> shown</li>
        </ul>
        <?php if (trim((string) $store['description']) !== ''): ?>
            <p class="mb-2 wm-store-desc"><?= nl2br(e($store['description'])) ?></p>
        <?php endif; ?>
        <?php if ($store['public_email'] || $store['public_phone']): ?>
            <p class="small mb-2">Contact the seller:
                <?php if ($store['public_email']): ?><a href="mailto:<?= e($store['public_email']) ?>"><?= e($store['public_email']) ?></a><?php endif; ?>
                <?php if ($store['public_email'] && $store['public_phone']): ?> · <?php endif; ?>
                <?php if ($store['public_phone']): ?><a href="tel:+<?= e($store['public_phone']) ?>"><?= e(Validator::displayPhone((string) $store['public_phone'])) ?></a><?php endif; ?>
            </p>
        <?php endif; ?>
        <p class="small text-muted mb-0">Orders are paid to <?= e(brand_name()) ?> and delivered by <?= e(brand_name()) ?> or collected from a <?= e(brand_name()) ?> pickup station.</p>
    </div>
    <form class="wm-store-search" method="get" action="<?= e(url('/store/' . $store['slug'])) ?>" role="search">
        <label class="visually-hidden" for="wmStoreSearch">Search this store</label>
        <div class="input-group">
            <input class="form-control" type="search" id="wmStoreSearch" name="q" maxlength="100" placeholder="Search this store" value="<?= e((string) $searchQuery) ?>">
            <button class="btn btn-wm" type="submit"><i class="bi bi-search" aria-hidden="true"></i><span class="visually-hidden">Search</span></button>
        </div>
    </form>
</section>
