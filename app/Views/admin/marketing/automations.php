<?php
use Waimart\Core\Auth;
use Waimart\Core\View;
use Waimart\Services\Marketing\Automation;

/**
 * @var array<string,array<string,mixed>> $rows @var array<string,array<string,int>> $stats @var bool $paused
 * @var string|null $lastRun @var string|null $nightly
 */
$manage = Auth::can('marketing.manage');
$steps = ['cart_recovery' => 2, 'reengage' => 3];
$hours = fn ($h) => $h === null ? '' : ((int) $h % 24 === 0 && (int) $h >= 48 ? ((int) $h / 24) . ' days' : (int) $h . ' hours');
?>
<div class="wm-admin-head"><h1>Automations</h1></div>
<?= View::partial('admin/marketing/_nav', ['active' => '/admin/marketing/automations']) ?>
<?php if ($paused): ?><div class="alert alert-warning small"><strong>All marketing messages are paused</strong> in <a href="<?= e(url('/admin/marketing/settings')) ?>">Settings</a>. Nothing below is sent until you un-pause.</div><?php endif; ?>
<div class="alert alert-info small">
    Automations send only to people who agreed to marketing on that channel, never during quiet hours, never more than the daily and weekly limits, and they stop by themselves when they are no longer relevant (for example the customer ordered). Each message has an unsubscribe link.
    They run when the scheduler runs <code>bin/marketing-run.php</code> every 15 minutes (see docs/OPERATIONS.md).
    <?= $nightly ? 'Nightly tasks last ran on ' . e($nightly) . '.' : '<strong>The scheduler has not run yet on this server.</strong>' ?>
</div>
<?php foreach (Automation::CODES as $code => [$name, $desc]): $a = $rows[$code] ?? null; if ($a === null) { continue; } $s = $stats[$code] ?? []; $n = $steps[$code] ?? 1; ?>
    <section class="wm-panel mb-3" id="auto-<?= e($code) ?>">
        <div class="wm-panel-head d-flex justify-content-between align-items-center">
            <span><?= e($name) ?></span>
            <span class="badge <?= $a['is_enabled'] ? 'badge-soft-success' : 'badge-soft-muted' ?>"><?= $a['is_enabled'] ? 'On' : 'Off' ?></span>
        </div>
        <div class="wm-panel-body">
            <p class="small text-muted mb-2"><?= e($desc) ?></p>
            <p class="small mb-3">Last 30 days: <?= (int) ($s['sent'] ?? 0) + (int) ($s['logged'] ?? 0) ?> sent · <?= (int) ($s['queued'] ?? 0) ?> waiting · <?= (int) ($s['cancelled'] ?? 0) ?> stopped (no longer relevant) · <?= (int) ($s['suppressed'] ?? 0) ?> not allowed (consent) · <?= (int) ($s['failed'] ?? 0) ?> failed</p>
            <?php if ($code === 'cart_recovery'): ?>
                <p class="small mb-0">Timing, channels and guest reminders are set under <a href="<?= e(url('/admin/marketing/abandoned-carts', ['view' => 'settings'])) ?>">Abandoned carts → Settings</a>, with the recovery figures.</p>
            <?php elseif ($manage): $chan = explode(',', (string) $a['channels']); ?>
                <form class="row g-3 align-items-end" method="post" action="<?= e(url('/admin/marketing/automations/' . $code)) ?>" novalidate>
                    <?= csrf_field() ?>
                    <div class="col-md-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="on-<?= e($code) ?>" name="is_enabled" value="1" <?= $a['is_enabled'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="on-<?= e($code) ?>">Switched on</label>
                        </div>
                    </div>
                    <fieldset class="col-md-3">
                        <legend class="form-label fs-6 mb-1">Send by</legend>
                        <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" id="em-<?= e($code) ?>" name="channels[]" value="email" <?= in_array('email', $chan, true) ? 'checked' : '' ?>><label class="form-check-label" for="em-<?= e($code) ?>">Email</label></div>
                        <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" id="sm-<?= e($code) ?>" name="channels[]" value="sms" <?= in_array('sms', $chan, true) ? 'checked' : '' ?>><label class="form-check-label" for="sm-<?= e($code) ?>">SMS</label></div>
                    </fieldset>
                    <?php foreach (['delay_hours', 'delay2_hours', 'delay3_hours'] as $i => $col): if ($i >= $n) { break; } ?>
                        <div class="col-md-2">
                            <label class="form-label small" for="<?= e($col . '-' . $code) ?>"><?= $n > 1 ? 'Step ' . ($i + 1) . ' after (hours)' : 'Wait (hours)' ?></label>
                            <input class="form-control form-control-sm" id="<?= e($col . '-' . $code) ?>" name="<?= e($col) ?>" inputmode="numeric" value="<?= e((string) ($a[$col] ?? '')) ?>" aria-describedby="h-<?= e($col . '-' . $code) ?>">
                            <div class="form-text" id="h-<?= e($col . '-' . $code) ?>"><?= $a[$col] !== null ? e($hours($a[$col])) : ($i > 0 ? 'Blank: no step' : '') ?></div>
                        </div>
                    <?php endforeach; ?>
                    <div class="col-md-auto"><button class="btn btn-sm btn-wm-green" type="submit">Save</button></div>
                </form>
            <?php else: ?>
                <p class="small mb-0">Sends by <?= e(str_replace(',', ' and ', strtoupper((string) $a['channels']))) ?>, after <?= e($hours($a['delay_hours'])) ?>.</p>
            <?php endif; ?>
        </div>
    </section>
<?php endforeach; ?>
<p class="small text-muted">The wording of each message is under <a href="<?= e(url('/admin/messages')) ?>">Messages</a> (group "Marketing"). SMS needs an approved sender ID in Email &amp; SMS.</p>
