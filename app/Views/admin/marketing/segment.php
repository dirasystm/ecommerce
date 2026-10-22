<?php
use Waimart\Core\Auth;
use Waimart\Core\View;
use Waimart\Services\Marketing\LeadService;

/** @var string $code @var array{name:string,description:string,count:int} $seg @var list<array<string,mixed>> $rows @var \Waimart\Core\ListParams $lp @var bool $full */
$link = Auth::can('customers.view');
?>
<div class="wm-admin-head">
    <h1><?= e($seg['name']) ?> <span class="text-muted fs-5">(<?= number_format($seg['count']) ?>)</span></h1>
    <a class="btn btn-outline-secondary" href="<?= e(url('/admin/marketing/segments')) ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> All segments</a>
</div>
<?= View::partial('admin/marketing/_nav', ['active' => '/admin/marketing/segments']) ?>
<p class="small text-muted"><?= e($seg['description']) ?> Marketing is only ever sent to members who agreed (the last column).</p>
<div class="wm-panel"><div id="members" class="wm-table-region" data-table-region data-loading-text="Loading customers…"><div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
        <caption class="visually-hidden">Customers in this segment</caption>
        <thead><tr><th scope="col">Customer</th><th scope="col">Joined</th><th scope="col" class="text-end">Orders</th><th scope="col">Last order</th><th scope="col">Last activity</th><th scope="col">Marketing allowed</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $name = trim($r['first_name'] . ' ' . $r['last_name']); ?>
            <tr>
                <td><?= $link ? '<a href="' . e(url('/admin/customers/' . $r['id'])) . '">' . e($name) . '</a>' : e($name) ?>
                    <div class="small text-muted"><?= e($full ? (string) $r['email'] : LeadService::maskEmail($r['email'])) ?></div></td>
                <td class="small text-nowrap"><?= e(date('d M Y', strtotime((string) $r['created_at']))) ?></td>
                <td class="text-end small"><?= (int) $r['orders'] ?></td>
                <td class="small text-nowrap"><?= $r['last_order'] ? e(date('d M Y', strtotime((string) $r['last_order']))) : '—' ?></td>
                <td class="small text-nowrap"><?= $r['last_activity'] ? e(date('d M Y', strtotime((string) $r['last_activity']))) : '—' ?></td>
                <td class="small"><?= $r['marketing_email'] ? '<span class="badge badge-soft-success">Email</span> ' : '' ?><?= $r['marketing_sms'] ? '<span class="badge badge-soft-success">SMS</span>' : '' ?><?= !$r['marketing_email'] && !$r['marketing_sms'] ? '<span class="text-muted">No</span>' : '' ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><?= empty_row(6, false, 'Nobody is in this segment right now.') ?><?php endif; ?>
        </tbody>
    </table>
</div>
<?= View::partial('partials/table/footer', ['lp' => $lp, 'path' => '/admin/marketing/segments/' . $code . '', 'id' => 'members', 'noun' => 'customers']) ?>
</div></div>
