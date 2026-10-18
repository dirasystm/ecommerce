<?php /** @var list<array<string,mixed>> $rows @var string $q */ ?>
<div class="wm-admin-head"><h1>Customers</h1></div>
<form class="d-flex gap-2 mb-3" method="get" action="<?= e(url('/admin/customers')) ?>">
    <label class="visually-hidden" for="q">Search customers</label>
    <input class="form-control form-control-sm" id="q" name="q" value="<?= e($q) ?>" placeholder="Name, email or phone" style="max-width:320px">
    <button class="btn btn-sm btn-outline-secondary" type="submit">Search</button>
</form>
<div class="wm-panel"><div class="table-responsive">
    <table class="table table-hover table-sm mb-0 wm-num">
        <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th class="text-end">Orders</th><th class="text-end">Booked value</th><th>Joined</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $c): ?>
            <tr><td class="fw-semibold"><?= e($c['first_name'] . ' ' . $c['last_name']) ?></td><td class="small"><?= e($c['email']) ?></td>
                <td class="small"><?= $c['phone'] ? e(\Waimart\Core\Validator::displayPhone((string) $c['phone'])) : '' ?></td>
                <td class="text-end"><?= (int) $c['orders'] ?></td><td class="text-end"><?= e(money((string) $c['spent'])) ?></td>
                <td class="small"><?= e(date('d M Y', strtotime($c['created_at']))) ?></td>
                <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/admin/customers/' . $c['id'])) ?>">View</a></td></tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">No customers found.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div></div>
<p class="small text-muted mt-2">Guest orders are not linked to accounts and appear only under Orders. Viewing a customer's details is recorded in the audit log.</p>
