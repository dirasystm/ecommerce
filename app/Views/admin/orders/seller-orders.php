<?php
use Waimart\Core\View;
use Waimart\Services\OrderPresenter;

/** @var \Waimart\Core\ListParams $lp @var string $status @var array<string,int> $counts @var list<array<string,mixed>> $rows @var list<array<string,mixed>> $sellers */
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
<?php $sellerOpts = ['' => 'All sellers']; foreach ($sellers as $s) { $sellerOpts[(string) $s['id']] = $s['name']; } ?>
<?= View::partial('partials/table/toolbar', ['id' => 'parts', 'path' => '/admin/seller-orders', 'lp' => $lp, 'label' => 'Search seller orders', 'placeholder' => 'Part or order number, or seller',
    'keep' => ['status' => $status], 'filters' => [['name' => 'seller', 'label' => 'Seller', 'value' => $sellerQ, 'options' => $sellerOpts]]]) ?>
<div class="wm-panel"><div id="parts" class="wm-table-region" data-table-region data-loading-text="Fetching orders…"><div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
        <caption class="visually-hidden">Seller orders</caption>
        <thead><tr><th scope="col">Part</th><th scope="col">Seller</th><th scope="col">Fulfilment</th><?= sort_th('Value', 'value', $lp, '/admin/seller-orders', 'text-end') ?><?= sort_th('Since', 'since', $lp, '/admin/seller-orders') ?><th scope="col">Seller's note</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
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
        <?php if (!$rows): ?><?= empty_row(7, $lp->q !== '' || $sellerQ !== '', 'No seller orders here.') ?><?php endif; ?>
        </tbody>
    </table>
</div>
<?= \Waimart\Core\View::partial('partials/table/footer', ['lp' => $lp, 'path' => '/admin/seller-orders', 'id' => 'parts', 'noun' => 'parts']) ?>
</div></div>
