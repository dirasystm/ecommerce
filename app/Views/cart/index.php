<?php
/** @var array<string,mixed> $cart */
?>
<div class="container-wm">
    <nav class="wm-breadcrumb" aria-label="Breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= e(url('/')) ?>">Home</a></li>
            <li class="breadcrumb-item active" aria-current="page">Cart</li>
        </ol>
    </nav>

    <?php if ($cart['is_empty']): ?>
        <div class="wm-card text-center py-5 px-3">
            <i class="bi bi-cart3 fs-1 text-muted" aria-hidden="true"></i>
            <h1 class="fs-5 fw-bold mt-2">Your cart is empty</h1>
            <p class="text-muted">Browse our categories and discover our best deals!</p>
            <a class="btn btn-wm" href="<?= e(url('/')) ?>">Start shopping</a>
        </div>
    <?php else: ?>
        <div class="row g-3">
            <section class="col-lg-8" aria-labelledby="cartTitle">
                <div class="wm-card">
                    <h1 id="cartTitle" class="fs-5 fw-bold px-3 pt-3 pb-2 mb-0 border-bottom">Cart (<?= (int) $cart['item_count'] ?>)</h1>
                    <?php foreach ($cart['lines'] as $l): ?>
                        <article class="wm-cart-line<?= $l['issue'] ? ' wm-cart-issue' : '' ?>">
                            <a href="<?= e(url('/product/' . $l['slug'])) ?>" class="wm-cart-img"><img src="<?= e(media($l['image'])) ?>" alt="" width="80" height="80" loading="lazy"></a>
                            <div class="wm-cart-info">
                                <h2 class="fs-6 mb-1"><a class="text-body" href="<?= e(url('/product/' . $l['slug'])) ?>"><?= e($l['name']) ?></a></h2>
                                <?php if ($l['variant_name']): ?><div class="small text-muted">Variation: <?= e($l['variant_name']) ?></div><?php endif; ?>
                                <?php if ($l['seller_name']): ?><div class="small text-muted">Sold by <a href="<?= e(url('/store/' . $l['seller_slug'])) ?>"><?= e($l['seller_name']) ?></a></div><?php endif; ?>
                                <?php if ($l['issue']): ?>
                                    <div class="small text-danger fw-semibold mt-1" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i> <?= e($l['issue']['message']) ?></div>
                                <?php elseif ($l['available'] <= 5): ?>
                                    <div class="small wm-stock-note mt-1">Only <?= (int) $l['available'] ?> left</div>
                                <?php endif; ?>
                                <div class="d-flex flex-wrap align-items-center gap-3 mt-2">
                                    <form method="post" action="<?= e(url('/cart/remove')) ?>">
                                        <?= csrf_field() ?><input type="hidden" name="variant_id" value="<?= $l['variant_id'] ?>">
                                        <button class="btn btn-link btn-sm p-0 text-danger" type="submit"><i class="bi bi-trash" aria-hidden="true"></i> Remove</button>
                                    </form>
                                    <?php if (!$l['issue'] || $l['issue']['code'] === 'limited'): ?>
                                        <form method="post" action="<?= e(url('/cart/update')) ?>" class="d-flex align-items-center gap-2">
                                            <?= csrf_field() ?><input type="hidden" name="variant_id" value="<?= $l['variant_id'] ?>">
                                            <label class="visually-hidden" for="q<?= $l['variant_id'] ?>">Quantity for <?= e($l['name']) ?></label>
                                            <input class="form-control form-control-sm wm-qty" type="number" id="q<?= $l['variant_id'] ?>" name="quantity" min="0" max="<?= (int) $l['max'] ?>" value="<?= (int) min($l['quantity'], max($l['max'], 1)) ?>" inputmode="numeric">
                                            <button class="btn btn-sm btn-outline-secondary" type="submit">Update</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="wm-cart-price text-end">
                                <div class="fw-bold"><?= e(money($l['price'])) ?></div>
                                <?php if ($l['was'] !== null): ?><div class="wm-price-old"><?= e(money($l['was'])) ?></div><?php endif; ?>
                                <?php if ($l['quantity'] > 1): ?><div class="small text-muted"><?= (int) $l['quantity'] ?> × = <?= e(money($l['line_total'])) ?></div><?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
                <?php if (!empty($recs)): ?><div class="mt-3"><?= \Waimart\Core\View::partial('partials/rec-rail', ['title' => 'You may also need', 'cards' => $recs, 'placement' => 'cart']) ?></div><?php endif; ?>
            </section>

            <aside class="col-lg-4">
                <div class="wm-card p-3 wm-sticky-summary">
                    <h2 class="fs-6 fw-bold text-uppercase border-bottom pb-2">Cart summary</h2>
                    <div class="d-flex justify-content-between my-2"><span>Subtotal</span><strong><?= e(money($cart['subtotal'])) ?></strong></div>
                    <p class="small text-muted">Delivery fees are calculated at checkout based on your location or pickup station.</p>
                    <?php if ($cart['has_issues']): ?>
                        <div class="alert alert-warning small py-2" role="alert">Please fix the highlighted items before checking out.</div>
                        <button class="btn btn-wm w-100" type="button" disabled>Checkout</button>
                    <?php else: ?>
                        <a class="btn btn-wm w-100" href="<?= e(url('/checkout')) ?>">Checkout (<?= e(money($cart['subtotal'])) ?>)</a>
                    <?php endif; ?>
                    <p class="small text-muted mt-3 mb-0"><i class="bi bi-info-circle" aria-hidden="true"></i> Items in your cart are not reserved. Stock is held for you once you place your order.</p>
                </div>
                <a class="d-block text-center mt-3 small" href="<?= e(url('/')) ?>">Continue shopping</a>
            </aside>
        </div>
    <?php endif; ?>
</div>
