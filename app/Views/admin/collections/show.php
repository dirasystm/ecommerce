<?php
use Waimart\Services\OrderPresenter;

/** @var array<string,mixed> $order @var list<array<string,mixed>> $items @var bool $canReissue */
$ready = $order['status'] === 'ready_for_collection' || in_array('ready_for_collection', array_column($items, 'part_status'), true);
$multi = count(array_unique(array_column($items, 'sub_number'))) > 1;
?>
<div class="wm-admin-head">
    <div>
        <h1>Hand over <?= e($order['order_number']) ?></h1>
        <div class="small text-muted"><?= e($order['pickup_name']) ?> (<?= e($order['pickup_code']) ?>) · <span class="badge <?= OrderPresenter::badgeClass($order['status']) ?>"><?= e(OrderPresenter::status($order['status'])) ?></span></div>
    </div>
    <a class="btn btn-outline-secondary" href="<?= e(url('/admin/collections')) ?>">Back to desk</a>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <?php if ($ready): ?>
            <form class="wm-panel" method="post" action="<?= e(url('/admin/collections/' . $order['id'])) ?>" autocomplete="off" novalidate>
                <?= csrf_field() ?>
                <div class="wm-panel-head">Verify and hand over</div>
                <div class="wm-panel-body">
                    <ol class="small ps-3">
                        <li>Ask the customer for their <strong>8-character collection code</strong> (sent by SMS/email). Never read the code out to them.</li>
                        <li>Check their <strong>ID</strong> matches the order name, or that they are authorised to collect.</li>
                        <li>Check the items below with the customer.</li>
                    </ol>
                    <label class="form-label" for="code">Collection code</label>
                    <input class="form-control form-control-lg text-uppercase<?= invalid('code') ?>" id="code" name="code" maxlength="12" required
                           inputmode="text" autocapitalize="characters" spellcheck="false" placeholder="XXXX XXXX" style="letter-spacing:.15em;font-family:monospace">
                    <?= field_error('code') ?>
                    <div class="form-check mt-3">
                        <input class="form-check-input<?= invalid('id_checked') ?>" type="checkbox" id="id_checked" name="id_checked" value="1" required>
                        <label class="form-check-label" for="id_checked">I have checked the customer's ID / authorisation for <strong><?= e($order['customer_name']) ?></strong></label>
                        <?= field_error('id_checked') ?>
                    </div>
                    <label class="form-label mt-3" for="note">Note (optional)</label>
                    <input class="form-control" id="note" name="note" maxlength="200" placeholder="e.g. Collected by sister, ID 1234…">
                    <?php if ($order['payment_method'] === 'cod'): ?>
                        <div class="alert alert-warning small mt-3 mb-0">Cash on delivery: collect <strong><?= e(money($order['grand_total'])) ?></strong> before handing over.</div>
                    <?php endif; ?>
                    <button class="btn btn-wm-green btn-lg w-100 mt-3" type="submit">Verify code &amp; hand over</button>
                    <p class="small text-muted mt-2 mb-0">Incorrect codes are logged. After 5 incorrect attempts the order is locked for 15 minutes.</p>
                </div>
            </form>
            <?php if ($canReissue): ?>
                <form method="post" action="<?= e(url('/admin/collections/' . $order['id'] . '/reissue')) ?>" class="mt-2" data-confirm="Send the customer a new code? The current code will stop working.">
                    <?= csrf_field() ?><button class="btn btn-link btn-sm px-0" type="submit">Customer lost their code? Re-issue a new one</button>
                </form>
            <?php endif; ?>
        <?php else: ?>
            <div class="alert alert-info">This order is not ready for collection (status: <?= e(OrderPresenter::status($order['status'])) ?>).</div>
        <?php endif; ?>
    </div>
    <div class="col-lg-6">
        <div class="wm-panel">
            <div class="wm-panel-head">Items to hand over</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($items as $it): ?>
                    <?php $here = in_array($it['part_status'], ['ready_for_collection', 'collected'], true) || !$multi; ?>
                    <li class="list-group-item d-flex justify-content-between gap-2"><span><?= e($it['product_name']) ?><?= $it['variant_name'] !== 'Default' ? ' · ' . e($it['variant_name']) : '' ?> <code class="small"><?= e($it['sku']) ?></code>
                        <?php if ($multi): ?><span class="d-block small text-muted"><?= e($it['seller_name']) ?> · <?= e(\Waimart\Services\OrderPresenter::sellerOrderStatus((string) $it['part_status'])) ?><?= $here ? '' : ' — not at the station yet' ?></span><?php endif; ?></span>
                        <strong class="<?= $here ? '' : 'text-muted' ?>">× <?= (int) $it['quantity'] ?></strong></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>
