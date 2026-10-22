<?php /** @var \Waimart\Core\ListParams $lp @var string $status @var list<array<string,mixed>> $rows @var array<string,int> $counts */ ?>
<div class="wm-admin-head">
    <h1>Seller applications</h1>
    <a class="btn btn-outline-secondary" href="<?= e(url('/admin/sellers')) ?>"><i class="bi bi-shop" aria-hidden="true"></i> All sellers</a>
</div>
<ul class="nav nav-pills mb-3 small">
    <?php foreach (['submitted' => 'Waiting', 'under_review' => 'Under review', 'approved' => 'Approved', 'rejected' => 'Rejected', 'draft' => 'Drafts'] as $k => $l): ?>
        <li class="nav-item"><a class="nav-link<?= $status === $k ? ' active' : '' ?>" href="<?= e(url('/admin/sellers/applications', ['status' => $k])) ?>"><?= e($l) ?> <span class="badge text-bg-light"><?= (int) ($counts[$k] ?? 0) ?></span></a></li>
    <?php endforeach; ?>
</ul>
<?= \Waimart\Core\View::partial('partials/table/toolbar', ['id' => 'sapps', 'path' => '/admin/sellers/applications', 'lp' => $lp, 'label' => 'Search seller applications', 'placeholder' => 'Application number, store or county', 'keep' => ['status' => $status]]) ?>
<div class="wm-panel"><div id="sapps" class="wm-table-region" data-table-region data-loading-text="Loading applications…"><div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
        <caption class="visually-hidden">Seller applications</caption>
        <thead><tr><th scope="col">Application</th><?= sort_th('Store', 'store', $lp, '/admin/sellers/applications') ?><th scope="col">Type</th><th scope="col">Location</th><th scope="col" class="text-end">Documents</th><?= sort_th('Submitted', 'submitted', $lp, '/admin/sellers/applications') ?><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $a): ?>
            <tr>
                <td class="text-nowrap"><code><?= e($a['application_number']) ?></code></td>
                <td class="fw-semibold"><?= e($a['store_name']) ?></td>
                <td class="small"><?= $a['business_type'] === 'registered_business' ? 'Business' : 'Individual' ?></td>
                <td class="small"><?= e($a['county_name']) ?></td>
                <td class="text-end"><?= (int) $a['documents'] ?></td>
                <td class="small text-nowrap"><?= $a['submitted_at'] ? e(date('d M Y H:i', strtotime($a['submitted_at']))) : '—' ?></td>
                <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/admin/sellers/applications/' . $a['id'])) ?>">Review</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><?= empty_row(7, $lp->q !== '', 'No applications here.') ?><?php endif; ?>
        </tbody>
    </table>
</div>
<?= \Waimart\Core\View::partial('partials/table/footer', ['lp' => $lp, 'path' => '/admin/sellers/applications', 'id' => 'sapps', 'noun' => 'applications']) ?>
</div></div>
