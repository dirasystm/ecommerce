<?php
use Waimart\Core\View;
use Waimart\Services\SettingsService;

/** Marketing settings (the "intelligence" settings group), in sections. @var array<string,string> $values */
$fields = SettingsService::fieldsOf('intelligence');
$sections = [
    'Shopping activity & privacy' => ['tracking_enabled', 'tracking_consent_mode', 'tracking_retention_days', 'guest_history_days'],
    'Recommendations' => ['recs_enabled', 'recs_home', 'recs_similar', 'recs_bought_together', 'recs_cart', 'recs_account', 'recs_confirmation', 'recs_email',
                          'recs_weight_view', 'recs_weight_search', 'recs_weight_cart', 'recs_weight_wishlist', 'recs_weight_purchase', 'recs_half_life_days'],
    'Marketing messages' => ['marketing_paused', 'marketing_max_per_day', 'marketing_max_per_week', 'marketing_quiet_start', 'marketing_quiet_end', 'price_drop_min_percent'],
    'Segments & attribution' => ['segment_new_days', 'segment_inactive_days', 'segment_high_value', 'segment_frequent_views', 'attribution_window_days'],
];
$listed = array_merge(...array_values($sections));
if ($rest = array_diff(array_keys($fields), $listed)) {
    $sections['Other'] = array_values($rest);
}
?>
<div class="wm-admin-head"><h1>Marketing settings</h1></div>
<?= View::partial('admin/marketing/_nav', ['active' => '/admin/marketing/settings']) ?>
<form method="post" action="<?= e(url('/admin/marketing/settings')) ?>" novalidate>
    <?= csrf_field() ?>
    <?php foreach ($sections as $title => $keys): ?>
        <section class="wm-panel mb-3">
            <div class="wm-panel-head"><?= e($title) ?></div>
            <div class="wm-panel-body row g-3">
                <?php foreach ($keys as $key): if (!isset($fields[$key])) { continue; } [$label, $rule, $help, , $widget] = $fields[$key];
                    $val = (string) fv($key, $values[$key] ?? ''); $helpId = $help ? $key . 'Help' : null; ?>
                    <?php if ($widget === 'checkbox'): ?>
                        <div class="col-md-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="<?= e($key) ?>" name="<?= e($key) ?>" value="1" <?= fchecked($key, ($values[$key] ?? '0') === '1') ?><?= $helpId ? ' aria-describedby="' . e($helpId) . '"' : '' ?>>
                                <label class="form-check-label" for="<?= e($key) ?>"><?= e($label) ?></label>
                                <?php if ($help): ?><div class="form-text" id="<?= e($helpId) ?>"><?= e($help) ?></div><?php endif; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="col-md-6 col-xl-4">
                            <label class="form-label" for="<?= e($key) ?>"><?= e($label) ?></label>
                            <?php if ($widget === 'select'): ?>
                                <select class="form-select<?= invalid($key) ?>" id="<?= e($key) ?>" name="<?= e($key) ?>"<?= $helpId ? ' aria-describedby="' . e($helpId) . '"' : '' ?>>
                                    <?php foreach (SettingsService::OPTIONS[$key] as $opt => $optLabel): ?><option value="<?= e((string) $opt) ?>" <?= $val === (string) $opt ? 'selected' : '' ?>><?= e($optLabel) ?></option><?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <input class="form-control<?= invalid($key) ?>" id="<?= e($key) ?>" name="<?= e($key) ?>" value="<?= e($val) ?>"<?= $widget === 'number' ? ' inputmode="numeric"' : '' ?><?= $helpId ? ' aria-describedby="' . e($helpId) . '"' : '' ?>>
                            <?php endif; ?>
                            <?php if ($help): ?><div class="form-text" id="<?= e($helpId) ?>"><?= e($help) ?></div><?php endif; ?>
                            <?= field_error($key) ?>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
    <button class="btn btn-wm-green" type="submit">Save marketing settings</button>
</form>
