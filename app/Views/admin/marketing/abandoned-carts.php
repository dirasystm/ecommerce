<?php
use Waimart\Core\Auth;
use Waimart\Core\Validator;
use Waimart\Core\View;
use Waimart\Services\Marketing\LeadService;

/**
 * @var string $view carts|log|settings @var string $label @var array<string,string> $q @var array<string,mixed> $stats
 * @var \Waimart\Core\ListParams $lp @var list<array<string,mixed>> $rows @var bool $full
 * @var array<string,mixed>|null $automation @var array<string,string> $values @var string $channel @var string $status
 */
$rows ??= [];
$manage = Auth::can('marketing.manage');
$path = '/admin/marketing/abandoned-carts';
$pct = fn (?float $v) => $v === null ? '—' : number_format($v * 100, 1) . '%';
$n = fn ($v) => number_format((int) $v);
$mask = fn (?string $email, ?string $phone) => $full ? trim(($email ?: '') . ($phone ? ' · ' . Validator::displayPhone($phone) : '')) : LeadService::maskEmail($email);
$on = $automation !== null && (int) $automation['is_enabled'] === 1;
$statusBadge = ['sent' => ['badge-soft-success', 'Sent'], 'logged' => ['badge-soft-muted', 'Logged (not sent)'], 'queued' => ['badge-soft-warning', 'Waiting'],
                'sending' => ['badge-soft-warning', 'Sending'], 'failed' => ['badge-soft-danger', 'Failed'], 'suppressed' => ['badge-soft-muted', 'Not allowed'],
                'cancelled' => ['badge-soft-muted', 'Stopped']];
$cards = [
    ['Abandoned carts', $n($stats['abandoned']), 'Items left ' . (int) ($values['cart_abandon_minutes'] ?? 60) . '+ minutes without activity or an order · ' . money($stats['cart_value'])],
    ['Can be reminded', $n($stats['eligible']), 'Agreed to marketing by email or SMS'],
    ['Email reminders', $n($stats['email_sent']), $n($stats['email_failed']) . ' failed'],
    ['SMS reminders', $n($stats['sms_sent']), $n($stats['sms_delivered']) . ' delivered (gateway report) · ' . $n($stats['sms_failed']) . ' failed'],
    ['Retries', $n($stats['retries']), $n($stats['queued']) . ' waiting · ' . $n($stats['suppressed']) . ' not allowed · ' . $n($stats['cancelled']) . ' stopped'],
    ['Link clicks', $n($stats['clicks']), $n($stats['returned']) . ' customers came back to their cart'],
    ['Recovered orders', $n($stats['recovered']), $pct($stats['rate']) . ' of reminded carts (recovery rate)'],
    ['Recovered sales', money($stats['revenue']), money($stats['paid_revenue']) . ' of it paid'],
];
?>
<div class="wm-admin-head"><h1>Abandoned carts</h1></div>
<?= View::partial('admin/marketing/_nav', ['active' => $path]) ?>
<?php if (!$on): ?>
    <div class="alert alert-warning small"><strong>Cart reminders are switched off.</strong> Carts are still counted below. <?= $manage ? 'Switch them on under <a href="' . e(url($path, ['view' => 'settings'])) . '">Settings</a>.' : '' ?></div>
<?php endif; ?>
<?= View::partial('admin/marketing/_period', ['path' => $path, 'q' => $q, 'model' => false, 'extra' => ['view' => $view]]) ?>
<p class="small text-muted">For <?= e(strtolower($label)) ?>. A cart counts as abandoned when it has items, nothing happened for the inactivity period and no order followed. A reminder is sent only to people who agreed to marketing on that channel. An order counts as recovered when it is placed within <?= (int) ($values['cart_reminder_link_days'] ?? 7) ?> days after a reminder.</p>
<div class="row g-3 mb-3">
    <?php foreach ($cards as [$t, $v, $sub]): ?>
        <div class="col-6 col-lg-3"><div class="wm-panel wm-stat h-100">
            <div class="wm-stat-label"><?= e($t) ?></div>
            <div class="wm-stat-value"><?= e($v) ?></div>
            <div class="small text-muted"><?= e($sub) ?></div>
        </div></div>
    <?php endforeach; ?>
</div>

<ul class="nav nav-tabs mb-3">
    <?php foreach (['carts' => 'Abandoned carts', 'log' => 'Reminder log', 'settings' => 'Settings'] as $k => $l): if ($k === 'settings' && !$manage) { continue; } ?>
        <li class="nav-item"><a class="nav-link<?= $view === $k ? ' active' : '' ?>" href="<?= e(url($path, $q + ['view' => $k])) ?>"<?= $view === $k ? ' aria-current="page"' : '' ?>><?= e($l) ?></a></li>
    <?php endforeach; ?>
</ul>

<?php if ($view === 'carts'): ?>
    <?= View::partial('partials/table/toolbar', ['id' => 'ac-carts', 'path' => $path, 'lp' => $lp, 'label' => 'Search abandoned carts',
        'placeholder' => 'Customer name, email, phone or cart number', 'keep' => $q + ['view' => 'carts']]) ?>
    <div class="wm-panel">
        <div id="ac-carts" class="wm-table-region" data-table-region data-loading-text="Loading carts…">
            <div class="table-responsive"><table class="table table-hover align-middle mb-0">
                <caption class="visually-hidden">Abandoned carts</caption>
                <thead><tr><th scope="col">Cart</th><th scope="col">Customer</th><th scope="col" class="text-end">Items</th><th scope="col" class="text-end">Value now</th>
                    <th scope="col">Last activity</th><th scope="col" class="text-end">Reminders</th><th scope="col">Came back</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): $guest = $r['user_id'] === null; ?>
                    <tr>
                        <td><code>#<?= (int) $r['id'] ?></code></td>
                        <td><?php if ($guest): ?>
                                <span class="badge badge-soft-muted">Guest</span> <?= $r['lead_email'] ? e($full ? (string) $r['lead_name'] : '') . '<div class="small text-muted">' . e($mask($r['lead_email'], null)) . '</div>' : '<span class="small text-muted">No contact details</span>' ?>
                            <?php else: ?>
                                <?= e(trim($r['first_name'] . ' ' . $r['last_name'])) ?><div class="small text-muted"><?= e($mask($r['email'], $r['phone'])) ?></div>
                            <?php endif; ?></td>
                        <td class="text-end"><?= (int) $r['items'] ?></td>
                        <td class="text-end text-nowrap"><?= e(money($r['value'])) ?></td>
                        <td class="small text-nowrap"><?= e(date('d M Y H:i', strtotime((string) $r['seen']))) ?></td>
                        <td class="text-end"><?= (int) $r['reminders'] ?></td>
                        <td class="small"><?= $r['clicked_at'] ? '<span class="badge badge-soft-success">Yes</span> ' . e(date('d M H:i', strtotime((string) $r['clicked_at']))) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?><?= empty_row(7, $lp->q !== '', 'No abandoned carts in this period.') ?><?php endif; ?>
                </tbody>
            </table></div>
            <?= View::partial('partials/table/footer', ['lp' => $lp, 'path' => $path, 'id' => 'ac-carts', 'noun' => 'carts']) ?>
        </div>
    </div>

<?php elseif ($view === 'log'): ?>
    <?= View::partial('partials/table/toolbar', ['id' => 'ac-log', 'path' => $path, 'lp' => $lp, 'label' => 'Search reminders',
        'placeholder' => 'Customer, email, phone or reason', 'keep' => $q + ['view' => 'log'],
        'filters' => [
            ['name' => 'channel', 'label' => 'Channel', 'value' => $channel, 'options' => ['' => 'Email and SMS', 'email' => 'Email', 'sms' => 'SMS']],
            ['name' => 'status', 'label' => 'Status', 'value' => $status, 'options' => ['' => 'Any status', 'sent' => 'Sent', 'queued' => 'Waiting', 'failed' => 'Failed',
                'suppressed' => 'Not allowed', 'cancelled' => 'Stopped', 'logged' => 'Logged (not sent)', 'recovered' => 'Recovered an order']],
        ]]) ?>
    <div class="wm-panel">
        <div id="ac-log" class="wm-table-region" data-table-region data-loading-text="Loading reminders…">
            <div class="table-responsive"><table class="table table-hover align-middle mb-0">
                <caption class="visually-hidden">Reminder log</caption>
                <thead><tr><th scope="col">Reminder</th><th scope="col">To</th><th scope="col">Status</th><th scope="col">Planned / sent</th><th scope="col" class="text-end">Attempts</th>
                    <th scope="col">Clicked</th><th scope="col">Recovered</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): $p = (array) json_decode((string) $r['payload'], true); [$cls, $txt] = $statusBadge[$r['status']] ?? ['badge-soft-muted', (string) $r['status']]; ?>
                    <tr>
                        <td><span class="fw-semibold"><?= $r['channel'] === 'sms' ? 'SMS' : 'Email' ?></span> · step <?= (int) ($p['step'] ?? 1) ?><div class="small text-muted">Cart #<?= (int) ($p['cart_id'] ?? 0) ?></div></td>
                        <td class="small"><?= e(trim((string) $r['who']) ?: '—') ?><div class="text-muted"><?= e($r['channel'] === 'sms' ? ($full ? Validator::displayPhone((string) $r['phone']) : '••• ' . substr((string) $r['phone'], -3)) : $mask($r['email'], null)) ?></div></td>
                        <td><span class="badge <?= e($cls) ?>"><?= e($txt) ?></span>
                            <?php if ($r['provider_status']): ?><div class="small text-muted">Gateway: <?= e((string) $r['provider_status']) ?></div><?php endif; ?>
                            <?php if ($r['reason']): ?><div class="small text-muted"><?= e((string) $r['reason']) ?></div><?php endif; ?></td>
                        <td class="small text-nowrap"><?= $r['sent_at'] ? 'Sent ' . e(date('d M H:i', strtotime((string) $r['sent_at']))) : 'Planned ' . e(date('d M H:i', strtotime((string) $r['scheduled_at']))) ?></td>
                        <td class="text-end"><?= (int) $r['attempts'] ?></td>
                        <td class="small text-nowrap"><?= $r['clicked_at'] ? e(date('d M H:i', strtotime((string) $r['clicked_at']))) : '—' ?></td>
                        <td class="small"><?= $r['order_number'] ? e((string) $r['order_number']) . '<div class="text-muted">' . e(money($r['recovered_amount'])) . '</div>' : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?><?= empty_row(7, $lp->q !== '' || $channel !== '' || $status !== '', 'No reminders in this period.') ?><?php endif; ?>
                </tbody>
            </table></div>
            <?= View::partial('partials/table/footer', ['lp' => $lp, 'path' => $path, 'id' => 'ac-log', 'noun' => 'reminders']) ?>
        </div>
    </div>

<?php elseif ($manage && $automation !== null): $chan = explode(',', (string) $automation['channels']); ?>
    <form class="wm-panel wm-panel-body row g-3" method="post" action="<?= e(url($path . '/settings')) ?>" novalidate>
        <?= csrf_field() ?>
        <div class="col-12">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" id="is_enabled" name="is_enabled" value="1" <?= $on ? 'checked' : '' ?>>
                <label class="form-check-label fw-semibold" for="is_enabled">Send cart reminders</label>
            </div>
        </div>
        <fieldset class="col-md-6">
            <legend class="form-label fs-6 mb-1">Send by</legend>
            <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" id="ch-email" name="channels[]" value="email" <?= in_array('email', $chan, true) ? 'checked' : '' ?>><label class="form-check-label" for="ch-email">Email</label></div>
            <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" id="ch-sms" name="channels[]" value="sms" <?= in_array('sms', $chan, true) ? 'checked' : '' ?>><label class="form-check-label" for="ch-sms">SMS</label></div>
            <?= field_error('channels') ?>
        </fieldset>
        <div class="col-md-6">
            <label class="form-label" for="cart_abandon_minutes">First reminder after (minutes without activity)</label>
            <input class="form-control<?= invalid('cart_abandon_minutes') ?>" id="cart_abandon_minutes" name="cart_abandon_minutes" inputmode="numeric" value="<?= e((string) fv('cart_abandon_minutes', $values['cart_abandon_minutes'] ?? '60')) ?>" aria-describedby="camHelp">
            <div class="form-text" id="camHelp">15 to 1440. Counted from the last time the customer changed the cart or opened the cart or checkout. Signing out or closing the browser does not send anything early.</div>
            <?= field_error('cart_abandon_minutes') ?>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="delay2_hours">Second reminder after (hours, optional)</label>
            <input class="form-control<?= invalid('delay2_hours') ?>" id="delay2_hours" name="delay2_hours" inputmode="numeric" value="<?= e((string) fv('delay2_hours', (string) ($automation['delay2_hours'] ?? ''))) ?>" aria-describedby="d2Help">
            <div class="form-text" id="d2Help">From the same last activity. Leave empty for one reminder only.</div>
            <?= field_error('delay2_hours') ?>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="cart_reminder_link_days">Cart links work for (days)</label>
            <input class="form-control<?= invalid('cart_reminder_link_days') ?>" id="cart_reminder_link_days" name="cart_reminder_link_days" inputmode="numeric" value="<?= e((string) fv('cart_reminder_link_days', $values['cart_reminder_link_days'] ?? '7')) ?>">
            <?= field_error('cart_reminder_link_days') ?>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="marketing_max_attempts">Attempts when sending fails</label>
            <input class="form-control<?= invalid('marketing_max_attempts') ?>" id="marketing_max_attempts" name="marketing_max_attempts" inputmode="numeric" value="<?= e((string) fv('marketing_max_attempts', $values['marketing_max_attempts'] ?? '3')) ?>" aria-describedby="maHelp">
            <div class="form-text" id="maHelp">1 to 10, for all marketing messages. Retried after 30, 60… minutes.</div>
            <?= field_error('marketing_max_attempts') ?>
        </div>
        <div class="col-12">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="cart_recovery_guests" name="cart_recovery_guests" value="1" <?= ($values['cart_recovery_guests'] ?? '1') === '1' ? 'checked' : '' ?> aria-describedby="gHelp">
                <label class="form-check-label" for="cart_recovery_guests">Remind guests who signed up for the newsletter</label>
                <div class="form-text" id="gHelp">A guest can only be reached when they gave their email or phone with consent in the same browser.</div>
            </div>
        </div>
        <div class="col-12 small text-muted">Quiet hours (<?= e((string) ($values['marketing_quiet_start'] ?? '20:00')) ?>–<?= e((string) ($values['marketing_quiet_end'] ?? '08:00')) ?>), the daily and weekly limits and the global pause are in <a href="<?= e(url('/admin/marketing/settings')) ?>">Marketing settings</a>. The email and SMS wording is under <a href="<?= e(url('/admin/messages')) ?>">Messages</a> → "Items left in your cart". Reminders are sent by the scheduler (<code>bin/marketing-run.php</code>, every 15 minutes).</div>
        <div class="col-12"><button class="btn btn-wm-green" type="submit">Save settings</button></div>
    </form>
<?php endif; ?>
