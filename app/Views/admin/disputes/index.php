<?php
use Waimart\Services\DisputeService;

/** @var string $filter @var list<array<string,mixed>> $rows @var \Waimart\Core\ListParams $lp */
?>
<div class="wm-admin-head"><h1>Seller cases</h1></div>
<ul class="nav nav-pills mb-3 small">
    <?php foreach (['open' => 'Open', 'closed' => 'Closed', 'all' => 'All'] as $k => $l): ?>
        <li class="nav-item"><a class="nav-link<?= $filter === $k ? ' active' : '' ?>" href="<?= e(url('/admin/disputes', ['show' => $k])) ?>"><?= e($l) ?></a></li>
    <?php endforeach; ?>
</ul>
<p class="small text-muted">A case concerns one seller's part of an order. While it is open, that seller's earnings for the part are held.</p>
<?= \Waimart\Core\View::partial('partials/table/toolbar', ['id' => 'cases', 'path' => '/admin/disputes', 'lp' => $lp, 'label' => 'Search seller cases',
    'placeholder' => 'Case, order or part number, seller or customer', 'keep' => ['show' => $filter]]) ?>
<div class="wm-panel">
    <div id="cases" class="wm-table-region" data-table-region data-loading-text="Loading cases…">
        <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
        <caption class="visually-hidden">Seller cases</caption>
        <thead><tr><th scope="col">Case</th><th scope="col">Order</th><th scope="col">Seller</th><th scope="col">Customer</th><th scope="col">Problem</th><?= sort_th('Updated', 'updated', $lp, '/admin/disputes') ?><th scope="col">Status</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $c): ?>
            <tr><td><a class="fw-semibold" href="<?= e(url('/admin/disputes/' . $c['id'])) ?>"><?= e($c['dispute_number']) ?></a></td>
                <td><?= e($c['sub_number']) ?></td><td><?= e($c['seller_name']) ?></td><td class="small"><?= e($c['customer_name']) ?></td>
                <td class="small"><?= e(DisputeService::REASONS[$c['reason']] ?? $c['reason']) ?></td>
                <td class="small text-nowrap"><?= e(date('d M Y H:i', strtotime((string) $c['updated_at']))) ?></td>
                <td><span class="badge <?= $c['status'] === 'open' ? 'text-bg-warning' : (in_array($c['status'], DisputeService::OPEN, true) ? 'badge-soft-warning' : 'badge-soft-muted') ?>"><?= e(brand_text(DisputeService::STATUS_LABELS[$c['status']])) ?></span></td></tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><?= empty_row(7, $lp->q !== '', 'No cases here.') ?><?php endif; ?>
        </tbody>
    </table>
</div>
        <?= \Waimart\Core\View::partial('partials/table/footer', ['lp' => $lp, 'path' => '/admin/disputes', 'id' => 'cases', 'noun' => 'cases']) ?>
    </div>
</div>
