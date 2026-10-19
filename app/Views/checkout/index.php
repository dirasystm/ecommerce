<?php
use Waimart\Core\Validator;

/**
 * @var array<string,mixed> $cart
 * @var array<string,mixed>|null $user
 * @var list<array<string,mixed>> $addresses
 * @var list<array{id:int,name:string}> $counties
 * @var list<array<string,mixed>> $pickupPoints
 * @var bool $deliveryAvailable
 * @var bool $pickupAvailable
 * @var array<string,array<string,mixed>> $payments
 * @var string $deliveryNote @var string $pickupNote
 * @var string $checkoutToken
 */
$method = (string) fv('fulfilment_method', $deliveryAvailable ? 'delivery' : 'pickup');
$addrChoice = (string) fv('address_id', $addresses ? (string) $addresses[0]['id'] : 'new');
$payChoice = (string) fv('payment_method', '');
$byCounty = [];
foreach ($pickupPoints as $pp) {
    $byCounty[$pp['county_name']][] = $pp;
}
?>
<div class="container-wm">
    <nav class="wm-breadcrumb" aria-label="Breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= e(url('/cart')) ?>">Cart</a></li>
            <li class="breadcrumb-item active" aria-current="page">Checkout</li>
        </ol>
    </nav>

    <form method="post" action="<?= e(url('/checkout')) ?>" class="row g-3" data-checkout data-quote-url="<?= e(url('/checkout/quote')) ?>" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="checkout_token" value="<?= e($checkoutToken) ?>">

        <div class="col-lg-8">
            <h1 class="visually-hidden">Checkout</h1>

            <!-- 1. Customer -->
            <section class="wm-card wm-checkout-step" aria-labelledby="stepCustomer">
                <h2 id="stepCustomer"><span class="wm-step-num">1</span> Customer details</h2>
                <?php if (!$user): ?>
                    <p class="small text-muted">Checking out as a guest. <a href="<?= e(url('/login')) ?>">Sign in</a> for faster checkout and order history.</p>
                <?php endif; ?>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="customer_name">Full name</label>
                        <input class="form-control<?= invalid('customer_name') ?>" id="customer_name" name="customer_name" autocomplete="name" required maxlength="160"
                               value="<?= e(fv('customer_name', $user ? $user['first_name'] . ' ' . $user['last_name'] : '')) ?>">
                        <?= field_error('customer_name') ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="customer_phone">Phone number</label>
                        <input class="form-control<?= invalid('customer_phone') ?>" id="customer_phone" name="customer_phone" type="tel" inputmode="tel" autocomplete="tel" required placeholder="0712 345 678"
                               value="<?= e(fv('customer_phone', $user && $user['phone'] ? Validator::displayPhone($user['phone']) : '')) ?>">
                        <?= field_error('customer_phone') ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="customer_email">Email <?= $user ? '' : '<span class="text-muted fw-normal">(optional — for order emails)</span>' ?></label>
                        <input class="form-control<?= invalid('customer_email') ?>" id="customer_email" name="customer_email" type="email" autocomplete="email"
                               value="<?= e(fv('customer_email', $user['email'] ?? '')) ?>">
                        <?= field_error('customer_email') ?>
                    </div>
                </div>
            </section>

            <!-- 2. Fulfilment -->
            <section class="wm-card wm-checkout-step" aria-labelledby="stepFulfil">
                <h2 id="stepFulfil"><span class="wm-step-num">2</span> Delivery method</h2>
                <?= field_error('fulfilment_method') ?>
                <?php if (!$deliveryAvailable && !$pickupAvailable): ?>
                    <div class="alert alert-warning">Delivery and pickup are not available at the moment. Please try again later.</div>
                <?php endif; ?>
                <div class="wm-choice-row mb-3">
                    <?php if ($deliveryAvailable): ?>
                        <input class="btn-check" type="radio" name="fulfilment_method" id="fmDelivery" value="delivery" <?= $method === 'delivery' ? 'checked' : '' ?> data-fulfil>
                        <label class="wm-choice" for="fmDelivery"><i class="bi bi-truck" aria-hidden="true"></i><span><strong>Home or office delivery</strong><small>Delivered to your address</small></span></label>
                    <?php endif; ?>
                    <?php if ($pickupAvailable): ?>
                        <input class="btn-check" type="radio" name="fulfilment_method" id="fmPickup" value="pickup" <?= $method === 'pickup' ? 'checked' : '' ?> data-fulfil>
                        <label class="wm-choice" for="fmPickup"><i class="bi bi-shop" aria-hidden="true"></i><span><strong>Pickup station</strong><small>Collect with your private code</small></span></label>
                    <?php endif; ?>
                </div>

                <?php if ($deliveryAvailable): ?>
                <div data-panel="delivery" <?= $method === 'delivery' ? '' : 'hidden' ?>>
                    <?php if ($deliveryNote !== ''): ?><p class="small wm-callout mb-3"><i class="bi bi-info-circle me-1" aria-hidden="true"></i><?= e($deliveryNote) ?></p><?php endif; ?>
                    <?php if ($addresses): ?>
                        <fieldset class="mb-3">
                            <legend class="fs-6 fw-semibold">Deliver to</legend>
                            <?= field_error('address_id') ?>
                            <?php foreach ($addresses as $a): ?>
                                <div class="form-check border rounded p-2 ps-5 mb-2">
                                    <input class="form-check-input" type="radio" name="address_id" id="addr<?= $a['id'] ?>" value="<?= $a['id'] ?>" <?= $addrChoice === (string) $a['id'] ? 'checked' : '' ?> data-quote>
                                    <label class="form-check-label small w-100" for="addr<?= $a['id'] ?>">
                                        <strong><?= e($a['label'] ?: $a['recipient_name']) ?></strong> — <?= e($a['location']) ?>, <?= e($a['town']) ?>, <?= e($a['county_name']) ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                            <div class="form-check border rounded p-2 ps-5">
                                <input class="form-check-input" type="radio" name="address_id" id="addrNew" value="new" <?= $addrChoice === 'new' ? 'checked' : '' ?> data-quote data-new-address>
                                <label class="form-check-label small" for="addrNew">Use a different address</label>
                            </div>
                        </fieldset>
                    <?php else: ?>
                        <input type="hidden" name="address_id" value="new">
                    <?php endif; ?>

                    <div class="row g-3" data-new-address-fields <?= $addresses && $addrChoice !== 'new' ? 'hidden' : '' ?>>
                        <div class="col-md-6">
                            <label class="form-label" for="county_id">County</label>
                            <select class="form-select<?= invalid('county_id') ?>" id="county_id" name="county_id" data-quote data-towns-for="checkout_towns" data-towns-url="<?= e(url('/checkout/towns')) ?>">
                                <option value="">Select county…</option>
                                <?php foreach ($counties as $c): ?>
                                    <option value="<?= $c['id'] ?>" <?= (string) fv('county_id', '') === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?= field_error('county_id') ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="town">Town / area</label>
                            <input class="form-control<?= invalid('town') ?>" id="town" name="town" list="checkout_towns" autocomplete="address-level2" value="<?= e(fv('town', '')) ?>" data-quote>
                            <datalist id="checkout_towns"></datalist>
                            <?= field_error('town') ?>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="location">Estate, street, building / house no.</label>
                            <input class="form-control<?= invalid('location') ?>" id="location" name="location" autocomplete="street-address" value="<?= e(fv('location', '')) ?>">
                            <?= field_error('location') ?>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="instructions">Delivery instructions <span class="text-muted fw-normal">(optional)</span></label>
                            <textarea class="form-control<?= invalid('instructions') ?>" id="instructions" name="instructions" rows="2" maxlength="500"><?= e(fv('instructions', '')) ?></textarea>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($pickupAvailable): ?>
                <div data-panel="pickup" <?= $method === 'pickup' ? '' : 'hidden' ?>>
                    <?php if ($pickupNote !== ''): ?><p class="small wm-callout mb-3"><i class="bi bi-info-circle me-1" aria-hidden="true"></i><?= e($pickupNote) ?></p><?php endif; ?>
                    <?= field_error('pickup_point_id') ?>
                    <fieldset>
                        <legend class="fs-6 fw-semibold">Choose a pickup station</legend>
                        <?php foreach ($byCounty as $county => $points): ?>
                            <h3 class="fs-6 text-muted text-uppercase mt-3 mb-2 small fw-bold"><?= e($county) ?></h3>
                            <?php foreach ($points as $pp): ?>
                                <div class="form-check border rounded p-2 ps-5 mb-2">
                                    <input class="form-check-input" type="radio" name="pickup_point_id" id="pp<?= $pp['id'] ?>" value="<?= $pp['id'] ?>" <?= (string) fv('pickup_point_id', '') === (string) $pp['id'] ? 'checked' : '' ?> data-quote>
                                    <label class="form-check-label small w-100" for="pp<?= $pp['id'] ?>">
                                        <strong><?= e($pp['name']) ?></strong>
                                        <?= (float) $pp['pickup_fee'] > 0 ? '<span class="badge text-bg-light ms-1">' . e(money($pp['pickup_fee'])) . '</span>' : '<span class="badge badge-soft-success ms-1">Free</span>' ?>
                                        <span class="d-block"><?= e($pp['address']) ?>, <?= e($pp['town']) ?></span>
                                        <span class="d-block text-muted"><i class="bi bi-clock" aria-hidden="true"></i> <?= e($pp['opening_hours']) ?></span>
                                        <?php if ($pp['instructions']): ?><span class="d-block text-muted"><i class="bi bi-info-circle" aria-hidden="true"></i> <?= e($pp['instructions']) ?></span><?php endif; ?>
                                        <?php if ($pp['map_url']): ?><a class="d-inline-block mt-1" href="<?= e($pp['map_url']) ?>" target="_blank" rel="noopener noreferrer">View on map</a><?php endif; ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </fieldset>
                </div>
                <?php endif; ?>
                <p class="small mt-2 mb-0" data-quote-message role="status" aria-live="polite"></p>
            </section>

            <!-- 3. Payment -->
            <section class="wm-card wm-checkout-step" aria-labelledby="stepPay">
                <h2 id="stepPay"><span class="wm-step-num">3</span> Payment method</h2>
                <?= field_error('payment_method') ?>
                <?php if (!$payments): ?>
                    <div class="alert alert-warning small">No payment method is available at the moment. Please contact us to complete your order.</div>
                <?php endif; ?>
                <?php foreach ($payments as $key => $p): ?>
                    <div class="form-check border rounded p-2 ps-5 mb-2">
                        <input class="form-check-input" type="radio" name="payment_method" id="pm_<?= $key ?>" value="<?= $key ?>" <?= $payChoice === $key && $p['available'] ? 'checked' : '' ?> <?= $key === 'cod' ? 'data-cod' : '' ?><?= $p['available'] ? '' : ' disabled data-blocked' ?>>
                        <label class="form-check-label w-100" for="pm_<?= $key ?>">
                            <strong class="small"><?= e($p['label']) ?></strong>
                            <?php if ($p['description'] !== ''): ?><span class="d-block small text-muted"><?= e($p['description']) ?></span><?php endif; ?>
                            <?php if (!$p['available']): ?><span class="d-block small text-danger"><?= e((string) $p['reason']) ?></span><?php endif; ?>
                            <span class="d-block small text-danger" data-cod-reason hidden></span>
                        </label>
                    </div>
                <?php endforeach; ?>
                <div class="mt-3">
                    <label class="form-label small" for="notes">Order notes <span class="text-muted fw-normal">(optional)</span></label>
                    <textarea class="form-control" id="notes" name="notes" rows="2" maxlength="500"><?= e(fv('notes', '')) ?></textarea>
                </div>
            </section>
        </div>

        <aside class="col-lg-4">
            <div class="wm-card p-3 wm-sticky-summary">
                <h2 class="fs-6 fw-bold text-uppercase border-bottom pb-2">Order summary</h2>
                <?php
                $sellerGroups = \Waimart\Services\MarketplacePolicy::groups($cart['lines']);
                $multiSeller = count($sellerGroups) > 1 || !array_values($cart['lines'])[0]['seller_is_platform'];
                ?>
                <?php foreach ($sellerGroups as $g): ?>
                    <?php if ($multiSeller): ?><div class="small fw-semibold mt-2">Sold by <?= e($g['seller_name']) ?></div><?php endif; ?>
                    <ul class="list-unstyled small mb-2">
                        <?php foreach ($g['lines'] as $l): ?>
                            <li class="d-flex justify-content-between gap-2 py-1">
                                <span><?= (int) $l['quantity'] ?> × <?= e($l['name']) ?><?= $l['variant_name'] ? ' <span class="text-muted">(' . e($l['variant_name']) . ')</span>' : '' ?></span>
                                <span class="text-nowrap"><?= e(money($l['line_total'])) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endforeach; ?>
                <div class="d-flex justify-content-between border-top pt-2"><span>Subtotal</span><span><?= e(money($cart['subtotal'])) ?></span></div>
                <div class="d-flex justify-content-between"><span>Delivery</span><span data-fee>Select an option</span></div>
                <?php if (count($sellerGroups) > 1): ?>
                    <div class="small text-muted"><?= \Waimart\Services\MarketplacePolicy::feePolicy() === 'per_seller'
                        ? 'Delivery is charged once for each seller (' . count($sellerGroups) . ' sellers).'
                        : 'One delivery fee covers your whole order, even with several sellers.' ?></div>
                <?php endif; ?>
                <div class="d-flex justify-content-between text-success" data-discount-row hidden><span>Discount</span><span data-discount-amount></span></div>
                <div class="small text-muted" data-eta></div>
                <div class="d-flex justify-content-between border-top pt-2 mt-2 fs-5 fw-bold"><span>Total</span><span data-total><?= e(money($cart['subtotal'])) ?></span></div>

                <div class="mt-3">
                    <label class="form-label small fw-semibold" for="coupon_code">Coupon code</label>
                    <div class="input-group input-group-sm">
                        <input class="form-control text-uppercase<?= invalid('coupon_code') ?>" id="coupon_code" name="coupon_code" maxlength="40" autocomplete="off" value="<?= e(fv('coupon_code', '')) ?>" data-quote>
                        <button class="btn btn-outline-secondary" type="button" data-apply-coupon>Apply</button>
                    </div>
                    <?= field_error('coupon_code') ?>
                    <div class="small mt-1" data-coupon-message role="status" aria-live="polite"></div>
                </div>

                <button class="btn btn-wm btn-lg w-100 mt-3" type="submit" data-place-order>Place order</button>
                <p class="small text-muted mt-2 mb-0">By placing your order you agree to our <a href="<?= e(url('/terms')) ?>" target="_blank">terms</a>.
                    Totals are confirmed by our system when you place the order, and your items are reserved for you at that moment.</p>
            </div>
        </aside>
    </form>
</div>
