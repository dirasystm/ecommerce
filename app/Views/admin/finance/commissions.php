<?php
/**
 * @var list<array<string,mixed>> $rules @var bool $provisional @var list<array<string,mixed>> $sellers @var list<array<string,mixed>> $categories
 * @var list<array<string,mixed>> $report @var string $from @var string $to
 */
$active = array_values(array_filter($rules, fn ($r) => (int) $r['is_active'] === 1));
$retired = array_values(array_filter($rules, fn ($r) => (int) $r['is_active'] === 0));
$default = current(array_filter($active, fn ($r) => $r['scope'] === 'default')) ?: null;
$pct = fn ($r) => rtrim(rtrim((string) $r, '0'), '.') . '%';
$target = fn ($r) => $r['scope'] === 'default' ? 'All sellers (default)' : ($r['scope'] === 'seller' ? 'Seller: ' . $r['seller_name'] : 'Category: ' . $r['category_name']);
?>
<div class="wm-admin-head"><h1>Commission</h1></div>
<?php if ($provisional): ?>
    <div class="alert alert-warning small">The default rate (<?= e($default ? $pct($default['rate']) : '—') ?>) is <strong>provisional</strong>. Set the confirmed business rate below and tick "This is the confirmed rate".</div>
<?php endif; ?>
<p class="small text-muted">Precedence: a seller's own rate, then the product's category rate (its own category before its parent), then the default. The rate in force when an order is placed is stored with the order, so changes here never alter past orders.</p>

<div class="row g-3">
    <div class="col-lg-7">
        <section class="wm-panel mb-3">
            <div class="wm-panel-head">Rates in force</div>
            <div class="table-responsive"><table class="table table-sm mb-0">
                <thead><tr><th scope="col">Applies to</th><th scope="col" class="text-end">Rate</th><th scope="col">Since</th><th scope="col">Note</th><th scope="col"></th></tr></thead>
                <tbody>
                <?php foreach ($active as $r): ?>
                    <tr><td><?= e($target($r)) ?></td><td class="text-end fw-semibold"><?= e($pct($r['rate'])) ?></td>
                        <td class="small text-nowrap"><?= e(date('d M Y', strtotime((string) $r['created_at']))) ?></td><td class="small"><?= e((string) $r['note']) ?></td>
                        <td class="text-end"><?php if ($r['scope'] !== 'default' && !($r['scope'] === 'seller' && (int) $r['seller_id'] === \Waimart\Services\Sellers::platformId())): ?>
                            <form method="post" action="<?= e(url('/admin/commissions/' . $r['id'] . '/retire')) ?>" data-confirm="Remove this rate? New orders will use the next applicable rate."><?= csrf_field() ?>
                                <button class="btn btn-sm btn-link text-danger p-0" type="submit">Remove</button></form><?php endif; ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </section>
        <details class="wm-panel mb-3">
            <summary class="wm-panel-head">History (<?= count($retired) ?> retired rates)</summary>
            <div class="table-responsive"><table class="table table-sm mb-0">
                <thead><tr><th scope="col">Applied to</th><th scope="col" class="text-end">Rate</th><th scope="col">From</th><th scope="col">Until</th></tr></thead>
                <tbody><?php foreach ($retired as $r): ?>
                    <tr><td class="small"><?= e($target($r)) ?></td><td class="text-end small"><?= e($pct($r['rate'])) ?></td>
                        <td class="small text-nowrap"><?= e(date('d M Y H:i', strtotime((string) $r['created_at']))) ?></td><td class="small text-nowrap"><?= $r['deactivated_at'] ? e(date('d M Y H:i', strtotime((string) $r['deactivated_at']))) : '' ?></td></tr>
                <?php endforeach; ?></tbody>
            </table></div>
        </details>
    </div>
    <div class="col-lg-5">
        <form class="wm-panel mb-3" method="post" action="<?= e(url('/admin/commissions')) ?>" novalidate>
            <?= csrf_field() ?>
            <div class="wm-panel-head">Set a rate</div>
            <div class="wm-panel-body">
                <label class="form-label small" for="scope">Applies to</label>
                <select class="form-select form-select-sm<?= invalid('scope') ?>" id="scope" name="scope">
                    <option value="default">All sellers (default rate)</option><option value="seller">One seller</option><option value="category">One category</option>
                </select>
                <label class="form-label small mt-2" for="seller_id">Seller <span class="text-muted">(for "One seller")</span></label>
                <select class="form-select form-select-sm<?= invalid('target_id') ?>" id="seller_id" name="seller_id"><option value="">—</option>
                    <?php foreach ($sellers as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select>
                <label class="form-label small mt-2" for="category_id">Category <span class="text-muted">(for "One category")</span></label>
                <select class="form-select form-select-sm" id="category_id" name="category_id"><option value="">—</option>
                    <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"><?= $c['parent_name'] ? e($c['parent_name']) . ' › ' : '' ?><?= e($c['name']) ?></option><?php endforeach; ?></select>
                <?= field_error('target_id') ?>
                <label class="form-label small mt-2" for="rate">Rate (%)</label>
                <input class="form-control form-control-sm<?= invalid('rate') ?>" id="rate" name="rate" inputmode="decimal" placeholder="e.g. 10 or 7.5">
                <?= field_error('rate') ?>
                <label class="form-label small mt-2" for="note">Note <span class="text-muted">(optional)</span></label>
                <input class="form-control form-control-sm" id="note" name="note" maxlength="255">
                <div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="confirm_rate" name="confirm_rate" value="1">
                    <label class="form-check-label small" for="confirm_rate">This is the confirmed business rate (default only)</label></div>
                <button class="btn btn-wm-green w-100 mt-3" type="submit">Save rate</button>
            </div>
        </form>
    </div>
</div>

<section class="wm-panel">
    <div class="wm-panel-head">Commission earned</div>
    <form class="wm-panel-body d-flex flex-wrap gap-2 align-items-end border-bottom" method="get" action="<?= e(url('/admin/commissions')) ?>">
        <div><label class="form-label small mb-0" for="from">From</label><input class="form-control form-control-sm" type="date" id="from" name="from" value="<?= e($from) ?>"></div>
        <div><label class="form-label small mb-0" for="to">To</label><input class="form-control form-control-sm" type="date" id="to" name="to" value="<?= e($to) ?>"></div>
        <button class="btn btn-sm btn-outline-secondary" type="submit">Show</button>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/admin/commissions', ['from' => $from, 'to' => $to, 'format' => 'csv'])) ?>">CSV</a>
    </form>
    <div class="wm-panel-body pb-0"><?= \Waimart\Core\View::partial('partials/table/toolbar', ['id' => 'commission', 'path' => '/admin/commissions', 'lp' => $lp, 'label' => 'Search sellers in the report', 'placeholder' => 'Seller name', 'keep' => ['from' => $from, 'to' => $to]]) ?></div>
    <div id="commission" class="wm-table-region" data-table-region data-loading-text="Loading the report…">
    <div class="table-responsive"><table class="table table-sm mb-0">
        <caption class="visually-hidden">Commission earned per seller</caption>
        <thead><tr><th scope="col">Seller</th><th scope="col" class="text-end">Sales received</th><th scope="col" class="text-end">Commission</th><th scope="col" class="text-end">Commission reversed</th><th scope="col" class="text-end">Net commission</th></tr></thead>
        <tbody>
        <?php foreach ($page as $r): ?>
            <tr><td><?= e($r['seller_name']) ?></td><td class="text-end"><?= e(money($r['gross_sales'])) ?></td><td class="text-end"><?= e(money($r['commission'])) ?></td>
                <td class="text-end"><?= e(money(bcmul((string) $r['commission_reversed'], '-1', 2))) ?></td>
                <td class="text-end fw-semibold"><?= e(money(bcsub((string) $r['commission'], (string) $r['commission_reversed'], 2))) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$page): ?><?= empty_row(5, $lp->q !== '', 'No marketplace sales received in this period.') ?><?php endif; ?>
        </tbody>
    </table></div>
    <?= \Waimart\Core\View::partial('partials/table/footer', ['lp' => $lp, 'path' => '/admin/commissions', 'id' => 'commission', 'noun' => 'sellers']) ?>
    </div>
    <div class="wm-panel-body small text-muted">Counts only money actually received (verified M-Pesa or recorded cash). Sales are the sellers' merchandise — <?= e(brand_name()) ?>'s revenue is the commission column.</div>
</section>
