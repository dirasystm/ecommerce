<?php
use Waimart\Core\Auth;
use Waimart\Core\View;
use Waimart\Services\Marketing\CampaignService;

/**
 * @var list<array<string,mixed>> $rows @var \Waimart\Core\ListParams $lp @var string $search @var string $sort
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
<?= View::partial('partials/table/toolbar', ['id' => 'campaigns', 'path' => '/admin/marketing/campaigns', 'lp' => $lp, 'label' => 'Search campaigns',
    'placeholder' => 'Name, channel or platform', 'keep' => $q,
    'filters' => [['name' => 'sort', 'label' => 'Sort by', 'value' => $sort, 'default' => 'recent', 'options' => $sorts]]]) ?>
<p class="small text-muted">Results for <?= e($label) ?>, sales credited to the <?= $model === 'first' ? 'first' : 'last' ?> link clicked.</p>
<div class="wm-panel"><div id="campaigns" class="wm-table-region" data-table-region data-loading-text="Loading campaigns…"><div class="table-responsive">
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
        <?php if (!$rows): ?><?= empty_row(12, $search !== '', 'No campaigns yet. Create one, then create promotional links to share on social media, WhatsApp, SMS or posters.') ?><?php endif; ?>
        </tbody>
    </table>
</div>
<?= View::partial('partials/table/footer', ['lp' => $lp, 'path' => '/admin/marketing/campaigns', 'id' => 'campaigns', 'noun' => 'campaigns']) ?>
</div></div>
<p class="small text-muted mt-2">ROAS (return on ad spend) is shown only when you entered what the campaign actually cost. "Paid" means M-Pesa verified or cash on delivery collected.</p>
