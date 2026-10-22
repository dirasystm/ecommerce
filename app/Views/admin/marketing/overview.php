<?php
use Waimart\Core\Auth;
use Waimart\Core\View;
use Waimart\Services\Marketing\Analytics;
use Waimart\Services\Marketing\CampaignService;

/**
 * @var array<string,mixed> $ov @var list<array{stage:string,count:?int}> $funnel @var list<array<string,string>> $insights
 * @var list<array<string,mixed>> $channels @var list<array<string,mixed>> $products @var list<array<string,mixed>> $placements
 * @var string $label @var string $model @var array<string,string> $q @var bool $paused
 */
$pct = fn (?float $v, int $dp = 1) => $v === null ? '—' : number_format($v * 100, $dp) . '%';
$num = fn ($v) => number_format((int) $v);
$def = Analytics::DEFINITIONS;
$cards = [
    ['Promotional clicks', $num($ov['clicks']), $num($ov['visitors']) . ' unique visitors (est.) · ' . $num($ov['qr']) . ' from QR codes', 'clicks'],
    ['Leads', $num($ov['leads']), $num($ov['registrations']) . ' new accounts (' . $num($ov['registrations_attr']) . ' after a promotional link)', 'leads'],
    ['Add to cart', $num($ov['carts']), $num($ov['carts_attr']) . ' after a promotional link · ' . $num($ov['checkouts']) . ' checkouts started', 'carts'],
    ['Orders placed', $num($ov['orders']), $num($ov['paid']) . ' paid · ' . $num($ov['cancelled']) . ' cancelled · ' . $num($ov['refunded']) . ' refunded/returned', 'orders'],
    ['Paid sales', money($ov['sales']), 'All paid orders placed in the period', 'sales'],
    ['Sales from promotional links', money($ov['sales_attr']), $num($ov['orders_attr']) . ' orders, ' . $num($ov['paid_attr']) . ' paid (' . ($model === 'first' ? 'first' : 'last') . ' link clicked)', 'sales'],
    ['Conversion from links', $pct($ov['conversion']), 'Orders ÷ unique visitors from promotional links', 'conversion'],
    ['Return on ad spend', $ov['roas'] === null ? '—' : number_format($ov['roas'], 2) . '×', $ov['cost'] === null ? 'Enter the actual cost on a campaign to see this' : 'Cost entered: ' . money($ov['cost']), 'roas'],
    ['Marketing emails accepted', $pct($ov['email_rate']), 'By the mail server (opens are not tracked)', 'email_rate'],
    ['Marketing SMS delivered', $pct($ov['sms_rate']), 'Where Talksasa delivery reports were checked', 'sms_rate'],
    ['Unsubscribes', $num($ov['unsubs']), $pct($ov['unsub_rate']) . ' of ' . $num($ov['messages']) . ' marketing messages', 'unsub_rate'],
    ['Campaigns', $num($ov['campaigns']), $num($ov['active']) . ' active', null],
];
$maxFunnel = max(1, ...array_map(fn ($s) => (int) $s['count'], $funnel));
?>
<div class="wm-admin-head">
    <h1>Marketing Intelligence</h1>
    <?php if (Auth::can('marketing.manage')): ?><a class="btn btn-wm-green" href="<?= e(url('/admin/marketing/campaigns/new')) ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i> New campaign</a><?php endif; ?>
</div>
<?= View::partial('admin/marketing/_nav', ['active' => '/admin/marketing']) ?>
<?php if ($paused): ?><div class="alert alert-warning small"><strong>All marketing messages are paused.</strong> Order and account messages still go out. <a href="<?= e(url('/admin/marketing/settings')) ?>">Settings</a></div><?php endif; ?>
<?= View::partial('admin/marketing/_period', ['path' => '/admin/marketing', 'q' => $q, 'model' => true]) ?>
<p class="small text-muted">Showing <strong><?= e($label) ?></strong> (Kenya time). Every figure is counted from recorded activity on this shop; nothing is estimated except where it says "est.". Impressions on social platforms are not available to this shop and are never shown as numbers.</p>

<div class="row g-3 mb-3">
    <?php foreach ($cards as [$title, $value, $sub, $key]): ?>
        <div class="col-6 col-lg-4 col-xl-3"><div class="wm-panel wm-stat h-100">
            <div class="wm-stat-label"><?= e($title) ?></div>
            <div class="wm-stat-value"><?= e($value) ?></div>
            <div class="small text-muted"><?= e($sub) ?></div>
        </div></div>
    <?php endforeach; ?>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <section class="wm-panel h-100">
            <div class="wm-panel-head">Promotional traffic funnel</div>
            <div class="wm-panel-body">
                <table class="table table-sm align-middle mb-2 wm-funnel">
                    <caption class="visually-hidden">From promotional link click to paid order, <?= e($label) ?></caption>
                    <thead><tr><th scope="col">Step</th><th scope="col" class="text-end">Count</th><th scope="col" class="w-50"><span class="visually-hidden">Relative size</span></th></tr></thead>
                    <tbody>
                    <?php foreach ($funnel as $s): ?>
                        <tr>
                            <th scope="row" class="fw-normal small"><?= e($s['stage']) ?></th>
                            <td class="text-end small"><?= $s['count'] === null ? '<span class="text-muted">Not available</span>' : e($num($s['count'])) ?></td>
                            <td aria-hidden="true"><?php if ($s['count'] !== null): ?><span class="wm-funnel-bar" style="width:<?= round((int) $s['count'] / $maxFunnel * 100, 1) ?>%"></span><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="small text-muted mb-0">Impressions come from the advertising platforms (Facebook, TikTok…) and are not shared with this shop. Read them in the platform's own reports.</p>
            </div>
        </section>
    </div>
    <div class="col-lg-6">
        <section class="wm-panel h-100">
            <div class="wm-panel-head"><i class="bi bi-lightbulb" aria-hidden="true"></i> Optimization assistant</div>
            <div class="wm-panel-body">
                <?php if (!$insights): ?>
                    <p class="small text-muted mb-0">No suggestions yet. Suggestions appear once campaigns have enough clicks and visitors to compare (at least 20 clicks or 30 visitors); small numbers are labelled as early signals.</p>
                <?php endif; ?>
                <?php foreach ($insights as $i): ?>
                    <div class="border-start border-3 ps-3 mb-3 border-<?= e($i['tone'] === 'success' ? 'success' : ($i['tone'] === 'warning' ? 'warning' : 'info')) ?>">
                        <div class="fw-semibold small"><i class="bi bi-<?= e($i['tone'] === 'success' ? 'check-circle' : ($i['tone'] === 'warning' ? 'exclamation-triangle' : 'info-circle')) ?>" aria-hidden="true"></i> <?= e($i['title']) ?></div>
                        <div class="small"><?= e($i['text']) ?></div>
                        <div class="small text-muted"><strong>Suggestion:</strong> <?= e($i['action']) ?></div>
                    </div>
                <?php endforeach; ?>
                <p class="small text-muted mb-0">These are rules applied to the measured numbers, not predictions. A difference can be chance when numbers are small.</p>
            </div>
        </section>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-xl-6">
        <section class="wm-panel h-100">
            <div class="wm-panel-head">By channel and platform</div>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                <caption class="visually-hidden">Results by channel and platform</caption>
                <thead><tr><th scope="col">Channel</th><th scope="col" class="text-end">Clicks</th><th scope="col" class="text-end">Visitors (est.)</th><th scope="col" class="text-end">Orders</th><th scope="col" class="text-end">Paid sales</th><th scope="col" class="text-end">Conversion</th></tr></thead>
                <tbody>
                <?php foreach ($channels as $c): ?>
                    <tr>
                        <td class="small"><?= e(CampaignService::CHANNELS[$c['channel']] ?? $c['channel']) ?><?= $c['platform'] ? ' · ' . e(CampaignService::PLATFORMS[$c['platform']] ?? $c['platform']) : '' ?></td>
                        <td class="text-end small"><?= $num($c['clicks']) ?></td><td class="text-end small"><?= $num($c['visitors']) ?></td>
                        <td class="text-end small"><?= $num($c['orders']) ?></td><td class="text-end small"><?= e(money($c['sales'])) ?></td><td class="text-end small"><?= e($pct($c['conversion'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$channels): ?><tr><td colspan="6" class="text-center text-muted small py-3">No campaigns yet.</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </section>
    </div>
    <div class="col-xl-6">
        <section class="wm-panel h-100">
            <div class="wm-panel-head">Advertised products</div>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                <caption class="visually-hidden">Products with promotional links</caption>
                <thead><tr><th scope="col">Product</th><th scope="col" class="text-end">Clicks</th><th scope="col" class="text-end">Leads</th><th scope="col" class="text-end">Orders with it</th></tr></thead>
                <tbody>
                <?php foreach ($products as $p): ?>
                    <tr><td class="small"><a href="<?= e(url('/product/' . $p['slug'])) ?>" target="_blank" rel="noopener"><?= e((string) $p['name']) ?></a></td>
                        <td class="text-end small"><?= $num($p['clicks']) ?></td><td class="text-end small"><?= $num($p['leads']) ?></td><td class="text-end small"><?= $num($p['orders']) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$products): ?><tr><td colspan="4" class="text-center text-muted small py-3">No product links yet.</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </section>
    </div>
</div>

<section class="wm-panel mb-3">
    <div class="wm-panel-head d-flex justify-content-between align-items-center">Recommendations <a class="small" href="<?= e(url('/admin/marketing/recommendations', $q)) ?>">Details</a></div>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <caption class="visually-hidden">Recommendation placements</caption>
        <thead><tr><th scope="col">Placement</th><th scope="col" class="text-end">Products shown</th><th scope="col" class="text-end">Clicks</th><th scope="col" class="text-end">Click rate</th><th scope="col" class="text-end">Orders</th><th scope="col" class="text-end">Value of recommended items ordered</th></tr></thead>
        <tbody>
        <?php foreach ($placements as $p): ?>
            <tr><td class="small"><?= e($p['label']) ?></td><td class="text-end small"><?= $num($p['shown']) ?></td><td class="text-end small"><?= $num($p['clicks']) ?></td>
                <td class="text-end small"><?= e($pct($p['ctr'], 2)) ?></td><td class="text-end small"><?= $num($p['orders']) ?></td><td class="text-end small"><?= e(money($p['value'])) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>

<details class="wm-panel wm-panel-body small">
    <summary class="fw-semibold">How each number is measured</summary>
    <dl class="row mb-0 mt-2">
        <?php foreach ($def as $k => $text): ?>
            <dt class="col-sm-3"><?= e(ucfirst(str_replace('_', ' ', $k))) ?></dt><dd class="col-sm-9"><?= e($text) ?></dd>
        <?php endforeach; ?>
    </dl>
</details>
