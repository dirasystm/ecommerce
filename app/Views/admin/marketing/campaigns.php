<?php
use Waimart\Core\Auth;
use Waimart\Core\View;
use Waimart\Services\Marketing\CampaignService;

/**
 * @var list<array<string,mixed>> $rows @var int $total @var int $page @var int $pages @var string $search @var string $sort
 * @var string $label @var string $model @var array<string,string> $q
 */
$pct = fn (?float $v) => $v === null ? '—' : number_format($v * 100, 1) . '%';
$badge = ['active' => 'badge-soft-success', 'paused' => 'badge-soft-warning', 'ended' => 'badge-soft-muted'];
$sorts = ['recent' => 'Newest', 'name' => 'Name', 'clicks' => 'Most clicks', 'orders' => 'Most orders', 'sales' => 'Highest paid sales', 'conversion' => 'Best conversion'];
?>
<div class="wm-admin-head">
    <h1>Campaigns &amp; links</h1>
    <?php if (Auth::can('marketing.manage')): ?><a class="btn btn-wm-green" href="<?= e(url('/admin/marketing/campaigns/new')) ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i> New campaign</a><?php endif; ?>
</div>
<?= View::partial('admin/marketing/_nav', ['active' => '/admin/marketing/campaigns']) ?>
<?= View::partial('admin/marketing/_period', ['path' => '/admin/marketing/campaigns', 'q' => $q, 'model' => true, 'extra' => array_filter(['q' => $search, 'sort' => $sort !== 'recent' ? $sort : null])]) ?>
<form class="d-flex flex-wrap gap-2 mb-3" method="get" action="<?= e(url('/admin/marketing/campaigns')) ?>" role="search">
    <?php foreach ($q as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
    <label class="visually-hidden" for="cq">Search campaigns</label>
    <input class="form-control form-control-sm" type="search" id="cq" name="q" maxlength="100" placeholder="Name, channel or platform" value="<?= e($search) ?>" style="max-width:280px">
    <label class="visually-hidden" for="sort">Sort by</label>
    <select class="form-select form-select-sm" id="sort" name="sort" style="max-width:200px">
        <?php foreach ($sorts as $k => $l): ?><option value="<?= e($k) ?>" <?= $sort === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-sm btn-outline-secondary" type="submit">Apply</button>
</form>
<p class="small text-muted"><?= (int) $total ?> campaign<?= $total === 1 ? '' : 's' ?> · results for <?= e($label) ?>, sales credited to the <?= $model === 'first' ? 'first' : 'last' ?> link clicked.</p>
<?= View::partial('partials/pagination', ['page' => $page, 'pages' => $pages, 'path' => '/admin/marketing/campaigns', 'compact' => true]) ?>
<div class="wm-panel"><div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
        <caption class="visually-hidden">Campaign performance</caption>
        <thead><tr>
            <th scope="col">Campaign</th><th scope="col">Status</th><th scope="col" class="text-end">Clicks</th><th scope="col" class="text-end">Visitors (est.)</th>
            <th scope="col" class="text-end">Leads</th><th scope="col" class="text-end">Carts</th><th scope="col" class="text-end">Orders</th><th scope="col" class="text-end">Paid</th>
            <th scope="col" class="text-end">Paid sales</th><th scope="col" class="text-end">Conversion</th><th scope="col" class="text-end">Cost</th><th scope="col" class="text-end">ROAS</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><a class="fw-semibold" href="<?= e(url('/admin/marketing/campaigns/' . $r['id'], $q)) ?>"><?= e((string) $r['name']) ?></a>
                    <div class="small text-muted"><?= e(CampaignService::CHANNELS[$r['channel']] ?? $r['channel']) ?><?= $r['platform'] ? ' · ' . e(CampaignService::PLATFORMS[$r['platform']] ?? $r['platform']) : '' ?> · <?= (int) $r['links'] ?> link<?= (int) $r['links'] === 1 ? '' : 's' ?></div></td>
                <td><span class="badge <?= $badge[$r['status']] ?? 'badge-soft-muted' ?>"><?= e(ucfirst((string) $r['status'])) ?></span></td>
                <td class="text-end"><?= number_format((int) $r['clicks']) ?></td><td class="text-end"><?= number_format((int) $r['visitors']) ?></td>
                <td class="text-end"><?= number_format((int) $r['leads']) ?></td><td class="text-end"><?= number_format((int) $r['carts']) ?></td>
                <td class="text-end"><?= number_format((int) $r['orders']) ?></td><td class="text-end"><?= number_format((int) $r['paid']) ?></td>
                <td class="text-end text-nowrap"><?= e(money($r['sales'])) ?></td><td class="text-end"><?= e($pct($r['conversion'])) ?></td>
                <td class="text-end text-nowrap"><?= $r['cost'] === null ? '<span class="text-muted">—</span>' : e(money($r['cost'])) ?></td>
                <td class="text-end"><?= $r['roas'] === null ? '<span class="text-muted">—</span>' : e(number_format($r['roas'], 2)) . '×' ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="12" class="text-center text-muted py-4"><?= $search !== '' ? 'No campaigns match your search.' : 'No campaigns yet. Create one, then create promotional links to share on social media, WhatsApp, SMS or posters.' ?></td></tr><?php endif; ?>
        </tbody>
    </table>
</div></div>
<?= View::partial('partials/pagination', ['page' => $page, 'pages' => $pages, 'path' => '/admin/marketing/campaigns']) ?>
<p class="small text-muted mt-2">ROAS (return on ad spend) is shown only when you entered what the campaign actually cost. "Paid" means M-Pesa verified or cash on delivery collected.</p>
