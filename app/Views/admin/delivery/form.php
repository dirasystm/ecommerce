<?php
/** @var array<string,mixed>|null $zone @var list<array<string,mixed>> $locations @var list<array<string,mixed>> $counties */
$mine = fv('_form', '') === 'zone';
$v = fn (string $k, string $d) => (string) ($mine ? fv($k, $d) : $d);
?>
<div class="wm-admin-head">
    <h1><?= $zone ? 'Edit zone: ' . e($zone['name']) : 'New delivery zone' ?></h1>
    <a class="btn btn-outline-secondary" href="<?= e(url('/admin/delivery-zones')) ?>">All zones</a>
</div>
<div class="row g-3">
    <div class="col-xl-6">
        <form class="wm-panel wm-panel-body" method="post" action="<?= e(url($zone ? '/admin/delivery-zones/' . $zone['id'] : '/admin/delivery-zones')) ?>" novalidate>
            <?= csrf_field() ?><input type="hidden" name="_form" value="zone">
            <div class="mb-3">
                <label class="form-label" for="name">Zone name</label>
                <input class="form-control<?= $mine ? invalid('name') : '' ?>" id="name" name="name" maxlength="100" required value="<?= e($v('name', $zone['name'] ?? '')) ?>">
                <?= $mine ? field_error('name') : '' ?>
            </div>
            <div class="mb-3">
                <label class="form-label" for="description">Description</label>
                <input class="form-control" id="description" name="description" maxlength="255" value="<?= e($v('description', $zone['description'] ?? '')) ?>">
            </div>
            <div class="row g-3 mb-3">
                <div class="col-6">
                    <label class="form-label" for="fee">Delivery fee (KSh)</label>
                    <input class="form-control<?= $mine ? invalid('fee') : '' ?>" id="fee" name="fee" inputmode="decimal" required value="<?= e($v('fee', (string) ($zone['fee'] ?? ''))) ?>">
                    <?= $mine ? field_error('fee') : '' ?>
                </div>
                <div class="col-6">
                    <label class="form-label" for="free">Free delivery over (KSh)</label>
                    <input class="form-control<?= $mine ? invalid('free_delivery_threshold') : '' ?>" id="free" name="free_delivery_threshold" inputmode="decimal" placeholder="Leave blank for never" value="<?= e($v('free_delivery_threshold', (string) ($zone['free_delivery_threshold'] ?? ''))) ?>">
                    <?= $mine ? field_error('free_delivery_threshold') : '' ?>
                </div>
                <div class="col-6">
                    <label class="form-label" for="min_days">Estimated days — from</label>
                    <input class="form-control<?= $mine ? invalid('min_days') : '' ?>" id="min_days" name="min_days" type="number" min="0" max="60" required value="<?= e($v('min_days', (string) ($zone['min_days'] ?? '1'))) ?>">
                    <?= $mine ? field_error('min_days') : '' ?>
                </div>
                <div class="col-6">
                    <label class="form-label" for="max_days">to</label>
                    <input class="form-control<?= $mine ? invalid('max_days') : '' ?>" id="max_days" name="max_days" type="number" min="0" max="60" required value="<?= e($v('max_days', (string) ($zone['max_days'] ?? '3'))) ?>">
                    <?= $mine ? field_error('max_days') : '' ?>
                </div>
                <div class="col-6">
                    <label class="form-label" for="sort_order">Display order</label>
                    <input class="form-control" id="sort_order" name="sort_order" type="number" value="<?= e($v('sort_order', (string) ($zone['sort_order'] ?? '0'))) ?>">
                </div>
            </div>
            <div class="form-check"><input class="form-check-input" type="checkbox" id="cod_allowed" name="cod_allowed" value="1" <?= $mine ? fchecked('cod_allowed', true) : (($zone === null || (int) $zone['cod_allowed']) ? 'checked' : '') ?>><label class="form-check-label" for="cod_allowed">Cash on delivery available</label></div>
            <div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" <?= $mine ? fchecked('is_active', true) : (($zone === null || (int) $zone['is_active']) ? 'checked' : '') ?>><label class="form-check-label" for="is_active">Active</label></div>
            <p class="form-text">Delivery estimates are shown to customers as a range of business days after dispatch, never as a guaranteed date.</p>
            <button class="btn btn-wm-green" type="submit">Save zone</button>
        </form>
    </div>

    <?php if ($zone): ?>
    <div class="col-xl-6" id="locations">
        <div class="wm-panel">
            <div class="wm-panel-head">Locations covered</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($locations as $l): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span><?= $l['town'] !== '' ? e($l['town']) . ', ' . e($l['county']) : '<strong>All of ' . e($l['county']) . '</strong>' ?></span>
                        <form method="post" action="<?= e(url('/admin/delivery-zones/' . $zone['id'] . '/locations/' . $l['id'] . '/delete')) ?>" data-confirm="Remove this location from the zone?">
                            <?= csrf_field() ?><button class="btn btn-sm btn-link text-danger" type="submit" aria-label="Remove <?= e($l['town'] ?: $l['county']) ?>">Remove</button>
                        </form>
                    </li>
                <?php endforeach; ?>
                <?php if (!$locations): ?><li class="list-group-item text-muted small">No locations yet — this zone does not apply anywhere.</li><?php endif; ?>
            </ul>
            <form class="wm-panel-body border-top" method="post" action="<?= e(url('/admin/delivery-zones/' . $zone['id'] . '/locations')) ?>" novalidate>
                <?= csrf_field() ?><input type="hidden" name="_form" value="loc">
                <div class="row g-2 align-items-end">
                    <div class="col-sm-5">
                        <label class="form-label small" for="county_id">County</label>
                        <select class="form-select form-select-sm" id="county_id" name="county_id" required>
                            <option value="">Select…</option>
                            <?php foreach ($counties as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label small" for="town">Town (optional)</label>
                        <input class="form-control form-control-sm" id="town" name="town" maxlength="100" placeholder="Blank = whole county">
                    </div>
                    <div class="col-sm-3 d-grid"><button class="btn btn-sm btn-wm-green" type="submit">Add</button></div>
                </div>
                <?= fv('_form', '') === 'loc' ? field_error('town') . field_error('county_id') : '' ?>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>
