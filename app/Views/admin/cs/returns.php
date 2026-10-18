<?php /** @var list<array<string,mixed>> $rows @var string $status @var array<string,string> $reasons */ ?>
<div class="wm-admin-head"><h1>Returns</h1></div>
<ul class="nav nav-pills mb-3 small">
    <?php foreach (['open' => 'Open', 'completed' => 'Completed', 'rejected' => 'Rejected', 'all' => 'All'] as $k => $l): ?>
        <li class="nav-item"><a class="nav-link<?= $status === $k ? ' active' : '' ?>" href="<?= e(url('/admin/returns', ['status' => $k])) ?>"><?= e($l) ?></a></li>
    <?php endforeach; ?>
</ul>
<div class="wm-panel"><div class="table-responsive">
    <table class="table table-hover mb-0">
        <thead><tr><th>Return</th><th>Order</th><th>Customer</th><th>Reason</th><th class="text-end">Units</th><th>Requested</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><a class="fw-semibold" href="<?= e(url('/admin/returns/' . $r['id'])) ?>"><?= e($r['return_number']) ?></a></td>
                <td><?= e($r['order_number']) ?></td><td class="small"><?= e($r['customer_name']) ?></td>
                <td class="small"><?= e($reasons[$r['reason']] ?? $r['reason']) ?></td><td class="text-end"><?= (int) $r['units'] ?></td>
                <td class="small text-nowrap"><?= e(date('d M H:i', strtotime($r['requested_at']))) ?></td>
                <td><span class="badge <?= ['completed' => 'badge-soft-success', 'rejected' => 'badge-soft-danger'][$r['status']] ?? 'badge-soft-warning' ?>"><?= e($r['status']) ?></span></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">No returns.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div></div>
