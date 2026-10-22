<?php
/**
 * Date range and attribution model filter (GET). @var string $path @var array<string,string> $q current query
 * @var bool $model show the attribution choice @var array<string,string> $extra other query values to keep
 */
$range = $q['range'] ?? '30d';
$presets = ['today' => 'Today', 'yesterday' => 'Yesterday', '7d' => 'Last 7 days', '30d' => 'Last 30 days', 'this_month' => 'This month',
            'last_month' => 'Previous month', 'this_year' => 'This year', 'custom' => 'Custom dates'];
$extra ??= [];
?>
<form class="wm-panel wm-panel-body d-flex flex-wrap gap-2 align-items-end mb-3" method="get" action="<?= e(url($path)) ?>">
    <?php foreach ($extra as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e((string) $v) ?>"><?php endforeach; ?>
    <div>
        <label class="form-label small mb-1" for="range">Period</label>
        <select class="form-select form-select-sm" id="range" name="range">
            <?php foreach ($presets as $k => $l): ?><option value="<?= e($k) ?>" <?= $range === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div>
        <label class="form-label small mb-1" for="from">From <span class="visually-hidden">(custom dates)</span></label>
        <input class="form-control form-control-sm" type="date" id="from" name="from" value="<?= e($q['from'] ?? '') ?>">
    </div>
    <div>
        <label class="form-label small mb-1" for="to">To <span class="visually-hidden">(custom dates)</span></label>
        <input class="form-control form-control-sm" type="date" id="to" name="to" value="<?= e($q['to'] ?? '') ?>">
    </div>
    <?php if (!empty($model)): ?>
        <div>
            <label class="form-label small mb-1" for="model">Credit sales to</label>
            <select class="form-select form-select-sm" id="model" name="model" aria-describedby="modelHelp">
                <option value="last" <?= ($q['model'] ?? 'last') !== 'first' ? 'selected' : '' ?>>Last link clicked</option>
                <option value="first" <?= ($q['model'] ?? '') === 'first' ? 'selected' : '' ?>>First link clicked</option>
            </select>
        </div>
    <?php endif; ?>
    <button class="btn btn-sm btn-wm-green" type="submit">Show</button>
    <?php if (!empty($model)): ?><div class="form-text w-100 mt-0" id="modelHelp">Custom dates are used when "Custom dates" is chosen. A sale counts for a link clicked within the attribution window (Settings).</div><?php endif; ?>
</form>
