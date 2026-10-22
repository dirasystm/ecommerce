<?php
use Waimart\Core\Auth;
use Waimart\Core\View;
use Waimart\Services\Marketing\CampaignService;

/**
 * @var array<string,mixed> $c @var list<array<string,mixed>> $links @var list<array{stage:string,count:?int}> $funnel
 * @var array<string,array{name:string,count:int}> $segments @var list<array{code:string}> $coupons
 * @var array<string,list<array{value:string,label:string}>> $choices @var string $label @var string $model @var array<string,string> $q
 */
$manage = Auth::can('marketing.manage');
$pct = fn (?float $v) => $v === null ? '—' : number_format($v * 100, 1) . '%';
$badge = ['active' => 'badge-soft-success', 'paused' => 'badge-soft-warning', 'ended' => 'badge-soft-muted'];
$maxFunnel = max(1, ...array_map(fn ($s) => (int) $s['count'], $funnel));
$destType = (string) fv('destination_type', 'product');
?>
<div class="wm-admin-head">
    <h1><?= e((string) $c['name']) ?> <span class="badge <?= $badge[$c['status']] ?? 'badge-soft-muted' ?> align-middle fs-6"><?= e(ucfirst((string) $c['status'])) ?></span></h1>
    <?php if ($manage): ?>
        <div class="d-flex gap-2">
            <?php foreach (['active' => 'Resume', 'paused' => 'Pause', 'ended' => 'Mark as ended'] as $st => $btn): if ($st === $c['status']) { continue; } ?>
                <form method="post" action="<?= e(url('/admin/marketing/campaigns/' . $c['id'] . '/state')) ?>"><?= csrf_field() ?>
                    <input type="hidden" name="state" value="<?= e($st) ?>"><button class="btn btn-sm btn-outline-secondary" type="submit"><?= e($btn) ?></button></form>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?= View::partial('admin/marketing/_nav', ['active' => '/admin/marketing/campaigns']) ?>
<p class="small text-muted mb-2">
    <?= e(CampaignService::OBJECTIVES[$c['objective']] ?? $c['objective']) ?> · <?= e(CampaignService::CHANNELS[$c['channel']] ?? $c['channel']) ?><?= $c['platform'] ? ' · ' . e(CampaignService::PLATFORMS[$c['platform']] ?? $c['platform']) : '' ?>
    <?= $c['starts_on'] ? ' · from ' . e(date('d M Y', strtotime((string) $c['starts_on']))) : '' ?><?= $c['ends_on'] ? ' to ' . e(date('d M Y', strtotime((string) $c['ends_on']))) : '' ?>
    <?= $c['cost'] !== null ? ' · cost ' . e(money($c['cost'])) : '' ?>
</p>
<?= View::partial('admin/marketing/_period', ['path' => '/admin/marketing/campaigns/' . $c['id'], 'q' => $q, 'model' => true]) ?>

<div class="row g-3 mb-3">
    <div class="col-lg-5">
        <section class="wm-panel h-100">
            <div class="wm-panel-head">Funnel, <?= e(strtolower($label)) ?></div>
            <div class="wm-panel-body">
                <table class="table table-sm align-middle mb-0 wm-funnel">
                    <caption class="visually-hidden">Campaign funnel</caption>
                    <thead><tr><th scope="col">Step</th><th scope="col" class="text-end">Count</th><th scope="col" class="w-50"><span class="visually-hidden">Relative size</span></th></tr></thead>
                    <tbody>
                    <?php foreach ($funnel as $s): ?>
                        <tr><th scope="row" class="fw-normal small"><?= e($s['stage']) ?></th>
                            <td class="text-end small"><?= $s['count'] === null ? '<span class="text-muted">Not available</span>' : number_format((int) $s['count']) ?></td>
                            <td aria-hidden="true"><?php if ($s['count'] !== null): ?><span class="wm-funnel-bar" style="width:<?= round((int) $s['count'] / $maxFunnel * 100, 1) ?>%"></span><?php endif; ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
    <div class="col-lg-7">
        <section class="wm-panel h-100">
            <div class="wm-panel-head">Links (A/B variants)</div>
            <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
                <caption class="visually-hidden">Promotional links of this campaign</caption>
                <thead><tr><th scope="col">Link</th><th scope="col" class="text-end">Clicks</th><th scope="col" class="text-end">QR</th><th scope="col" class="text-end">Carts</th><th scope="col" class="text-end">Orders</th><th scope="col" class="text-end">Paid sales</th><th scope="col" class="text-end">Conv.</th></tr></thead>
                <tbody>
                <?php foreach ($links as $l): $off = !$l['is_active'] || ($l['expires_at'] !== null && strtotime((string) $l['expires_at']) < time()); ?>
                    <tr>
                        <td><a href="<?= e(url('/admin/marketing/links/' . $l['id'], $q)) ?>" class="fw-semibold"><?= e((string) $l['headline']) ?></a>
                            <div class="small text-muted"><code>/go/<?= e((string) $l['code']) ?></code> · variant <?= e((string) $l['variant']) ?><?= $off ? ' · <span class="text-danger">off</span>' : '' ?></div></td>
                        <td class="text-end small"><?= number_format((int) $l['clicks']) ?></td><td class="text-end small"><?= number_format((int) $l['qr']) ?></td>
                        <td class="text-end small"><?= number_format((int) $l['carts']) ?></td><td class="text-end small"><?= number_format((int) $l['orders']) ?></td>
                        <td class="text-end small text-nowrap"><?= e(money($l['sales'])) ?></td><td class="text-end small"><?= e($pct($l['conversion'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$links): ?><tr><td colspan="7" class="text-center text-muted small py-3">No links yet<?= $manage ? ': create one below.' : '.' ?></td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </section>
    </div>
</div>

<?php if ($manage): ?>
<section class="wm-panel mb-3" id="new-link">
    <div class="wm-panel-head">Create a promotional link</div>
    <form class="wm-panel-body row g-3" method="post" action="<?= e(url('/admin/marketing/campaigns/' . $c['id'] . '/links')) ?>" novalidate>
        <?= csrf_field() ?>
        <div class="col-12"><p class="small text-muted mb-0">The link always opens a page of this shop. Headline, description and image are filled from the product (or page) if you leave them empty; prices shown in the share texts are always the current prices.</p></div>
        <div class="col-md-4">
            <label class="form-label" for="destination_type">Goes to</label>
            <select class="form-select<?= invalid('destination_type') ?>" id="destination_type" name="destination_type" data-dest-type>
                <?php foreach (CampaignService::DESTINATIONS as $k => $l): ?><option value="<?= e($k) ?>" <?= $destType === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-8">
            <label class="form-label" for="destination">Which one</label>
            <input class="form-control<?= invalid('destination') ?>" id="destination" name="destination" maxlength="200" value="<?= e((string) fv('destination', '')) ?>"
                   list="dest-<?= e($destType) ?>" data-dest-value aria-describedby="destHelp" autocomplete="off">
            <div class="form-text" id="destHelp">Start typing a name and choose it (products, categories, brands and stores), or enter search words, or a page such as /flash-sales.</div>
            <?= field_error('destination') ?>
            <?php foreach ($choices as $type => $list): ?>
                <datalist id="dest-<?= e($type) ?>"><?php foreach ($list as $o): ?><option value="<?= e($o['value']) ?>"><?= e($o['label']) ?></option><?php endforeach; ?></datalist>
            <?php endforeach; ?>
        </div>
        <div class="col-md-8">
            <label class="form-label" for="headline">Headline <span class="text-muted small">(optional)</span></label>
            <input class="form-control<?= invalid('headline') ?>" id="headline" name="headline" maxlength="120" value="<?= e((string) fv('headline', '')) ?>">
            <?= field_error('headline') ?>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="cta">Button text</label>
            <input class="form-control<?= invalid('cta') ?>" id="cta" name="cta" maxlength="40" value="<?= e((string) fv('cta', 'Shop now')) ?>">
        </div>
        <div class="col-12">
            <label class="form-label" for="description">Description <span class="text-muted small">(optional)</span></label>
            <textarea class="form-control<?= invalid('description') ?>" id="description" name="description" rows="2" maxlength="500"><?= e((string) fv('description', '')) ?></textarea>
        </div>
        <div class="col-md-3">
            <label class="form-label" for="variant">Variant</label>
            <input class="form-control<?= invalid('variant') ?>" id="variant" name="variant" maxlength="20" value="<?= e((string) fv('variant', $links ? chr(65 + min(25, count($links))) : 'A')) ?>" aria-describedby="variantHelp">
            <div class="form-text" id="variantHelp">For A/B tests: A, B…</div>
        </div>
        <div class="col-md-3">
            <label class="form-label" for="coupon_code">Coupon <span class="text-muted small">(optional)</span></label>
            <select class="form-select<?= invalid('coupon_code') ?>" id="coupon_code" name="coupon_code">
                <option value="">None</option>
                <?php foreach ($coupons as $cp): ?><option value="<?= e((string) $cp['code']) ?>" <?= fv('coupon_code', '') === $cp['code'] ? 'selected' : '' ?>><?= e((string) $cp['code']) ?></option><?php endforeach; ?>
            </select>
            <?= field_error('coupon_code') ?>
        </div>
        <div class="col-md-3">
            <label class="form-label" for="utm_source">Source tag <span class="text-muted small">(optional)</span></label>
            <input class="form-control" id="utm_source" name="utm_source" maxlength="40" value="<?= e((string) fv('utm_source', '')) ?>" placeholder="<?= e((string) ($c['platform'] ?: $c['channel'])) ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label" for="expires_at">Stops working after <span class="text-muted small">(optional)</span></label>
            <input class="form-control<?= invalid('expires_at') ?>" type="date" id="expires_at" name="expires_at" value="<?= e((string) fv('expires_at', '')) ?>">
        </div>
        <div class="col-12"><button class="btn btn-wm-green" type="submit">Create link</button></div>
    </form>
</section>

<details class="wm-panel mb-3">
    <summary class="wm-panel-head">Edit campaign details</summary>
    <form class="wm-panel-body row g-3" method="post" action="<?= e(url('/admin/marketing/campaigns/' . $c['id'])) ?>" novalidate>
        <?= csrf_field() ?>
        <?= View::partial('admin/marketing/_campaign-fields', ['c' => $c, 'segments' => $segments]) ?>
        <div class="col-12"><button class="btn btn-wm-green" type="submit">Save campaign</button></div>
    </form>
</details>
<?php endif; ?>
