<?php
use Waimart\Core\View;

/**
 * @var array<string,mixed> $product
 * @var array<string,mixed> $selected
 * @var list<array<string,mixed>> $related
 * @var list<array<string,mixed>> $recent
 */
$p = $product;
$images = $p['images'] ?: [['path' => null, 'alt_text' => $p['name']]];
$maxPerOrder = (int) $p['max_per_order'];
$multi = count($p['variants']) > 1;
$qtyMax = max(1, min($selected['available'], $maxPerOrder));
?>
<div class="container-wm">
    <nav class="wm-breadcrumb" aria-label="Breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= e(url('/')) ?>">Home</a></li>
            <?php foreach ($p['breadcrumbs'] as $c): ?>
                <li class="breadcrumb-item"><a href="<?= e(url('/category/' . $c['slug'])) ?>"><?= e($c['name']) ?></a></li>
            <?php endforeach; ?>
            <li class="breadcrumb-item active text-truncate" aria-current="page"><?= e($p['name']) ?></li>
        </ol>
    </nav>

    <div class="row g-3">
        <div class="col-lg-9">
            <section class="wm-card p-3" aria-label="Product">
                <div class="row g-4">
                    <!-- Gallery -->
                    <div class="col-md-5">
                        <div class="wm-gallery-main">
                            <img id="wmMainImage" src="<?= e(media($images[0]['path'])) ?>" alt="<?= e($images[0]['alt_text'] ?: $p['name']) ?>" width="600" height="600">
                        </div>
                        <?php if (count($images) > 1): ?>
                            <div class="wm-gallery-thumbs" role="list" aria-label="Product images">
                                <?php foreach ($images as $i => $img): ?>
                                    <button type="button" role="listitem" class="wm-thumb<?= $i === 0 ? ' active' : '' ?>"
                                            data-gallery-src="<?= e(media($img['path'])) ?>" data-gallery-alt="<?= e($img['alt_text'] ?: $p['name']) ?>"
                                            aria-label="Show image <?= $i + 1 ?>" <?= $i === 0 ? 'aria-current="true"' : '' ?>>
                                        <img src="<?= e(media($img['path'])) ?>" alt="" width="64" height="64" loading="lazy">
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Buy box -->
                    <div class="col-md-7">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <?php if (!empty($p['brand_slug'])): ?>
                                    <div class="small mb-1">Brand: <a href="<?= e(url('/brand/' . $p['brand_slug'])) ?>"><?= e($p['brand_name']) ?></a></div>
                                <?php endif; ?>
                                <h1 class="wm-product-title"><?= e($p['name']) ?></h1>
                                <div class="wm-soldby mb-1">Sold by <a href="<?= e(url('/store/' . $p['seller_slug'])) ?>"><?= e($p['seller_name']) ?></a>
                                    <?php if ((int) $p['seller_is_platform'] === 1): ?><span class="badge badge-soft-success ms-1"><i class="bi bi-shield-check" aria-hidden="true"></i> Official</span>
                                    <?php elseif ((int) $p['seller_is_verified'] === 1): ?><span class="badge badge-soft-success ms-1"><i class="bi bi-patch-check" aria-hidden="true"></i> Verified seller</span><?php endif; ?></div>
                            </div>
                            <?php if ($inWishlist !== null): ?>
                                <form method="post" action="<?= e(url('/wishlist/' . $p['id'] . '/toggle')) ?>" data-wishlist>
                                    <?= csrf_field() ?>
                                    <button class="btn btn-link wm-wish-btn<?= $inWishlist ? ' saved' : '' ?>" type="submit" aria-pressed="<?= $inWishlist ? 'true' : 'false' ?>">
                                        <i class="bi bi-heart<?= $inWishlist ? '-fill' : '' ?>" aria-hidden="true"></i><span class="visually-hidden">Save to wishlist</span>
                                    </button>
                                </form>
                            <?php else: ?>
                                <a class="btn btn-link wm-wish-btn" href="<?= e(url('/login')) ?>" title="Sign in to save items"><i class="bi bi-heart" aria-hidden="true"></i><span class="visually-hidden">Sign in to save to wishlist</span></a>
                            <?php endif; ?>
                        </div>
                        <div class="wm-rating mb-2">
                            <?php if ((int) $p['rating_count'] > 0): ?>
                                <?= stars((float) $p['rating_avg']) ?> <a href="#reviews">(<?= (int) $p['rating_count'] ?> verified ratings)</a>
                            <?php else: ?>
                                <span>No ratings yet</span>
                            <?php endif; ?>
                        </div>
                        <hr>

                        <div class="wm-buybox-price" aria-live="polite">
                            <span class="wm-price-lg" data-price><?= e(money($selected['price'])) ?></span>
                            <span class="wm-price-old" data-was <?= $selected['was'] === null ? 'hidden' : '' ?>><?= $selected['was'] !== null ? e(money($selected['was'])) : '' ?></span>
                            <span class="wm-badge-discount position-static" data-discount <?= $selected['discount_percent'] > 0 ? '' : 'hidden' ?>>-<?= (int) $selected['discount_percent'] ?>%</span>
                        </div>
                        <?php if ($selected['on_sale'] && $selected['sale_ends_at']): ?>
                            <div class="wm-countdown small mb-2 text-body" data-countdown="<?= e(date('c', strtotime($selected['sale_ends_at']))) ?>" role="timer" aria-label="Flash sale time left">
                                <i class="bi bi-lightning-charge-fill text-warning" aria-hidden="true"></i> Flash sale ends in
                                <span class="wm-cd-box" data-cd="h">--</span>h <span class="wm-cd-box" data-cd="m">--</span>m <span class="wm-cd-box" data-cd="s">--</span>s
                            </div>
                        <?php endif; ?>
                        <p class="small mb-3" data-stock aria-live="polite">
                            <?php if ($selected['available'] <= 0): ?>
                                <span class="text-danger fw-semibold">Out of stock</span>
                            <?php elseif ($selected['low_stock']): ?>
                                <span class="wm-stock-note">Only <?= (int) $selected['available'] ?> left — order soon</span>
                            <?php else: ?>
                                <span class="text-success fw-semibold"><i class="bi bi-check-circle" aria-hidden="true"></i> In stock</span>
                            <?php endif; ?>
                        </p>

                        <form action="<?= e(url('/cart/add')) ?>" method="post" class="wm-buy-form" data-product-form data-max-per-order="<?= $maxPerOrder ?>">
                            <?= csrf_field() ?>
                            <?php if ($multi): ?>
                                <fieldset class="mb-3">
                                    <legend class="fs-6 fw-semibold mb-2">Variation available</legend>
                                    <div class="wm-variants">
                                        <?php foreach ($p['variants'] as $v): ?>
                                            <input class="btn-check" type="radio" name="variant_id" id="v<?= $v['id'] ?>" value="<?= $v['id'] ?>"
                                                   <?= $v['id'] === $selected['id'] ? 'checked' : '' ?>
                                                   data-price="<?= e(money($v['price'])) ?>"
                                                   data-was="<?= $v['was'] !== null ? e(money($v['was'])) : '' ?>"
                                                   data-discount="<?= (int) $v['discount_percent'] ?>"
                                                   data-available="<?= (int) $v['available'] ?>"
                                                   data-low="<?= $v['low_stock'] ? '1' : '0' ?>"
                                                   data-sku="<?= e($v['sku']) ?>">
                                            <label class="btn wm-variant-chip<?= $v['available'] <= 0 ? ' wm-variant-out' : '' ?>" for="v<?= $v['id'] ?>">
                                                <?= e($v['name']) ?><?= $v['available'] <= 0 ? ' <span class="visually-hidden">(out of stock)</span>' : '' ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </fieldset>
                            <?php else: ?>
                                <input type="hidden" name="variant_id" value="<?= $selected['id'] ?>">
                            <?php endif; ?>

                            <div class="d-flex flex-wrap gap-2 align-items-end wm-buy-actions">
                                <div>
                                    <label class="form-label small mb-1" for="wmQty">Quantity</label>
                                    <input class="form-control wm-qty" type="number" id="wmQty" name="quantity" value="1" min="1"
                                           max="<?= $qtyMax ?>" inputmode="numeric" required data-qty>
                                </div>
                                <button class="btn btn-wm btn-lg flex-grow-1 wm-add-btn" type="submit" data-add-btn <?= $selected['available'] <= 0 ? 'disabled' : '' ?>>
                                    <i class="bi bi-cart-plus" aria-hidden="true"></i>
                                    <span data-add-label><?= $selected['available'] <= 0 ? 'Out of stock' : 'Add to cart' ?></span>
                                </button>
                            </div>
                            <p class="small text-muted mt-2 mb-0">Limit <?= $maxPerOrder ?> per order. Items are reserved for you only once your order is placed.</p>
                            <p class="small text-muted mb-0">SKU: <span data-sku><?= e($selected['sku']) ?></span></p>
                        </form>
                    </div>
                </div>
            </section>

            <!-- Details -->
            <section class="wm-card mt-3" aria-label="Product details">
                <ul class="nav nav-tabs px-3 pt-2" role="tablist">
                    <li class="nav-item" role="presentation"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabDesc" type="button" role="tab" aria-controls="tabDesc" aria-selected="true">Description</button></li>
                    <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabSpecs" type="button" role="tab" aria-controls="tabSpecs" aria-selected="false">Specifications</button></li>
                    <li class="nav-item" role="presentation"><button class="nav-link" id="reviews" data-bs-toggle="tab" data-bs-target="#tabReviews" type="button" role="tab" aria-controls="tabReviews" aria-selected="false">Reviews</button></li>
                </ul>
                <div class="tab-content p-3 p-md-4">
                    <div class="tab-pane fade show active" id="tabDesc" role="tabpanel" tabindex="0">
                        <?php if ($p['short_description']): ?><p class="fw-semibold"><?= e($p['short_description']) ?></p><?php endif; ?>
                        <div class="wm-description"><?= $p['description'] ? nl2br(e($p['description'])) : '<p class="text-muted">No description yet.</p>' ?></div>
                    </div>
                    <div class="tab-pane fade" id="tabSpecs" role="tabpanel" tabindex="0">
                        <?php if ($p['specs']): ?>
                            <table class="table table-sm wm-specs mb-0">
                                <tbody>
                                <?php foreach ($p['specs'] as $k => $v): ?>
                                    <tr><th scope="row"><?= e($k) ?></th><td><?= e($v) ?></td></tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <p class="text-muted mb-0">No specifications listed.</p>
                        <?php endif; ?>
                    </div>
                    <div class="tab-pane fade" id="tabReviews" role="tabpanel" tabindex="0">
                        <?php if ($reviews): ?>
                            <div class="d-flex align-items-center gap-3 mb-3">
                                <span class="fs-2 fw-bold"><?= e(number_format((float) $p['rating_avg'], 1)) ?></span>
                                <span><?= stars((float) $p['rating_avg']) ?><span class="d-block small text-muted"><?= (int) $p['rating_count'] ?> verified rating<?= (int) $p['rating_count'] === 1 ? '' : 's' ?></span></span>
                            </div>
                            <?php foreach ($reviews as $rv): ?>
                                <article class="border-top py-3">
                                    <div><?= stars((float) $rv['rating']) ?> <strong class="ms-1"><?= e($rv['title'] ?? '') ?></strong></div>
                                    <?php if ($rv['body']): ?><p class="mb-1 mt-1"><?= nl2br(e($rv['body'])) ?></p><?php endif; ?>
                                    <div class="small text-muted"><?= e(date('j M Y', strtotime($rv['created_at']))) ?> by <?= e($rv['first_name'] . ' ' . $rv['last_initial'] . '.') ?>
                                        <?= $rv['variant_name'] !== 'Default' ? ' · ' . e($rv['variant_name']) : '' ?>
                                        · <span class="text-success"><i class="bi bi-patch-check-fill" aria-hidden="true"></i> Verified purchase</span></div>
                                </article>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-muted mb-0">No reviews yet. Only customers who bought this product can leave a review.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
        </div>

        <!-- Delivery & returns -->
        <aside class="col-lg-3">
            <div class="wm-card p-3 mb-3">
                <h2 class="fs-6 fw-bold text-uppercase mb-2">Seller</h2>
                <p class="mb-1 fw-semibold"><?= e($p['seller_name']) ?></p>
                <?php if (!empty($sellerRating) && $sellerRating['count'] > 0): ?>
                    <div class="wm-rating small mb-1"><?= stars((float) $sellerRating['avg']) ?> <?= e(number_format($sellerRating['avg'], 1)) ?> (<?= (int) $sellerRating['count'] ?> reviews)</div>
                <?php endif; ?>
                <a class="small" href="<?= e(url('/store/' . $p['seller_slug'])) ?>">Visit store <i class="bi bi-chevron-right" aria-hidden="true"></i></a>
                <p class="small text-muted mt-2 mb-0">Paid to Waimart and delivered or collected through Waimart.</p>
            </div>
            <div class="wm-card p-3 wm-delivery-box">
                <h2 class="fs-6 fw-bold text-uppercase mb-3">Delivery &amp; returns</h2>
                <div class="d-flex gap-2 mb-3">
                    <i class="bi bi-truck fs-4 text-success" aria-hidden="true"></i>
                    <div><strong class="d-block small">Home or office delivery</strong><span class="small text-muted">Fee and estimated delivery range for your area are shown at checkout.</span></div>
                </div>
                <div class="d-flex gap-2 mb-3">
                    <i class="bi bi-shop fs-4 text-success" aria-hidden="true"></i>
                    <div><strong class="d-block small">Pickup station</strong><span class="small text-muted">Collect with your private code once we tell you it is ready.</span></div>
                </div>
                <div class="d-flex gap-2 mb-3">
                    <i class="bi bi-phone fs-4 text-success" aria-hidden="true"></i>
                    <div><strong class="d-block small">M-Pesa or cash on delivery</strong><a class="small" href="<?= e(url('/how-to-pay')) ?>">How to pay</a></div>
                </div>
                <div class="d-flex gap-2">
                    <i class="bi bi-arrow-repeat fs-4 text-success" aria-hidden="true"></i>
                    <div><strong class="d-block small">Returns</strong><a class="small" href="<?= e(url('/returns-policy')) ?>">See returns policy</a></div>
                </div>
            </div>
        </aside>
    </div>

    <?php foreach ([['You may also like', $related], ['Recently viewed', $recent]] as [$label, $cards]): ?>
        <?php if ($cards): ?>
            <section class="wm-card wm-section" aria-label="<?= e($label) ?>">
                <div class="wm-section-head"><h2><?= e($label) ?></h2></div>
                <div class="wm-rail" tabindex="0" aria-label="<?= e($label) ?>, scroll horizontally">
                    <?php foreach ($cards as $c): ?><?= View::partial('partials/product-card', ['p' => $c]) ?><?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
