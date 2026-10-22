<?php
use Waimart\Services\PickupApplicationService as P;

/** @var \Waimart\Core\ListParams $lp @var list<array<string,mixed>> $rows @var array<string,int> $counts @var string $status @var string $search */
$tabs = ['pending' => 'Being reviewed', 'approved' => 'Approved', 'rejected' => 'Not approved', 'all' => 'All'];
$badge = ['pending' => 'badge-soft-warning', 'approved' => 'badge-soft-success', 'rejected' => 'badge-soft-danger'];
?>
<div class="wm-admin-head">
    <h1>Pickup station applications</h1>
    <a class="btn btn-outline-secondary" href="<?= e(url('/become-a-pickup-station')) ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> Public application page</a>
</div>
<p class="small text-muted">Businesses apply on the public page. Verify each one (call the contact person, check the location and, if needed, visit), then approve it to create the pickup station, or reject it with a reason. The business is told by email and SMS either way.</p>
<ul class="nav nav-pills mb-3 small">
    <?php foreach ($tabs as $k => $l): ?>
        <li class="nav-item"><a class="nav-link<?= $status === $k ? ' active' : '' ?>" href="<?= e(url('/admin/pickup-points/applications', array_filter(['status' => $k, 'q' => $search]))) ?>"<?= $status === $k ? ' aria-current="page"' : '' ?>>
            <?= e($l) ?><?= isset($counts[$k]) ? ' <span class="badge ' . ($k === 'pending' && $counts[$k] ? 'text-bg-warning' : 'text-bg-light') . '">' . (int) $counts[$k] . '</span>' : '' ?></a></li>
    <?php endforeach; ?>
</ul>
<?= \Waimart\Core\View::partial('partials/table/toolbar', ['id' => 'apps', 'path' => '/admin/pickup-points/applications', 'lp' => $lp, 'label' => 'Search applications',
    'placeholder' => 'Reference, business, town, county, contact or phone', 'keep' => ['status' => $status]]) ?>
<div class="wm-panel"><div id="apps" class="wm-table-region" data-table-region data-loading-text="Loading applications…"><div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
        <caption class="visually-hidden">Pickup station applications</caption>
        <thead><tr><th scope="col">Reference</th><th scope="col">Business</th><th scope="col">Location</th><th scope="col">Contact</th><?= sort_th('Applied', 'applied', $lp, '/admin/pickup-points/applications') ?><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $a): ?>
            <tr>
                <td><code><?= e((string) $a['reference']) ?></code></td>
                <td><span class="fw-semibold"><?= e((string) $a['business_name']) ?></span><div class="small text-muted"><?= e(P::BUSINESS_TYPES[$a['business_type']] ?? (string) $a['business_type']) ?></div></td>
                <td class="small"><?= e((string) $a['town']) ?>, <?= e((string) $a['county_name']) ?></td>
                <td class="small"><?= e((string) $a['contact_name']) ?><div class="text-muted"><?= e(\Waimart\Core\Validator::displayPhone((string) $a['phone'])) ?></div></td>
                <td class="small text-nowrap"><?= e(date('d M Y', strtotime((string) $a['created_at']))) ?></td>
                <td><span class="badge <?= $badge[$a['status']] ?? 'badge-soft-muted' ?>"><?= e(P::STATUSES[$a['status']] ?? (string) $a['status']) ?></span></td>
                <td class="text-end"><a class="btn btn-sm <?= $a['status'] === 'pending' ? 'btn-wm-green' : 'btn-outline-secondary' ?>" href="<?= e(url('/admin/pickup-points/applications/' . $a['id'])) ?>"><?= $a['status'] === 'pending' ? 'Review' : 'View' ?></a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><?= empty_row(7, $lp->q !== '', $status === 'pending' ? 'No applications waiting for review.' : 'No applications found.') ?><?php endif; ?>
        </tbody>
    </table>
</div>
<?= \Waimart\Core\View::partial('partials/table/footer', ['lp' => $lp, 'path' => '/admin/pickup-points/applications', 'id' => 'apps', 'noun' => 'applications']) ?>
</div></div>
