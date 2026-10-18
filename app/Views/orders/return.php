<?php
/** @var array<string,mixed> $order @var list<array<string,mixed>> $items @var array<string,string> $reasons @var int $windowDays */
?>
<div class="container-wm">
    <nav class="wm-breadcrumb" aria-label="Breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= e(url('/')) ?>">Home</a></li>
            <li class="breadcrumb-item"><?= e($order['order_number']) ?></li>
            <li class="breadcrumb-item active" aria-current="page">Return items</li>
        </ol>
    </nav>
    <form class="wm-card wm-page" method="post" action="<?= e(url('/order/' . $order['order_number'] . '/return')) ?>" style="max-width:820px" novalidate>
        <?= csrf_field() ?>
        <h1>Return items from <?= e($order['order_number']) ?></h1>
        <p class="small text-muted">You can request a return within <?= $windowDays ?> days of delivery or collection. Our team reviews every request; refunds are made after the items are received and inspected. Items from different sellers become separate returns.
            <a href="<?= e(url('/returns-policy')) ?>">Returns policy</a></p>
        <?= field_error('items') ?>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead><tr><th>Item</th><th class="text-end">Received</th><th style="width:140px">Return qty</th></tr></thead>
                <tbody>
                <?php foreach ($items as $it): ?>
                    <tr>
                        <td><?= e($it['product_name']) ?><?= $it['variant_name'] !== 'Default' ? '<div class="small text-muted">' . e($it['variant_name']) . '</div>' : '' ?>
                            <div class="small text-muted">Sold by <?= e($it['seller_name']) ?></div></td>
                        <td class="text-end"><?= (int) $it['returnable'] ?></td>
                        <td><label class="visually-hidden" for="q<?= $it['id'] ?>">Quantity to return of <?= e($it['product_name']) ?></label>
                            <input class="form-control form-control-sm" type="number" min="0" max="<?= (int) $it['returnable'] ?>" id="q<?= $it['id'] ?>" name="qty[<?= (int) $it['id'] ?>]" value="<?= e((string) ((is_array($oq = fv('qty', [])) ? ($oq[(string) $it['id']] ?? '0') : '0'))) ?>"></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label" for="reason">Reason</label>
                <select class="form-select<?= invalid('reason') ?>" id="reason" name="reason" required>
                    <option value="">Choose…</option>
                    <?php foreach ($reasons as $k => $l): ?><option value="<?= e($k) ?>" <?= fv('reason', '') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
                </select>
                <?= field_error('reason') ?>
            </div>
            <div class="col-12">
                <label class="form-label" for="details">Details <span class="text-muted fw-normal">(what is wrong?)</span></label>
                <textarea class="form-control<?= invalid('details') ?>" id="details" name="details" rows="3" maxlength="1000"><?= e(fv('details', '')) ?></textarea>
                <?= field_error('details') ?>
            </div>
        </div>
        <button class="btn btn-wm mt-3" type="submit">Request return</button>
    </form>
</div>
