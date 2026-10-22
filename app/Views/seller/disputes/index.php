<?php
use Waimart\Services\DisputeService;
use Waimart\Services\ReturnService;

/** @var \Waimart\Core\ListParams $lp @var string $filter @var list<array<string,mixed>> $cases @var list<array<string,mixed>> $returns */
$returnLabels = ['requested' => 'Requested', 'approved' => 'Approved — coming back', 'received' => 'Received — being inspected',
                 'completed' => 'Completed', 'rejected' => 'Not accepted', 'cancelled' => 'Cancelled'];
?>
<div class="wm-admin-head"><h1>Returns &amp; cases</h1></div>
<p class="small text-muted">Customers report problems to <?= e(brand_name()) ?>, which handles every case with you and the customer. Earnings for an order are held while it has an open return or case. Refunds <?= e(brand_name()) ?> decides are deducted in proportion from your earnings for that order, and <?= e(brand_name()) ?> gives back its commission on the refunded part.</p>

<section class="wm-panel mb-3">
    <div class="wm-panel-head">Cases
        <ul class="nav nav-pills small ms-auto">
            <?php foreach (['open' => 'Open', 'closed' => 'Closed', 'all' => 'All'] as $k => $l): ?>
                <li class="nav-item"><a class="nav-link py-1<?= $filter === $k ? ' active' : '' ?>" href="<?= e(url('/seller/disputes', ['show' => $k])) ?>"><?= e($l) ?></a></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <div class="wm-panel-body pb-0"><?= \Waimart\Core\View::partial('partials/table/toolbar', ['id' => 'scases', 'path' => '/seller/disputes', 'lp' => $lp, 'label' => 'Search cases', 'placeholder' => 'Case or order number', 'keep' => ['show' => $filter]]) ?></div>
    <div id="scases" class="wm-table-region" data-table-region data-loading-text="Loading cases…">
    <div class="table-responsive"><table class="table table-hover mb-0 align-middle">
        <caption class="visually-hidden">Cases</caption>
        <thead><tr><th scope="col">Case</th><th scope="col">Order</th><th scope="col">Customer</th><th scope="col">Problem</th><?= sort_th('Updated', 'updated', $lp, '/seller/disputes') ?><th scope="col">Status</th></tr></thead>
        <tbody>
        <?php foreach ($cases as $c): ?>
            <tr><td><a class="fw-semibold" href="<?= e(url('/seller/disputes/' . $c['id'])) ?>"><?= e($c['dispute_number']) ?></a></td>
                <td><?= e($c['sub_number']) ?></td><td><?= e($c['customer_name']) ?></td>
                <td class="small"><?= e(DisputeService::REASONS[$c['reason']] ?? $c['reason']) ?></td>
                <td class="small text-nowrap"><?= e(date('d M Y H:i', strtotime((string) $c['updated_at']))) ?></td>
                <td><span class="badge <?= $c['status'] === 'awaiting_seller' ? 'text-bg-warning' : (in_array($c['status'], DisputeService::OPEN, true) ? 'badge-soft-warning' : 'badge-soft-muted') ?>"><?= e(brand_text(DisputeService::STATUS_LABELS[$c['status']])) ?></span></td></tr>
        <?php endforeach; ?>
        <?php if (!$cases): ?><?= empty_row(6, $lp->q !== '', 'No cases here.') ?><?php endif; ?>
        </tbody>
    </table></div>
    <?= \Waimart\Core\View::partial('partials/table/footer', ['lp' => $lp, 'path' => '/seller/disputes', 'id' => 'scases', 'noun' => 'cases']) ?>
    </div>
</section>

<section class="wm-panel">
    <div class="wm-panel-head">Returns <span class="small text-muted fw-normal">latest 30 · each order page lists its returns</span></div>
    <div class="table-responsive"><table class="table table-sm mb-0 align-middle">
        <thead><tr><th scope="col">Return</th><th scope="col">Order</th><th scope="col">Reason</th><th scope="col" class="text-end">Units</th><th scope="col">Requested</th><th scope="col">Status</th><th scope="col" class="text-end">Refund</th></tr></thead>
        <tbody>
        <?php foreach ($returns as $r): ?>
            <tr><td><?= e($r['return_number']) ?></td><td><a href="<?= e(url('/seller/orders/' . $r['so_id'])) ?>"><?= e($r['sub_number']) ?></a></td>
                <td class="small"><?= e(ReturnService::REASONS[$r['reason']] ?? $r['reason']) ?></td><td class="text-end"><?= (int) $r['units'] ?></td>
                <td class="small text-nowrap"><?= e(date('d M Y', strtotime((string) $r['requested_at']))) ?></td>
                <td class="small"><?= e($returnLabels[$r['status']] ?? $r['status']) ?></td>
                <td class="text-end"><?= $r['refund_amount'] !== null ? e(money($r['refund_amount'])) : '' ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$returns): ?><tr><td colspan="7" class="text-center text-muted py-3">No returns.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</section>
