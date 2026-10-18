<?php
use Waimart\Core\Validator;

/** @var array<string,mixed>|null $point @var list<array<string,mixed>> $counties @var int $openOrders */
$p = $point;
$field = function (string $name, string $label, string $current, array $attrs = []) {
    $type = $attrs['type'] ?? 'text';
    $extra = '';
    foreach ($attrs as $k => $val) {
        if ($k !== 'type' && $k !== 'help') {
            $extra .= ' ' . $k . '="' . e($val) . '"';
        }
    }
    $html = '<label class="form-label" for="' . $name . '">' . e($label) . '</label>'
        . '<input class="form-control' . invalid($name) . '" id="' . $name . '" name="' . $name . '" type="' . $type . '" value="' . e(fv($name, $current)) . '"' . $extra . '>';
    if (!empty($attrs['help'])) {
        $html .= '<div class="form-text">' . e($attrs['help']) . '</div>';
    }
    return $html . field_error($name);
};
?>
<div class="wm-admin-head">
    <h1><?= $p ? 'Edit: ' . e($p['name']) : 'New pickup point' ?></h1>
    <a class="btn btn-outline-secondary" href="<?= e(url('/admin/pickup-points')) ?>">All pickup points</a>
</div>
<?php if ($p && $openOrders > 0): ?>
    <div class="alert alert-info small"><?= $openOrders ?> open order(s) are assigned to this pickup point. Changes here do <strong>not</strong> alter those orders — each order keeps the details it was placed with.</div>
<?php endif; ?>
<form class="wm-panel wm-panel-body" method="post" action="<?= e(url($p ? '/admin/pickup-points/' . $p['id'] : '/admin/pickup-points')) ?>" style="max-width:900px" novalidate>
    <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-md-4"><?= $field('code', 'Code', $p['code'] ?? '', ['maxlength' => 20, 'required' => 'required', 'help' => 'Unique, e.g. NBO-CBD-01']) ?></div>
        <div class="col-md-8"><?= $field('name', 'Name', $p['name'] ?? '', ['maxlength' => 120, 'required' => 'required']) ?></div>
        <div class="col-md-4">
            <label class="form-label" for="county_id">County</label>
            <select class="form-select<?= invalid('county_id') ?>" id="county_id" name="county_id" required>
                <option value="">Select…</option>
                <?php foreach ($counties as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= (string) fv('county_id', (string) ($p['county_id'] ?? '')) === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <?= field_error('county_id') ?>
        </div>
        <div class="col-md-4"><?= $field('town', 'Town', $p['town'] ?? '', ['maxlength' => 100, 'required' => 'required']) ?></div>
        <div class="col-md-4"><?= $field('pickup_fee', 'Pickup fee (KSh)', (string) ($p['pickup_fee'] ?? '0'), ['inputmode' => 'decimal', 'required' => 'required']) ?></div>
        <div class="col-12"><?= $field('address', 'Address / directions', $p['address'] ?? '', ['maxlength' => 255, 'required' => 'required']) ?></div>
        <div class="col-md-4"><?= $field('latitude', 'Latitude', (string) ($p['latitude'] ?? ''), ['inputmode' => 'decimal', 'placeholder' => '-1.2833']) ?></div>
        <div class="col-md-4"><?= $field('longitude', 'Longitude', (string) ($p['longitude'] ?? ''), ['inputmode' => 'decimal', 'placeholder' => '36.8167']) ?></div>
        <div class="col-md-4"><?= $field('map_url', 'Map link', $p['map_url'] ?? '', ['type' => 'url', 'maxlength' => 500, 'placeholder' => 'https://maps.google.com/…']) ?></div>
        <div class="col-md-4"><?= $field('phone', 'Public phone', isset($p['phone']) ? Validator::displayPhone((string) $p['phone']) : '', ['type' => 'tel']) ?></div>
        <div class="col-md-4"><?= $field('email', 'Public email', $p['email'] ?? '', ['type' => 'email']) ?></div>
        <div class="col-md-4"><?= $field('opening_hours', 'Opening hours', $p['opening_hours'] ?? '', ['maxlength' => 500, 'required' => 'required', 'placeholder' => 'Mon–Sat 8:00–18:00']) ?></div>
        <div class="col-12">
            <label class="form-label" for="instructions">Collection instructions</label>
            <textarea class="form-control<?= invalid('instructions') ?>" id="instructions" name="instructions" rows="2" maxlength="1000"><?= e(fv('instructions', $p['instructions'] ?? '')) ?></textarea>
            <?= field_error('instructions') ?>
        </div>
        <div class="col-md-4"><?= $field('max_order_value', 'Maximum order value (KSh)', (string) ($p['max_order_value'] ?? ''), ['inputmode' => 'decimal', 'help' => 'Optional restriction']) ?></div>
        <div class="col-md-4"><?= $field('daily_capacity', 'Daily capacity (orders)', (string) ($p['daily_capacity'] ?? ''), ['type' => 'number', 'min' => 1, 'help' => 'Optional; blank = unlimited']) ?></div>
        <div class="col-md-4 d-flex flex-column justify-content-end">
            <div class="form-check"><input class="form-check-input" type="checkbox" id="cod_allowed" name="cod_allowed" value="1" <?= fchecked('cod_allowed', $p === null || (int) $p['cod_allowed'] === 1) ?>><label class="form-check-label" for="cod_allowed">Cash on collection accepted</label></div>
            <div class="form-check"><input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" <?= fchecked('is_active', $p === null || (int) $p['is_active'] === 1) ?>><label class="form-check-label" for="is_active">Active (offered at checkout)</label></div>
        </div>
    </div>
    <button class="btn btn-wm-green mt-4" type="submit">Save pickup point</button>
</form>
