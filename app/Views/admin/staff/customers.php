<?php /** @var list<array<string,mixed>> $rows @var \Waimart\Core\ListParams $lp @var string $status */ ?>
<div class="wm-admin-head"><h1>Customers</h1></div>
<?= \Waimart\Core\View::partial('partials/table/toolbar', ['id' => 'customers', 'path' => '/admin/customers', 'lp' => $lp, 'label' => 'Search customers', 'placeholder' => 'Name, email or phone', 'filters' => [['name' => 'status', 'label' => 'Status', 'value' => $status, 'options' => ['' => 'Any status', 'active' => 'Active', 'suspended' => 'Suspended']]]]) ?>
<div class="wm-panel"><div id="customers" class="wm-table-region" data-table-region data-loading-text="Loading customers…"><div class="table-responsive">
    <table class="table table-hover table-sm mb-0 wm-num align-middle">
        <caption class="visually-hidden">Customers</caption>
        <thead><tr><?= sort_th('Name', 'name', $lp, '/admin/customers') ?><th scope="col">Email</th><th scope="col">Phone</th><?= sort_th('Orders', 'orders', $lp, '/admin/customers', 'text-end') ?><?= sort_th('Booked value', 'spent', $lp, '/admin/customers', 'text-end') ?><?= sort_th('Joined', 'joined', $lp, '/admin/customers') ?><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $c): ?>
            <tr><td class="fw-semibold"><?= e($c['first_name'] . ' ' . $c['last_name']) ?></td><td class="small"><?= e($c['email']) ?></td>
                <td class="small"><?= $c['phone'] ? e(\Waimart\Core\Validator::displayPhone((string) $c['phone'])) : '' ?></td>
                <td class="text-end"><?= (int) $c['orders'] ?></td><td class="text-end"><?= e(money((string) $c['spent'])) ?></td>
                <td class="small"><?= e(date('d M Y', strtotime($c['created_at']))) ?></td>
                <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/admin/customers/' . $c['id'])) ?>">View</a></td></tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><?= empty_row(7, $lp->q !== '' || $status !== '', 'No customers yet.') ?><?php endif; ?>
        </tbody>
    </table>
</div>
<?= \Waimart\Core\View::partial('partials/table/footer', ['lp' => $lp, 'path' => '/admin/customers', 'id' => 'customers', 'noun' => 'customers']) ?>
</div></div>
<p class="small text-muted mt-2">Guest orders are not linked to accounts and appear only under Orders. Viewing a customer's details is recorded in the audit log.</p>
