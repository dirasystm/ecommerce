<?php
use Waimart\Core\Auth;
use Waimart\Core\View;
use Waimart\Services\Brand;

/**
 * Link studio. @var array<string,mixed> $l @var array<string,mixed> $creative @var array<string,mixed>|null $stats
 * @var string $label @var array<string,string> $q @var bool $expired
 */
$manage = Auth::can('marketing.manage');
$f = $creative['facts'];
$on = $l['is_active'] && !$expired;
$platforms = ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'whatsapp' => 'WhatsApp', 'x' => 'X (Twitter)'];
?>
<div class="wm-admin-head">
    <h1>Link studio</h1>
    <a class="btn btn-outline-secondary" href="<?= e(url('/admin/marketing/campaigns/' . $l['campaign_id'], $q)) ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> <?= e((string) $l['campaign_name']) ?></a>
</div>
<?= View::partial('admin/marketing/_nav', ['active' => '/admin/marketing/campaigns']) ?>

<?php if (!$on): ?>
    <div class="alert alert-warning small"><strong>This link is off<?= $expired ? ' (it expired on ' . e(date('d M Y', strtotime((string) $l['expires_at']))) . ')' : '' ?>.</strong> People who open it go to the homepage and are not counted.</div>
<?php endif; ?>
<?php if (!$f['available']): ?>
    <div class="alert alert-danger small"><strong>The product is no longer on sale or is out of stock.</strong> Stop sharing this link, or point a new link to another product.</div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-5">
        <section class="wm-panel mb-3">
            <div class="wm-panel-head">Creative</div>
            <div class="wm-panel-body">
                <figure class="wm-creative mb-3" data-creative
                        data-brand="<?= e(Brand::name()) ?>" data-headline="<?= e((string) $l['headline']) ?>"
                        data-price="<?= e($f['price'] !== null ? money($f['price']) : '') ?>" data-was="<?= e($f['was'] !== null ? money($f['was']) : '') ?>"
                        data-cta="<?= e((string) $l['cta']) ?>" data-coupon="<?= e((string) ($l['coupon_code'] ?? '')) ?>" data-url="<?= e(preg_replace('#^https?://#', '', (string) $creative['url'])) ?>">
                    <img src="<?= e((string) $creative['image']) ?>" alt="" class="wm-creative-img" crossorigin="anonymous" data-creative-img>
                    <figcaption class="wm-creative-body">
                        <div class="wm-creative-head"><?= e((string) $l['headline']) ?></div>
                        <?php if ($f['price'] !== null): ?>
                            <div><strong><?= e(money($f['price'])) ?></strong><?php if ($f['was'] !== null): ?> <s class="text-muted small"><?= e(money($f['was'])) ?></s> <span class="badge text-bg-danger">-<?= (int) $f['discount'] ?>%</span><?php endif; ?></div>
                        <?php endif; ?>
                        <?php if ($f['reviews'] > 0): ?><div class="small text-muted"><i class="bi bi-star-fill text-warning" aria-hidden="true"></i> <?= e(number_format($f['rating'], 1)) ?> (<?= (int) $f['reviews'] ?> review<?= $f['reviews'] === 1 ? '' : 's' ?>)</div><?php endif; ?>
                        <?php if ($l['coupon_code']): ?><div class="small">Code <strong><?= e((string) $l['coupon_code']) ?></strong></div><?php endif; ?>
                        <span class="btn btn-sm btn-wm-green mt-1"><?= e((string) $l['cta']) ?></span>
                    </figcaption>
                </figure>
                <p class="small text-muted">Every fact comes from the shop now: name, current price<?= $f['was'] !== null ? ', the real previous price' : '' ?>, rating and stock. Nothing is invented.</p>
                <div class="d-flex flex-wrap gap-2">
                    <button class="btn btn-sm btn-outline-secondary" type="button" data-creative-download="1080x1080"><i class="bi bi-download" aria-hidden="true"></i> Square image (1080×1080)</button>
                    <button class="btn btn-sm btn-outline-secondary" type="button" data-creative-download="1080x1920"><i class="bi bi-download" aria-hidden="true"></i> Story image (1080×1920)</button>
                </div>
                <p class="small text-danger mt-2 mb-0 d-none" data-creative-error role="status"></p>
            </div>
        </section>

        <section class="wm-panel mb-3">
            <div class="wm-panel-head">QR code (for posters and flyers)</div>
            <div class="wm-panel-body text-center">
                <div class="wm-qr mx-auto" data-qr="<?= e((string) $creative['qr_url']) ?>" role="img" aria-label="QR code for <?= e((string) $creative['qr_url']) ?>"></div>
                <p class="small text-muted mt-2">Scans are counted separately as "QR".</p>
                <button class="btn btn-sm btn-outline-secondary" type="button" data-qr-download="<?= e('qr-' . $l['code'] . '.png') ?>"><i class="bi bi-download" aria-hidden="true"></i> Download QR code</button>
            </div>
        </section>
    </div>

    <div class="col-lg-7">
        <section class="wm-panel mb-3">
            <div class="wm-panel-head">Short link</div>
            <div class="wm-panel-body">
                <div class="input-group">
                    <input class="form-control" id="short-url" readonly value="<?= e((string) $creative['url']) ?>" aria-label="Short link">
                    <button class="btn btn-outline-secondary" type="button" data-copy="#short-url">Copy</button>
                </div>
                <p class="small text-muted mt-2 mb-0">Opens <code><?= e((string) $l['destination_path']) ?></code> with campaign tags. Variant <?= e((string) $l['variant']) ?>.
                    <?= $l['expires_at'] ? 'Stops working after ' . e(date('d M Y', strtotime((string) $l['expires_at']))) . '.' : '' ?></p>
                <?php if ($stats): ?>
                    <p class="small mb-0 mt-2"><strong><?= e($label) ?>:</strong> <?= number_format((int) $stats['clicks']) ?> clicks (<?= number_format((int) $stats['qr']) ?> QR), <?= number_format((int) $stats['visitors']) ?> visitors (est.), <?= number_format((int) $stats['carts']) ?> add-to-cart, <?= number_format((int) $stats['orders']) ?> orders, <?= e(money($stats['sales'])) ?> paid.</p>
                <?php endif; ?>
            </div>
        </section>

        <section class="wm-panel mb-3">
            <div class="wm-panel-head">Ready-to-post texts</div>
            <div class="wm-panel-body">
                <p class="small text-muted">Copy and post them yourself. This shop never posts or sends messages on social media for you.</p>
                <?php foreach ($platforms as $k => $name): ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="form-label mb-0 small fw-semibold" for="text-<?= e($k) ?>"><?= e($name) ?></label>
                            <button class="btn btn-sm btn-link p-0" type="button" data-copy="#text-<?= e($k) ?>">Copy</button>
                        </div>
                        <textarea class="form-control form-control-sm" id="text-<?= e($k) ?>" rows="<?= $k === 'x' ? 2 : 5 ?>" readonly><?= e((string) $creative['texts'][$k]) ?></textarea>
                        <?php if ($k === 'x'): ?><div class="form-text"><?= mb_strlen((string) $creative['texts']['x']) ?> characters (X counts links as 23)</div><?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <div class="mb-3">
                    <div class="small fw-semibold mb-1">Hashtags</div>
                    <div class="d-flex flex-wrap gap-1"><?php foreach ($creative['hashtags'] as $t): ?><span class="badge badge-soft-muted"><?= e($t) ?></span><?php endforeach; ?></div>
                </div>
                <div class="small fw-semibold mb-1">Share now</div>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach (['facebook' => ['Facebook', 'facebook'], 'whatsapp' => ['WhatsApp', 'whatsapp'], 'x' => ['X', 'twitter-x'], 'telegram' => ['Telegram', 'telegram'], 'linkedin' => ['LinkedIn', 'linkedin']] as $k => [$n, $icon]): ?>
                        <a class="btn btn-sm btn-outline-secondary" href="<?= e((string) $creative['share'][$k]) ?>" target="_blank" rel="noopener noreferrer"><i class="bi bi-<?= e($icon) ?>" aria-hidden="true"></i> <?= e($n) ?><span class="visually-hidden"> (opens in a new tab)</span></a>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <?php if ($manage): ?>
            <section class="wm-panel mb-3">
                <div class="wm-panel-head">Edit wording</div>
                <form class="wm-panel-body row g-3" method="post" action="<?= e(url('/admin/marketing/links/' . $l['id'])) ?>" novalidate>
                    <?= csrf_field() ?>
                    <div class="col-md-8">
                        <label class="form-label" for="headline">Headline</label>
                        <input class="form-control<?= invalid('headline') ?>" id="headline" name="headline" maxlength="120" required value="<?= e((string) fv('headline', (string) $l['headline'])) ?>">
                        <?= field_error('headline') ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="cta">Button text</label>
                        <input class="form-control<?= invalid('cta') ?>" id="cta" name="cta" maxlength="40" required value="<?= e((string) fv('cta', (string) $l['cta'])) ?>">
                        <?= field_error('cta') ?>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="description">Description</label>
                        <textarea class="form-control<?= invalid('description') ?>" id="description" name="description" rows="2" maxlength="500"><?= e((string) fv('description', (string) ($l['description'] ?? ''))) ?></textarea>
                    </div>
                    <div class="col-12 d-flex flex-wrap gap-2 align-items-center">
                        <button class="btn btn-wm-green" type="submit">Save wording</button>
                        <span class="small text-muted">Where the link goes cannot be changed (so past results stay meaningful). Create a new link instead.</span>
                    </div>
                </form>
                <form class="wm-panel-body pt-0" method="post" action="<?= e(url('/admin/marketing/links/' . $l['id'] . '/active')) ?>"<?= $l['is_active'] ? ' data-confirm="Switch this link off? Anyone who opens it will be sent to the homepage."' : '' ?>>
                    <?= csrf_field() ?><input type="hidden" name="active" value="<?= $l['is_active'] ? '0' : '1' ?>">
                    <button class="btn btn-sm <?= $l['is_active'] ? 'btn-outline-danger' : 'btn-outline-success' ?>" type="submit"><?= $l['is_active'] ? 'Switch link off' : 'Switch link on' ?></button>
                </form>
            </section>
        <?php endif; ?>
    </div>
</div>
