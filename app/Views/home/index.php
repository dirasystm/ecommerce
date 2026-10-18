<?php
use Waimart\Core\View;

/**
 * @var list<array<string,mixed>> $banners
 * @var array{sale:array<string,mixed>,products:list<array<string,mixed>>}|null $flash
 * @var list<array<string,mixed>> $deals
 * @var list<array<string,mixed>> $featured
 * @var list<array{category:array<string,mixed>,products:list<array<string,mixed>>}> $rails
 * @var list<array<string,mixed>> $tree
 */
$slideThemes = ['wm-slide-green', 'wm-slide-orange', 'wm-slide-dark'];
$slideArt = ['bag-heart', 'phone', 'basket2', 'truck', 'tv', 'stars'];
$tileColours = ['#e8f3ee', '#fff3e8', '#fff8e1', '#eef3fb', '#fdecef', '#f1eefb'];
?>
<div class="container-wm">
    <h1 class="visually-hidden"><?= e(brand_name()) ?> — Everything You Need. One Marketplace.</h1>

    <!-- Hero: category sidebar · carousel · info tiles -->
    <section class="wm-hero-grid wm-hero-row" aria-label="Featured">
        <nav class="wm-card wm-cat-menu wm-cat-sidebar" aria-label="Shop by category">
            <ul>
                <?php foreach (array_slice($tree, 0, 12) as $cat): ?>
                    <li>
                        <a href="<?= e(url('/category/' . $cat['slug'])) ?>">
                            <i class="bi bi-<?= e($cat['icon'] ?: 'grid') ?>" aria-hidden="true"></i><?= e($cat['name']) ?>
                        </a>
                        <?php if ($cat['children']): ?>
                            <div class="wm-cat-flyout">
                                <h3><?= e($cat['name']) ?></h3>
                                <ul>
                                    <?php foreach ($cat['children'] as $child): ?>
                                        <li><a href="<?= e(url('/category/' . $child['slug'])) ?>"><?= e($child['name']) ?></a></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
                <?php if (!$tree): ?>
                    <li class="px-3 py-2 text-muted small">Categories coming soon.</li>
                <?php endif; ?>
            </ul>
        </nav>

        <div class="wm-hero">
            <div id="wmHero" class="carousel slide h-100" data-bs-ride="carousel" data-bs-interval="6000">
                <?php if (count($banners) > 1): ?>
                    <div class="carousel-indicators">
                        <?php foreach ($banners as $i => $b): ?>
                            <button type="button" data-bs-target="#wmHero" data-bs-slide-to="<?= $i ?>" <?= $i === 0 ? 'class="active" aria-current="true"' : '' ?>
                                    aria-label="Slide <?= $i + 1 ?>: <?= e($b['title']) ?>"></button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <div class="carousel-inner">
                    <?php if (!$banners): ?>
                        <div class="carousel-item active">
                            <div class="wm-slide wm-slide-green">
                                <div class="wm-slide-content">
                                    <span class="wm-eyebrow">Welcome</span>
                                    <h2>Everything you need. One marketplace.</h2>
                                    <p>Pay with M-Pesa or cash on delivery. Delivered to your door or ready at a pickup station.</p>
                                </div>
                                <i class="bi bi-cart3 wm-slide-art" aria-hidden="true"></i>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php foreach ($banners as $i => $b): ?>
                        <div class="carousel-item<?= $i === 0 ? ' active' : '' ?>">
                            <div class="wm-slide <?= $slideThemes[$i % 3] ?>">
                                <?php if (!empty($b['image_path'])): ?>
                                    <img class="wm-slide-img" src="<?= e(media($b['image_path'])) ?>" alt="">
                                <?php endif; ?>
                                <div class="wm-slide-content">
                                    <span class="wm-eyebrow"><?= e(brand_name()) ?> deals</span>
                                    <h2><?= e($b['title']) ?></h2>
                                    <?php if ($b['subtitle']): ?><p><?= e($b['subtitle']) ?></p><?php endif; ?>
                                    <?php if ($b['link_url']): ?>
                                        <a class="btn" href="<?= e(url($b['link_url'])) ?>">Shop now</a>
                                    <?php endif; ?>
                                </div>
                                <?php if (empty($b['image_path'])): ?>
                                    <i class="bi bi-<?= e($slideArt[$i % count($slideArt)]) ?> wm-slide-art" aria-hidden="true"></i>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if (count($banners) > 1): ?>
                    <button class="carousel-control-prev" type="button" data-bs-target="#wmHero" data-bs-slide="prev">
                        <span class="carousel-control-prev-icon" aria-hidden="true"></span><span class="visually-hidden">Previous slide</span>
                    </button>
                    <button class="carousel-control-next" type="button" data-bs-target="#wmHero" data-bs-slide="next">
                        <span class="carousel-control-next-icon" aria-hidden="true"></span><span class="visually-hidden">Next slide</span>
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <div class="wm-side-tiles">
            <a class="wm-card wm-tile" href="<?= e(url('/how-to-pay')) ?>">
                <span class="wm-tile-icon wm-ic-green"><i class="bi bi-phone" aria-hidden="true"></i></span>
                <span><strong>Pay with M-Pesa</strong><small>Till, Paybill or Send Money</small></span>
            </a>
            <a class="wm-card wm-tile" href="<?= e(url('/delivery-pickup')) ?>">
                <span class="wm-tile-icon wm-ic-orange"><i class="bi bi-shop" aria-hidden="true"></i></span>
                <span><strong>Pickup stations</strong><small>Collect at a time that suits you</small></span>
            </a>
            <a class="wm-card wm-tile" href="<?= e(url('/returns-policy')) ?>">
                <span class="wm-tile-icon wm-ic-green"><i class="bi bi-arrow-repeat" aria-hidden="true"></i></span>
                <span><strong>Easy returns</strong><small>Hassle-free return requests</small></span>
            </a>
        </div>
    </section>

    <!-- Quick category links -->
    <?php if ($tree): ?>
        <section class="wm-card wm-section" aria-label="Popular categories">
            <div class="wm-quick-cats">
                <?php foreach (array_slice($tree, 0, 6) as $i => $cat): ?>
                    <a class="wm-quick-cat" href="<?= e(url('/category/' . $cat['slug'])) ?>">
                        <span class="wm-quick-cat-icon" style="background: <?= $tileColours[$i % 6] ?>">
                            <i class="bi bi-<?= e($cat['icon'] ?: 'grid') ?>" aria-hidden="true"></i>
                        </span>
                        <?= e($cat['name']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <!-- Flash sale -->
    <?php if ($flash && $flash['products']): ?>
        <section class="wm-card wm-section" aria-labelledby="wmFlashTitle">
            <div class="wm-section-head wm-head-flash">
                <h2 id="wmFlashTitle"><i class="bi bi-lightning-charge-fill" aria-hidden="true"></i> <?= e($flash['sale']['title']) ?></h2>
                <div class="wm-countdown" data-countdown="<?= e(date('c', strtotime($flash['sale']['ends_at']))) ?>"
                     role="timer" aria-label="Time left in this sale">
                    <span>Time left:</span>
                    <span class="wm-cd-box" data-cd="h">--</span>h :
                    <span class="wm-cd-box" data-cd="m">--</span>m :
                    <span class="wm-cd-box" data-cd="s">--</span>s
                </div>
                <a class="wm-see-all" href="<?= e(url('/flash-sales')) ?>">See all <i class="bi bi-chevron-right" aria-hidden="true"></i></a>
            </div>
            <div class="wm-rail" tabindex="0" aria-label="Flash sale products, scroll horizontally">
                <?php foreach ($flash['products'] as $p): ?>
                    <?= View::partial('partials/product-card', ['p' => $p, 'flash' => true]) ?>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <!-- Top deals -->
    <?php if ($deals): ?>
        <section class="wm-card wm-section" aria-labelledby="wmDealsTitle">
            <div class="wm-section-head wm-head-green">
                <h2 id="wmDealsTitle">Top Deals</h2>
                <a class="wm-see-all" href="<?= e(url('/search', ['sort' => 'discount'])) ?>">See all <i class="bi bi-chevron-right" aria-hidden="true"></i></a>
            </div>
            <div class="wm-rail" tabindex="0" aria-label="Top deals, scroll horizontally">
                <?php foreach ($deals as $p): ?>
                    <?= View::partial('partials/product-card', ['p' => $p]) ?>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <!-- Category tiles -->
    <?php if (count($tree) > 6): ?>
        <section class="wm-card wm-section" aria-labelledby="wmCatsTitle">
            <div class="wm-section-head">
                <h2 id="wmCatsTitle">Shop by Category</h2>
                <a class="wm-see-all" href="<?= e(url('/categories')) ?>">All categories <i class="bi bi-chevron-right" aria-hidden="true"></i></a>
            </div>
            <div class="wm-cat-tiles">
                <?php foreach (array_slice($tree, 0, 12) as $i => $cat): ?>
                    <a class="wm-cat-tile" style="background: <?= $tileColours[$i % 6] ?>" href="<?= e(url('/category/' . $cat['slug'])) ?>">
                        <i class="bi bi-<?= e($cat['icon'] ?: 'grid') ?>" aria-hidden="true"></i><?= e($cat['name']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <!-- Featured -->
    <?php if ($featured): ?>
        <section class="wm-card wm-section" aria-labelledby="wmFeaturedTitle">
            <div class="wm-section-head">
                <h2 id="wmFeaturedTitle">Top Selling Items</h2>
            </div>
            <div class="wm-grid">
                <?php foreach ($featured as $p): ?>
                    <?= View::partial('partials/product-card', ['p' => $p]) ?>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <!-- Category rails -->
    <?php foreach ($rails as $i => $rail): ?>
        <section class="wm-card wm-section" aria-labelledby="wmRail<?= $i ?>">
            <div class="wm-section-head">
                <h2 id="wmRail<?= $i ?>"><?= e($rail['category']['name']) ?></h2>
                <a class="wm-see-all" href="<?= e(url('/category/' . $rail['category']['slug'])) ?>">See all <i class="bi bi-chevron-right" aria-hidden="true"></i></a>
            </div>
            <div class="wm-rail" tabindex="0" aria-label="<?= e($rail['category']['name']) ?>, scroll horizontally">
                <?php foreach ($rail['products'] as $p): ?>
                    <?= View::partial('partials/product-card', ['p' => $p]) ?>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>

    <!-- Why shop with us -->
    <section class="wm-values" aria-label="Why shop with <?= e(brand_name()) ?>">
        <div class="wm-card wm-value"><i class="bi bi-truck" aria-hidden="true"></i><div><strong>Home &amp; office delivery</strong><span>Delivery estimates shown at checkout for your area.</span></div></div>
        <div class="wm-card wm-value"><i class="bi bi-shop" aria-hidden="true"></i><div><strong>Pickup stations</strong><span>Collect your order with a secure collection code.</span></div></div>
        <div class="wm-card wm-value"><i class="bi bi-shield-check" aria-hidden="true"></i><div><strong>Safe payments</strong><span>M-Pesa Till, Paybill, Send Money or cash on delivery.</span></div></div>
        <div class="wm-card wm-value"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i><div><strong>Easy returns</strong><span>Request a return from your order page.</span></div></div>
    </section>
</div>
