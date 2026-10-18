<?php /** @var list<array<string,mixed>> $rows */ ?>
<div class="wm-admin-head">
    <h1>Flash sales</h1>
    <a class="btn btn-wm-green" href="<?= e(url('/admin/flash-sales/new')) ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i> New flash sale</a>
</div>
<div class="wm-panel"><div class="table-responsive">
    <table class="table table-hover mb-0">
        <thead><tr><th>Title</th><th>Starts</th><th>Ends</th><th class="text-end">Items</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $s):
            $now = time(); $st = strtotime($s['starts_at']); $en = strtotime($s['ends_at']);
            $state = !(int) $s['is_active'] ? ['Off', 'badge-soft-muted'] : ($now < $st ? ['Scheduled', 'badge-soft-warning'] : ($now < $en ? ['Live', 'badge-soft-success'] : ['Ended', 'badge-soft-muted'])); ?>
            <tr>
                <td class="fw-semibold"><?= e($s['title']) ?></td>
                <td class="small text-nowrap"><?= e(date('d M Y H:i', $st)) ?></td>
                <td class="small text-nowrap"><?= e(date('d M Y H:i', $en)) ?></td>
                <td class="text-end"><?= (int) $s['items'] ?></td>
                <td><span class="badge <?= $state[1] ?>"><?= $state[0] ?></span></td>
                <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/admin/flash-sales/' . $s['id'])) ?>">Edit</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-4">No flash sales yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div></div>
<p class="small text-muted mt-2">Sale prices apply automatically between the start and end time, on the product page, in the cart and at checkout. Orders keep the price paid.</p>
