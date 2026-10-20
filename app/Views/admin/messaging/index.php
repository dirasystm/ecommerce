<?php
use Waimart\Core\Auth;
use Waimart\Core\View;
use Waimart\Services\SettingsService;
use Waimart\Services\SmsGateway;

/**
 * @var array<string,array<string,array{total:int,today:int,month:int}>> $stats channel => status => counts
 * @var int $delivered @var array<string,string> $sms @var array<string,mixed> $token @var array<string,mixed> $mail @var array<string,mixed> $balance
 * @var list<array<string,mixed>> $rows @var int $total @var int $page @var int $pages @var int $perPage
 * @var string $channel @var string $status @var string $search @var list<string> $noResend
 */
$n = fn (string $ch, string $st, string $period = 'total'): int => (int) ($stats[$ch][$st][$period] ?? 0);
$live = ($sms['sms_driver'] ?? '') === 'talksasa';
$filters = array_filter(['channel' => $channel, 'status' => $status, 'q' => $search, 'page' => $page > 1 ? (string) $page : '', 'per' => $perPage !== 25 ? (string) $perPage : '']);
$statusLabel = ['sent' => 'Sent', 'failed' => 'Failed', 'logged' => 'Not sent (log only)', 'queued' => 'Sending'];
$statusClass = ['sent' => 'badge-soft-success', 'failed' => 'badge-soft-danger', 'logged' => 'badge-soft-muted', 'queued' => 'badge-soft-warning'];
$canSecrets = Auth::can('secrets.manage');
?>
<div class="wm-admin-head"><h1>Email &amp; SMS</h1></div>

<?php if (!$live): ?>
    <div class="alert alert-warning small" role="status"><strong>SMS is switched off.</strong> Customer SMS are kept in the message log below and are not delivered to phones.
        Save the Talksasa API token and sender ID, send a test SMS, then choose “Send through Talksasa”.</div>
<?php endif; ?>

<h2 class="fs-6 fw-bold text-uppercase text-muted mb-2">SMS</h2>
<div class="row g-3 mb-3">
    <div class="col-6 col-xl-3"><div class="wm-panel wm-stat h-100">
        <div class="wm-stat-label">SMS balance</div>
        <?php if ($balance['state'] === 'ok'): ?>
            <div class="wm-stat-value"><?= e((string) $balance['balance']) ?></div>
            <div class="small text-muted">Checked <?= e(date('H:i', (int) $balance['checked_at'])) ?><?= !empty($balance['expires']) ? ' · expires ' . e((string) $balance['expires']) : '' ?>
                · <a href="<?= e(url('/admin/messaging', ['refresh' => 1])) ?>">Refresh</a></div>
        <?php elseif ($balance['state'] === 'no_token'): ?>
            <div class="wm-stat-value text-muted">—</div>
            <div class="small text-muted">Save the API token to see the balance.</div>
        <?php else: ?>
            <div class="wm-stat-value text-muted">—</div>
            <div class="small text-danger"><?= e((string) $balance['error']) ?> <a href="<?= e(url('/admin/messaging', ['refresh' => 1])) ?>">Try again</a></div>
        <?php endif; ?>
    </div></div>
    <div class="col-6 col-xl-3"><div class="wm-panel wm-stat h-100">
        <div class="wm-stat-label">Sent SMS</div>
        <div class="wm-stat-value"><?= number_format($n('sms', 'sent')) ?></div>
        <div class="small text-muted">Today <?= number_format($n('sms', 'sent', 'today')) ?> · 30 days <?= number_format($n('sms', 'sent', 'month')) ?><?= $delivered ? ' · ' . number_format($delivered) . ' delivered' : '' ?></div>
    </div></div>
    <div class="col-6 col-xl-3"><div class="wm-panel wm-stat h-100">
        <div class="wm-stat-label">Failed SMS</div>
        <div class="wm-stat-value<?= $n('sms', 'failed') ? ' text-danger' : '' ?>"><?= number_format($n('sms', 'failed')) ?></div>
        <div class="small text-muted">Today <?= number_format($n('sms', 'failed', 'today')) ?> · 30 days <?= number_format($n('sms', 'failed', 'month')) ?>
            <?php if ($n('sms', 'failed')): ?>· <a href="<?= e(url('/admin/messaging', ['channel' => 'sms', 'status' => 'failed'])) ?>#log">View</a><?php endif; ?></div>
    </div></div>
    <div class="col-6 col-xl-3"><div class="wm-panel wm-stat h-100">
        <div class="wm-stat-label">SMS not sent (log only)</div>
        <div class="wm-stat-value"><?= number_format($n('sms', 'logged')) ?></div>
        <div class="small text-muted">Recorded while SMS was switched off</div>
    </div></div>
</div>

<h2 class="fs-6 fw-bold text-uppercase text-muted mb-2">Email</h2>
<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3"><div class="wm-panel wm-stat h-100">
        <div class="wm-stat-label">Sending method</div>
        <div class="fw-bold mt-1"><?= $mail['driver'] === 'smtp' ? 'Mail server' : 'Mail log only' ?></div>
        <div class="small text-muted"><?= $mail['driver'] === 'smtp' ? e(($mail['host'] ?: '(no server set)') . ':' . (int) $mail['port']) : 'Emails are not delivered' ?>
            · <a href="<?= e(url('/admin/settings/email')) ?>">Settings</a></div>
    </div></div>
    <div class="col-6 col-xl-3"><div class="wm-panel wm-stat h-100">
        <div class="wm-stat-label">Sent emails</div>
        <div class="wm-stat-value"><?= number_format($n('email', 'sent')) ?></div>
        <div class="small text-muted">Today <?= number_format($n('email', 'sent', 'today')) ?> · 30 days <?= number_format($n('email', 'sent', 'month')) ?></div>
    </div></div>
    <div class="col-6 col-xl-3"><div class="wm-panel wm-stat h-100">
        <div class="wm-stat-label">Failed emails</div>
        <div class="wm-stat-value<?= $n('email', 'failed') ? ' text-danger' : '' ?>"><?= number_format($n('email', 'failed')) ?></div>
        <div class="small text-muted">Today <?= number_format($n('email', 'failed', 'today')) ?> · 30 days <?= number_format($n('email', 'failed', 'month')) ?>
            <?php if ($n('email', 'failed')): ?>· <a href="<?= e(url('/admin/messaging', ['channel' => 'email', 'status' => 'failed'])) ?>#log">View</a><?php endif; ?></div>
    </div></div>
    <div class="col-6 col-xl-3"><div class="wm-panel wm-stat h-100">
        <div class="wm-stat-label">Emails not sent (log only)</div>
        <div class="wm-stat-value"><?= number_format($n('email', 'logged')) ?></div>
        <div class="small text-muted">Written to the mail log</div>
    </div></div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <section class="wm-panel h-100" aria-labelledby="gwTitle">
            <div class="wm-panel-head" id="gwTitle">SMS gateway (Talksasa)</div>
            <div class="wm-panel-body">
                <form method="post" action="<?= e(url('/admin/messaging/sms')) ?>" novalidate class="row g-3">
                    <?= csrf_field() ?>
                    <?php foreach (SettingsService::fieldsOf('sms') as $key => [$label, $rule, $help, , $widget]): $val = (string) fv($key, $sms[$key] ?? ''); $hid = $help ? $key . 'Help' : null; ?>
                        <div class="col-12">
                            <label class="form-label" for="<?= e($key) ?>"><?= e($label) ?></label>
                            <?php if ($widget === 'select'): ?>
                                <select class="form-select<?= invalid($key) ?>" id="<?= e($key) ?>" name="<?= e($key) ?>">
                                    <?php foreach (SettingsService::OPTIONS[$key] as $opt => $optLabel): ?>
                                        <option value="<?= e((string) $opt) ?>" <?= $val === (string) $opt ? 'selected' : '' ?>><?= e($optLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <input class="form-control<?= invalid($key) ?>" id="<?= e($key) ?>" name="<?= e($key) ?>" value="<?= e($val) ?>"
                                    <?= $key === 'sms_sender_id' ? 'maxlength="11" autocomplete="off"' : 'type="url" maxlength="190" placeholder="' . e(SmsGateway::DEFAULT_URL) . '"' ?><?= $hid ? ' aria-describedby="' . e($hid) . '"' : '' ?>>
                            <?php endif; ?>
                            <?php if ($help): ?><div class="form-text" id="<?= e($hid) ?>"><?= e($help) ?></div><?php endif; ?>
                            <?= field_error($key) ?>
                        </div>
                    <?php endforeach; ?>
                    <div class="col-12"><button class="btn btn-wm-green" type="submit">Save SMS settings</button></div>
                </form>

                <hr>
                <h3 class="fs-6 fw-bold">API token</h3>
                <p class="small mb-2">
                    <?php if (!$token['set']): ?>
                        <span class="badge badge-soft-muted">Not set</span>
                    <?php elseif (!$token['readable']): ?>
                        <span class="badge badge-soft-danger">Cannot be read</span> The app key changed since it was saved. Enter the token again.
                    <?php else: ?>
                        <span class="badge badge-soft-success">Saved</span> <?= e(date('d M Y H:i', strtotime((string) $token['updated_at']))) ?><?= $token['updated_by'] ? ' by ' . e($token['updated_by']) : '' ?>.
                    <?php endif; ?>
                    Copy it from Talksasa → Developers (“API Token”, Reveal token). It is stored encrypted and never shown again.
                </p>
                <?php if ($canSecrets): ?>
                    <form class="row g-2 align-items-end" method="post" action="<?= e(url('/admin/messaging/token')) ?>" autocomplete="off" novalidate>
                        <?= csrf_field() ?>
                        <div class="col-md-8">
                            <label class="form-label" for="secret_sms_api_token"><?= $token['set'] ? 'Replace the token' : 'API token' ?></label>
                            <input class="form-control<?= invalid('secret_sms_api_token') ?>" type="password" id="secret_sms_api_token" name="secret_sms_api_token" maxlength="500" autocomplete="new-password" spellcheck="false">
                            <?= field_error('secret_sms_api_token') ?>
                        </div>
                        <div class="col-md-4"><button class="btn btn-wm-green w-100" type="submit">Save token</button></div>
                    </form>
                    <?php if ($token['set']): ?>
                        <form class="mt-2" method="post" action="<?= e(url('/admin/messaging/token')) ?>" data-confirm="Remove the saved SMS API token? SMS will stop being sent until a new token is saved.">
                            <?= csrf_field() ?><input type="hidden" name="remove" value="1">
                            <button class="btn btn-sm btn-outline-danger" type="submit">Remove the saved token</button>
                        </form>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="small text-muted mb-0">Only staff allowed to manage credentials can change the token.</p>
                <?php endif; ?>
            </div>
        </section>
    </div>
    <div class="col-lg-6">
        <section class="wm-panel mb-3" aria-labelledby="tsTitle">
            <div class="wm-panel-head" id="tsTitle">Send a test SMS</div>
            <div class="wm-panel-body">
                <form method="post" action="<?= e(url('/admin/messaging/test-sms')) ?>" novalidate class="row g-2">
                    <?= csrf_field() ?>
                    <div class="col-12">
                        <label class="form-label" for="test_phone">Phone number</label>
                        <input class="form-control<?= invalid('phone') ?>" id="test_phone" name="phone" type="tel" inputmode="tel" autocomplete="off" placeholder="0712 345 678" value="<?= e((string) old('phone')) ?>" required>
                        <?= field_error('phone') ?>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="test_message">Message <span class="text-muted small">(optional)</span></label>
                        <textarea class="form-control<?= invalid('message') ?>" id="test_message" name="message" rows="2" maxlength="300" aria-describedby="testMsgHelp"><?= e((string) old('message')) ?></textarea>
                        <div class="form-text" id="testMsgHelp">Sent through Talksasa with the saved token and sender ID, even while customer SMS is switched off. Uses your SMS credit.</div>
                        <?= field_error('message') ?>
                    </div>
                    <div class="col-12"><button class="btn btn-outline-secondary" type="submit"<?= $token['set'] ? '' : ' disabled' ?>><i class="bi bi-send" aria-hidden="true"></i> Send test SMS</button>
                        <?php if (!$token['set']): ?><span class="small text-muted ms-1">Save the API token first.</span><?php endif; ?></div>
                </form>
            </div>
        </section>
        <section class="wm-panel mb-3" aria-labelledby="teTitle">
            <div class="wm-panel-head" id="teTitle">Send a test email</div>
            <div class="wm-panel-body">
                <form method="post" action="<?= e(url('/admin/messaging/test-email')) ?>" novalidate class="row g-2">
                    <?= csrf_field() ?>
                    <div class="col-12">
                        <label class="form-label" for="test_email">Email address</label>
                        <input class="form-control<?= invalid('email') ?>" id="test_email" name="email" type="email" autocomplete="off" value="<?= e((string) (old('email') ?: (Auth::user()['email'] ?? ''))) ?>" required>
                        <?= field_error('email') ?>
                    </div>
                    <div class="col-12"><button class="btn btn-outline-secondary" type="submit"><i class="bi bi-envelope" aria-hidden="true"></i> Send test email</button>
                        <span class="small text-muted ms-1">Uses the saved <a href="<?= e(url('/admin/settings/email')) ?>">email settings</a>.</span></div>
                </form>
            </div>
        </section>
        <section class="wm-panel" aria-labelledby="dlTitle">
            <div class="wm-panel-head" id="dlTitle">Delivery reports</div>
            <div class="wm-panel-body small">
                <p class="mb-2">“Sent” means Talksasa accepted the SMS. Ask Talksasa whether recent SMS (last 3 days) reached the phones; any it reports as not delivered move to Failed.</p>
                <form method="post" action="<?= e(url('/admin/messaging/delivery')) ?>"><?= csrf_field() ?>
                    <button class="btn btn-sm btn-outline-secondary" type="submit"<?= $token['set'] ? '' : ' disabled' ?>><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Check delivery reports</button></form>
            </div>
        </section>
    </div>
</div>

<h2 class="fs-5 fw-bold mb-2" id="log">Message log</h2>
<form class="d-flex flex-wrap gap-2 align-items-end mb-3" method="get" action="<?= e(url('/admin/messaging')) ?>#log" role="search">
    <div class="flex-grow-1" style="min-width:220px;max-width:340px"><label class="form-label small mb-0" for="q">Search</label>
        <input class="form-control form-control-sm" type="search" id="q" name="q" maxlength="100" value="<?= e($search) ?>" placeholder="Phone, email, order number, text…"></div>
    <div><label class="form-label small mb-0" for="channel">Type</label>
        <select class="form-select form-select-sm" id="channel" name="channel">
            <?php foreach (['' => 'SMS and email', 'sms' => 'SMS', 'email' => 'Email'] as $k => $l): ?><option value="<?= e($k) ?>" <?= $channel === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div><label class="form-label small mb-0" for="status">Status</label>
        <select class="form-select form-select-sm" id="status" name="status">
            <?php foreach (['' => 'Any'] + $statusLabel as $k => $l): ?><option value="<?= e($k) ?>" <?= $status === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div><label class="form-label small mb-0" for="per">Per page</label>
        <select class="form-select form-select-sm" id="per" name="per"><?php foreach ([25, 50, 100] as $pp): ?><option value="<?= $pp ?>" <?= $perPage === $pp ? 'selected' : '' ?>><?= $pp ?></option><?php endforeach; ?></select></div>
    <button class="btn btn-sm btn-outline-secondary" type="submit">Search</button>
    <?php if ($channel !== '' || $status !== '' || $search !== ''): ?><a class="btn btn-sm btn-link" href="<?= e(url('/admin/messaging')) ?>#log">Clear</a><?php endif; ?>
</form>

<?php $first = $total ? ($page - 1) * $perPage + 1 : 0; $last = min($total, $page * $perPage); ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
    <p class="small text-muted mb-0" role="status">Showing <?= number_format($first) ?>–<?= number_format($last) ?> of <?= number_format($total) ?><?= $search !== '' ? ' matching “' . e($search) . '”' : '' ?></p>
    <?= View::partial('partials/pagination', ['page' => $page, 'pages' => $pages, 'path' => '/admin/messaging', 'compact' => true]) ?>
</div>
<div class="wm-panel"><div class="table-responsive">
    <table class="table table-sm align-top mb-0">
        <caption class="visually-hidden">Messages sent to customers, sellers and staff, newest first</caption>
        <thead><tr><th scope="col">When</th><th scope="col">Type</th><th scope="col">To</th><th scope="col">Message</th><th scope="col">Order</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td class="small text-nowrap"><?= e(date('d M Y H:i', strtotime((string) $r['created_at']))) ?></td>
                <td class="small"><?= $r['channel'] === 'sms' ? '<i class="bi bi-phone" aria-hidden="true"></i> SMS' : '<i class="bi bi-envelope" aria-hidden="true"></i> Email' ?></td>
                <td class="small text-break"><?= e($r['channel'] === 'sms' && preg_match('/^254\d{9}$/', (string) $r['recipient']) ? \Waimart\Core\Validator::displayPhone((string) $r['recipient']) : (string) $r['recipient']) ?></td>
                <td class="small" style="max-width:380px"><?= $r['subject'] ? '<strong>' . e((string) $r['subject']) . '</strong><br>' : '' ?><?= e(mb_strimwidth((string) $r['body'], 0, 200, '…')) ?>
                    <div class="text-muted"><code><?= e((string) $r['template']) ?></code></div></td>
                <td class="small"><?php if ($r['order_number']): ?><a href="<?= e(url('/admin/orders/' . $r['order_id'])) ?>"><?= e((string) $r['order_number']) ?></a><?php endif; ?></td>
                <td class="small"><span class="badge <?= $statusClass[$r['status']] ?? 'badge-soft-muted' ?>"><?= e($statusLabel[$r['status']] ?? (string) $r['status']) ?></span>
                    <?php if ($r['provider_status']): ?><div class="text-muted">Report: <?= e((string) $r['provider_status']) ?></div><?php endif; ?>
                    <?php if ($r['last_error']): ?><div class="text-danger"><?= e((string) $r['last_error']) ?></div><?php endif; ?></td>
                <td><?php if ($r['status'] === 'failed' && !in_array($r['template'], $noResend, true)): ?>
                    <form method="post" action="<?= e(url('/admin/messaging/' . $r['id'] . '/retry')) ?>"><?= csrf_field() ?>
                        <?php foreach ($filters as $fk => $fv): ?><input type="hidden" name="<?= e($fk) ?>" value="<?= e($fv) ?>"><?php endforeach; ?>
                        <button class="btn btn-sm btn-outline-secondary" type="submit">Re-send</button></form>
                <?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">No messages<?= $search !== '' || $channel !== '' || $status !== '' ? ' match these filters' : ' yet' ?>.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div></div>
<?= View::partial('partials/pagination', ['page' => $page, 'pages' => $pages, 'path' => '/admin/messaging']) ?>
