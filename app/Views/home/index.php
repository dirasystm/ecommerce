<?php
use Waimart\Core\View;
use Waimart\Services\Brand;
use Waimart\Services\ContentRenderer;

/**
 * The homepage, section by section (Admin → Content → Homepage decides which sections show, in what order).
 * @var list<array{section:array<string,mixed>,data:array<string,mixed>}> $blocks
 * @var list<array<string,mixed>> $tree
 */
$slideThemes = ['wm-slide-green', 'wm-slide-orange', 'wm-slide-dark'];
$slideArt = ['bag-heart', 'phone', 'basket2', 'truck', 'tv', 'stars'];
$tileColours = ['#e8f3ee', '#fff3e8', '#fff8e1', '#eef3fb', '#fdecef', '#f1eefb'];
$tagline = Brand::tagline();
$intro = trim((string) \Waimart\Core\Settings::get('store_description', ''));
?>
<div class="container-wm">
    <h1 class="visually-hidden"><?= e(brand_name()) ?><?= $tagline !== '' ? ' — ' . e($tagline) : '' ?></h1>

<?php foreach ($blocks as $n => ['section' => $s, 'data' => $d]): $title = ContentRenderer::plain((string) $s['title']); $hid = 'wmSec' . (int) $s['id']; ?>
<?php switch ($s['type']):
case 'hero': $banners = $d['banners']; ?>
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
                                    <h2><?= e($tagline !== '' ? $tagline : brand_name()) ?></h2>
                                    <?php if ($intro !== ''): ?><p><?= e($intro) ?></p><?php endif; ?>
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
                                    <?php if (!empty($s['subtitle'])): ?><span class="wm-eyebrow"><?= e(ContentRenderer::plain((string) $s['subtitle'])) ?></span><?php endif; ?>
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

        <?php if ($d['tiles']): ?>
            <div class="wm-side-tiles">
                <?php foreach ($d['tiles'] as $i => $t): ?>
                    <a class="wm-card wm-tile" href="<?= e($t['href']) ?>"<?= $t['new_tab'] ? ' target="_blank" rel="noopener"' : '' ?>>
                        <span class="wm-tile-icon <?= $i % 2 ? 'wm-ic-orange' : 'wm-ic-green' ?>"><i class="bi bi-<?= e($t['icon'] ?: 'star') ?>" aria-hidden="true"></i></span>
                        <span><strong><?= e($t['label']) ?></strong><?php if ($t['description'] !== ''): ?><small><?= e($t['description']) ?></small><?php endif; ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
<?php break;

case 'quick_categories': if ($d['cats']): ?>
    <section class="wm-card wm-section" aria-label="<?= e($title) ?>">
        <div class="wm-quick-cats">
            <?php foreach ($d['cats'] as $i => $cat): ?>
                <a class="wm-quick-cat" href="<?= e(url('/category/' . $cat['slug'])) ?>">
                    <span class="wm-quick-cat-icon" style="background: <?= $tileColours[$i % 6] ?>">
                        <i class="bi bi-<?= e($cat['icon'] ?: 'grid') ?>" aria-hidden="true"></i>
                    </span>
                    <?= e($cat['name']) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; break;

case 'flash_sale': $flash = $d['flash']; if ($flash && $flash['products']): ?>
    <section class="wm-card wm-section" aria-labelledby="<?= $hid ?>">
        <div class="wm-section-head wm-head-flash">
            <h2 id="<?= $hid ?>"><i class="bi bi-lightning-charge-fill" aria-hidden="true"></i> <?= e($flash['sale']['title']) ?></h2>
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
<?php endif; break;

case 'recommended': if ($d['products']): ?>
    <section class="wm-card wm-section" aria-labelledby="<?= $hid ?>">
        <div class="wm-section-head">
            <h2 id="<?= $hid ?>"><?= e($d['personal'] ? $title : 'Popular right now') ?></h2>
            <?php if ($d['personal']): ?><span class="small text-muted">Based on what you have viewed · <a href="<?= e(url(\Waimart\Core\Auth::id() ? '/account/history' : '/privacy-choices')) ?>">Why?</a></span><?php endif; ?>
        </div>
        <div class="wm-rail" tabindex="0" aria-label="<?= e($d['personal'] ? $title : 'Popular right now') ?>, scroll horizontally">
            <?php foreach ($d['products'] as $p): ?>
                <?= View::partial('partials/product-card', ['p' => $p, 'rec' => 'home', 'dismiss' => $d['personal']]) ?>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; break;

case 'top_deals': if ($d['products']): ?>
    <section class="wm-card wm-section" aria-labelledby="<?= $hid ?>">
        <div class="wm-section-head wm-head-green">
            <h2 id="<?= $hid ?>"><?= e($title) ?></h2>
            <a class="wm-see-all" href="<?= e(url('/search', ['sort' => 'discount'])) ?>">See all <i class="bi bi-chevron-right" aria-hidden="true"></i></a>
        </div>
        <div class="wm-rail" tabindex="0" aria-label="<?= e($title) ?>, scroll horizontally">
            <?php foreach ($d['products'] as $p): ?>
                <?= View::partial('partials/product-card', ['p' => $p]) ?>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; break;

case 'shop_by_category': if (count($d['cats']) > 0): ?>
    <section class="wm-card wm-section" aria-labelledby="<?= $hid ?>">
        <div class="wm-section-head">
            <h2 id="<?= $hid ?>"><?= e($title) ?></h2>
            <a class="wm-see-all" href="<?= e(url('/categories')) ?>">All categories <i class="bi bi-chevron-right" aria-hidden="true"></i></a>
        </div>
        <div class="wm-cat-tiles">
            <?php foreach ($d['cats'] as $i => $cat): ?>
                <a class="wm-cat-tile" style="background: <?= $tileColours[$i % 6] ?>" href="<?= e(url('/category/' . $cat['slug'])) ?>">
                    <i class="bi bi-<?= e($cat['icon'] ?: 'grid') ?>" aria-hidden="true"></i><?= e($cat['name']) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; break;

case 'featured': if ($d['products']): ?>
    <section class="wm-card wm-section" aria-labelledby="<?= $hid ?>">
        <div class="wm-section-head">
            <h2 id="<?= $hid ?>"><?= e($title) ?></h2>
        </div>
        <div class="wm-grid">
            <?php foreach ($d['products'] as $p): ?>
                <?= View::partial('partials/product-card', ['p' => $p]) ?>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; break;

case 'category_rails': foreach ($d['rails'] as $i => $rail): ?>
    <section class="wm-card wm-section" aria-labelledby="<?= $hid . '-' . $i ?>">
        <div class="wm-section-head">
            <h2 id="<?= $hid . '-' . $i ?>"><?= e($rail['category']['name']) ?></h2>
            <a class="wm-see-all" href="<?= e(url('/category/' . $rail['category']['slug'])) ?>">See all <i class="bi bi-chevron-right" aria-hidden="true"></i></a>
        </div>
        <div class="wm-rail" tabindex="0" aria-label="<?= e($rail['category']['name']) ?>, scroll horizontally">
            <?php foreach ($rail['products'] as $p): ?>
                <?= View::partial('partials/product-card', ['p' => $p]) ?>
            <?php endforeach; ?>
        </div>
    </section>
<?php endforeach; break;

case 'category_row': if ($d['products']): ?>
    <section class="wm-card wm-section" aria-labelledby="<?= $hid ?>">
        <div class="wm-section-head">
            <h2 id="<?= $hid ?>"><?= e($title) ?></h2>
            <a class="wm-see-all" href="<?= e(url('/category/' . $s['category_slug'])) ?>">See all <i class="bi bi-chevron-right" aria-hidden="true"></i></a>
        </div>
        <div class="wm-rail" tabindex="0" aria-label="<?= e($title) ?>, scroll horizontally">
            <?php foreach ($d['products'] as $p): ?>
                <?= View::partial('partials/product-card', ['p' => $p]) ?>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; break;

case 'values': if ($d['items']): ?>
    <section class="wm-values" aria-label="<?= e($title) ?>">
        <?php foreach ($d['items'] as $v): ?>
            <div class="wm-card wm-value"><i class="bi bi-<?= e($v['icon'] ?: 'check-circle') ?>" aria-hidden="true"></i><div><strong><?php if ($v['href'] !== ''): ?><a href="<?= e($v['href']) ?>"><?= e($v['label']) ?></a><?php else: ?><?= e($v['label']) ?><?php endif; ?></strong><?php if ($v['description'] !== ''): ?><span><?= e($v['description']) ?></span><?php endif; ?></div></div>
        <?php endforeach; ?>
    </section>
<?php endif; break;

case 'text': ?>
    <section class="wm-card wm-section wm-page" aria-labelledby="<?= $hid ?>">
        <h2 id="<?= $hid ?>"><?= e($title) ?></h2>
        <?= $d['html'] /* ContentRenderer output: safe HTML */ ?>
    </section>
<?php break;
endswitch; ?>
<?php endforeach; ?>
</div>
