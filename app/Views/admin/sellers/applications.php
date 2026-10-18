<?php /** @var string $status @var list<array<string,mixed>> $rows @var array<string,int> $counts */ ?>
<div class="wm-admin-head">
    <h1>Seller applications</h1>
    <a class="btn btn-outline-secondary" href="<?= e(url('/admin/sellers')) ?>"><i class="bi bi-shop" aria-hidden="true"></i> All sellers</a>
</div>
<ul class="nav nav-pills mb-3 small">
    <?php foreach (['submitted' => 'Waiting', 'under_review' => 'Under review', 'approved' => 'Approved', 'rejected' => 'Rejected', 'draft' => 'Drafts'] as $k => $l): ?>
        <li class="nav-item"><a class="nav-link<?= $status === $k ? ' active' : '' ?>" href="<?= e(url('/admin/sellers/applications', ['status' => $k])) ?>"><?= e($l) ?> <span class="badge text-bg-light"><?= (int) ($counts[$k] ?? 0) ?></span></a></li>
    <?php endforeach; ?>
</ul>
<div class="wm-panel"><div class="table-responsive">
    <table class="table table-hover mb-0">
        <thead><tr><th>Application</th><th>Store</th><th>Type</th><th>Location</th><th class="text-end">Documents</th><th>Submitted</th><th></th></tr></thead>
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
        <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">No applications here.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div></div>
