<?php
/** @var list<array<string,mixed>> $zones @var array<int,list<array<string,mixed>>> $locs @var list<string> $uncovered */
?>
<div class="wm-admin-head">
    <h1>Delivery zones</h1>
    <a class="btn btn-wm-green" href="<?= e(url('/admin/delivery-zones/new')) ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i> New zone</a>
</div>
<p class="small text-muted">A town-specific rule takes priority over a county-wide rule. Locations not covered by an active zone cannot choose home delivery (customers are offered pickup instead).</p>
<div class="wm-panel mb-3">
    <div class="table-responsive">
        <table class="table table-hover mb-0" data-wm-table="10" data-wm-noun="zones">
            <thead><tr><th scope="col">Zone</th><th scope="col" class="text-end">Fee</th><th scope="col" class="text-end">Free over</th><th scope="col">ETA</th><th scope="col">COD</th><th scope="col">Covers</th><th scope="col">Status</th><th scope="col"></th></tr></thead>
            <tbody>
            <?php foreach ($zones as $z): $l = $locs[(int) $z['id']] ?? []; ?>
                <tr>
                    <td class="fw-semibold"><?= e($z['name']) ?></td>
                    <td class="text-end text-nowrap"><?= e(money($z['fee'])) ?></td>
                    <td class="text-end text-nowrap"><?= $z['free_delivery_threshold'] !== null ? e(money($z['free_delivery_threshold'])) : '—' ?></td>
                    <td class="small text-nowrap"><?= (int) $z['min_days'] ?>–<?= (int) $z['max_days'] ?> days</td>
                    <td><?= (int) $z['cod_allowed'] ? 'Yes' : 'No' ?></td>
                    <td class="small"><?= $l ? e(implode(', ', array_map(fn ($x) => $x['town'] !== '' ? $x['town'] . ' (' . $x['county'] . ')' : 'All ' . $x['county'], array_slice($l, 0, 6)))) . (count($l) > 6 ? ' +' . (count($l) - 6) . ' more' : '') : '<span class="text-danger">No locations</span>' ?></td>
                    <td><?= (int) $z['is_active'] ? '<span class="badge badge-soft-success">Active</span>' : '<span class="badge badge-soft-muted">Inactive</span>' ?></td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/admin/delivery-zones/' . $z['id'] . '/edit')) ?>">Edit</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$zones): ?><tr><td colspan="8" class="text-center text-muted py-4">No delivery zones yet — home delivery is unavailable until you add one.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php if ($uncovered): ?>
    <div class="wm-panel wm-panel-body small">
        <strong>Counties without active delivery (<?= count($uncovered) ?>):</strong> <?= e(implode(', ', $uncovered)) ?>
    </div>
<?php endif; ?>
