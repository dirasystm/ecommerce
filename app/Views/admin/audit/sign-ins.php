<?php
use Waimart\Services\AuthEvents;

/** @var list<array<string,mixed>> $rows @var \Waimart\Core\ListParams $lp @var bool $staffOnly @var string $event @var int $failed24h */
$bad = ['login_failed', 'mfa_failed', 'login_locked'];
?>
<div class="wm-admin-head"><h1>Sign-in activity</h1></div>
<p class="small text-muted">Every sign-in, failed attempt, two-step check and account security change. What was typed is shown masked; passwords and codes are never recorded.
    This log cannot be edited or deleted. Failed or blocked attempts in the last 24 hours: <strong><?= $failed24h ?></strong>.</p>
<?= \Waimart\Core\View::partial('partials/table/toolbar', ['id' => 'signins', 'path' => '/admin/audit/sign-ins', 'lp' => $lp, 'label' => 'Search sign-in activity',
    'placeholder' => 'Account, what was typed or IP address',
    'filters' => [
        ['name' => 'who', 'label' => 'Accounts', 'value' => $staffOnly ? 'staff' : 'all', 'default' => 'staff', 'options' => ['staff' => 'Staff (and the staff sign-in page)', 'all' => 'Everyone']],
        ['name' => 'event', 'label' => 'Event', 'value' => $event, 'options' => ['' => 'All events'] + AuthEvents::LABELS],
    ]]) ?>
<div class="wm-panel">
    <div id="signins" class="wm-table-region" data-table-region data-loading-text="Loading sign-in activity…">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <caption class="visually-hidden">Sign-in activity</caption>
                <thead><tr><?= sort_th('When', 'when', $lp, '/admin/audit/sign-ins') ?><th scope="col">Event</th><th scope="col">Account</th><th scope="col">Typed</th><th scope="col">IP address</th><th scope="col">Browser</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="small text-nowrap"><?= e(date('d M Y H:i:s', strtotime((string) $r['created_at']))) ?></td>
                        <td class="small"><span class="badge <?= in_array($r['event'], $bad, true) ? 'badge-soft-danger' : 'badge-soft-muted' ?>"><?= e(AuthEvents::LABELS[$r['event']] ?? $r['event']) ?></span></td>
                        <td class="small"><?= $r['who'] ? e(trim((string) $r['who'])) . ((int) $r['is_staff'] ? ' <span class="text-muted">(staff)</span>' : '') : '<span class="text-muted">unknown</span>' ?></td>
                        <td class="small"><?= e((string) $r['identifier']) ?></td>
                        <td class="small"><?= e((string) $r['ip_address']) ?></td>
                        <td class="small text-muted text-truncate" style="max-width:220px"><?= e((string) $r['user_agent']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?><?= empty_row(6, $lp->q !== '' || $event !== '', 'No activity yet.') ?><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?= \Waimart\Core\View::partial('partials/table/footer', ['lp' => $lp, 'path' => '/admin/audit/sign-ins', 'id' => 'signins', 'noun' => 'events']) ?>
    </div>
</div>
