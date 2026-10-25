<?php
use Waimart\Core\Barcode;
use Waimart\Core\Money;
use Waimart\Core\Validator;

/**
 * Package labels, one per page: 100 x 150 mm stickers (the usual shipping label), and they also print on A4.
 * @var list<array<string,mixed>> $orders
 */
$store = brand_name();
$storePhone = (string) \Waimart\Core\Settings::get('store_phone', '');
?><!doctype html>
<html lang="en-KE">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= count($orders) === 1 ? 'Label ' . e($orders[0]['order_number']) : count($orders) . ' package labels' ?> | <?= e($store) ?></title>
<style>
    @page { size: 100mm 150mm; margin: 0; }
    * { box-sizing: border-box; }
    body { margin: 0; background: #e9ecea; font-family: Arial, Helvetica, sans-serif; color: #000; }
    .bar { position: sticky; top: 0; display: flex; gap: 10px; align-items: center; justify-content: center; padding: 10px; background: #fff; border-bottom: 1px solid #ccd3cf; font-size: 14px; }
    .bar button, .bar a { font: inherit; padding: 7px 16px; border-radius: 6px; border: 1px solid #005b3d; background: #005b3d; color: #fff; cursor: pointer; text-decoration: none; }
    .bar a { background: #fff; color: #005b3d; }
    .sheet { width: 100mm; height: 150mm; margin: 12px auto; background: #fff; padding: 4mm; display: flex; flex-direction: column; gap: 2mm; overflow: hidden;
             box-shadow: 0 2px 10px rgba(0,0,0,.18); page-break-after: always; break-after: page; }
    .sheet:last-child { page-break-after: auto; break-after: auto; }
    .top { display: flex; align-items: center; justify-content: space-between; border-bottom: .6mm solid #000; padding-bottom: 2mm; }
    .top img { max-height: 9mm; max-width: 45mm; }
    .top .store { font-weight: 700; font-size: 13pt; }
    .kind { font-weight: 800; font-size: 12pt; border: .6mm solid #000; padding: .6mm 2mm; letter-spacing: .04em; }
    .label { font-size: 6.5pt; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: #333; }
    .dest { border-bottom: .3mm solid #000; padding-bottom: 2mm; }
    .dest .name { font-size: 15pt; font-weight: 800; line-height: 1.12; }
    .dest .sub { font-size: 9pt; margin-top: .6mm; }
    .who { display: grid; grid-template-columns: 22mm 1fr; row-gap: .8mm; font-size: 10pt; border-bottom: .3mm solid #000; padding-bottom: 2mm; }
    .who b { font-size: 11pt; }
    .items { font-size: 8.5pt; line-height: 1.3; flex: 1; overflow: hidden; }
    .items ul { margin: .8mm 0 0; padding-left: 4mm; }
    .pay { font-weight: 800; font-size: 11pt; text-align: center; padding: 1.2mm; border: .5mm solid #000; }
    .pay.cod { background: #000; color: #fff; }
    .code { text-align: center; }
    .code svg { width: 100%; height: 19mm; display: block; }
    .code .num { font-family: "Courier New", monospace; font-size: 13pt; font-weight: 700; letter-spacing: .12em; margin-top: .8mm; }
    .foot { font-size: 6.5pt; text-align: center; color: #222; }
    @media print {
        body { background: #fff; }
        .bar { display: none; }
        .sheet { margin: 0; box-shadow: none; }
    }
</style>
</head>
<body>
<div class="bar">
    <span><?= count($orders) === 1 ? 'Package label for ' . e($orders[0]['order_number']) : count($orders) . ' package labels' ?> (100 × 150 mm sticker; also prints on A4)</span>
    <button type="button" data-print>Print</button>
    <a href="<?= e(url(count($orders) === 1 ? '/admin/orders/' . $orders[0]['id'] : '/admin/collections?tab=arriving')) ?>">Back</a>
</div>
<?php foreach ($orders as $o):
    $pickup = $o['method'] === 'pickup';
    $items = $o['items'];
    $shown = array_slice($items, 0, 6);
    $units = array_sum(array_map(fn ($i) => (int) $i['quantity'], $items)); ?>
    <section class="sheet" aria-label="Label for order <?= e($o['order_number']) ?>">
        <div class="top">
            <span><img src="<?= e(\Waimart\Services\Brand::asset('brand_logo_header')) ?>" alt="<?= e($store) ?>"></span>
            <span class="kind"><?= $pickup ? 'PICKUP' : 'DELIVERY' ?></span>
        </div>
        <div class="dest">
            <div class="label"><?= $pickup ? 'Pickup station' : 'Deliver to' ?></div>
            <?php if ($pickup): ?>
                <div class="name"><?= e($o['pickup_name']) ?></div>
                <div class="sub"><?= e(trim($o['pickup_code'] . ' · ' . $o['pickup_town'] . ($o['pickup_county'] ? ', ' . $o['pickup_county'] : ''), ' ·')) ?></div>
            <?php else: ?>
                <div class="name"><?= e($o['town'] . ', ' . $o['county_name']) ?></div>
                <div class="sub"><?= e((string) $o['location']) ?><?= $o['delivery_instructions'] ? ' — ' . e($o['delivery_instructions']) : '' ?></div>
            <?php endif; ?>
        </div>
        <div class="who">
            <span class="label">Customer</span><b><?= e($o['customer_name']) ?></b>
            <span class="label">Phone</span><b><?= e(Validator::displayPhone((string) $o['customer_phone'])) ?></b>
            <span class="label">Customer no.</span><span><?= $o['user_id'] ? 'C-' . str_pad((string) $o['user_id'], 6, '0', STR_PAD_LEFT) : 'Guest' ?></span>
            <span class="label">Order</span><span><?= e($o['order_number']) ?> · <?= e(date('j M Y', strtotime((string) $o['placed_at']))) ?></span>
        </div>
        <div class="items">
            <div class="label">Contents (<?= $units ?> item<?= $units === 1 ? '' : 's' ?>)</div>
            <ul>
                <?php foreach ($shown as $it): ?><li><?= (int) $it['quantity'] ?> × <?= e($it['product_name']) ?><?= $it['variant_name'] && $it['variant_name'] !== 'Default' ? ' (' . e($it['variant_name']) . ')' : '' ?></li><?php endforeach; ?>
                <?php if (count($items) > count($shown)): ?><li>+ <?= count($items) - count($shown) ?> more line<?= count($items) - count($shown) === 1 ? '' : 's' ?></li><?php endif; ?>
            </ul>
        </div>
        <?php if ($o['payment_method'] === 'cod'): ?>
            <div class="pay cod">COLLECT CASH: <?= e(Money::format((string) $o['amount_due'])) ?></div>
        <?php else: ?>
            <div class="pay">PAID — NOTHING TO COLLECT</div>
        <?php endif; ?>
        <div class="code">
            <?= Barcode::svg((string) $o['order_number'], 60, 'Barcode for order ' . $o['order_number']) ?>
            <div class="num"><?= e($o['order_number']) ?></div>
        </div>
        <div class="foot"><?= $pickup ? 'Station: scan on arrival, and again at hand-over. Release only with the customer\'s code and ID.' : 'Hand over only to the customer named above.' ?>
            <?= $storePhone !== '' ? ' · ' . e($store) . ' ' . e($storePhone) : '' ?></div>
    </section>
<?php endforeach; ?>
<script src="<?= e(asset('js/label.js')) ?>"></script>
</body>
</html>
