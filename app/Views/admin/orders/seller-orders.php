<?php
use Waimart\Core\View;
use Waimart\Services\OrderPresenter;

/** @var string $status @var array<string,int> $counts @var list<array<string,mixed>> $rows @var list<array<string,mixed>> $sellers */
$req = View::shared('request');
$sellerQ = (string) ($req->query['seller'] ?? '');
$tabs = ['pending_acceptance', 'accepted', 'processing', 'packed', 'handed_over', 'dispatched', 'out_for_delivery', 'ready_for_collection', 'delivered', 'collected', 'rejected', 'cancelled'];
?>
<div class="wm-admin-head"><h1>Seller orders</h1></div>
<p class="small text-muted">Every seller's part of confirmed orders. "Packed" parts are waiting for <?= e(brand_name()) ?> to receive them from the seller.</p>
<ul class="nav nav-pills mb-3 small flex-wrap">
    <?php foreach ($tabs as $k): ?>
        <li class="nav-item"><a class="nav-link<?= $status === $k ? ' active' : '' ?>" href="<?= e(url('/admin/seller-orders', array_filter(['status' => $k, 'seller' => $sellerQ]))) ?>"><?= e(OrderPresenter::sellerOrderStatus($k)) ?>
            <span class="badge text-bg-light"><?= (int) ($counts[$k] ?? 0) ?></span></a></li>
    <?php endforeach; ?>
</ul>
<form class="mb-3 d-flex gap-2 align-items-end" method="get" action="<?= e(url('/admin/seller-orders')) ?>">
    <input type="hidden" name="status" value="<?= e($status) ?>">
    <div><label class="form-label small mb-1" for="seller">Seller</label>
        <select class="form-select form-select-sm" id="seller" name="seller"><option value="">All sellers</option>
            <?php foreach ($sellers as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $sellerQ === (string) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
        </select></div>
    <button class="btn btn-sm btn-outline-secondary" type="submit">Filter</button>
</form>
<div class="wm-panel"><div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
        <thead><tr><th>Part</th><th>Seller</th><th>Fulfilment</th><th class="text-end">Value</th><th>Since</th><th>Seller's note</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td class="text-nowrap"><code><?= e($r['sub_number']) ?></code></td>
                <td><a href="<?= e(url('/admin/sellers/' . $r['seller_id'])) ?>"><?= e($r['seller_name']) ?></a></td>
                <td class="small"><?= $r['fulfilment_method'] === 'pickup' ? 'Pickup · ' . e((string) $r['pickup_name']) : 'Delivery · ' . e(trim($r['town'] . ', ' . $r['county_name'], ', ')) ?></td>
                <td class="text-end text-nowrap"><?= e(money($r['items_subtotal'])) ?></td>
                <td class="small text-nowrap"><?= e(date('d M H:i', strtotime((string) $r['updated_at']))) ?></td>
                <td class="small"><?= e((string) $r['dispatch_note']) ?></td>
                <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/admin/orders/' . $r['order_id'])) ?>">Open order</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">Nothing here.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div></div>
