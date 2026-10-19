<?php
use Waimart\Services\AuthEvents;

/** @var list<array<string,mixed>> $rows @var bool $staffOnly @var string $event @var int $failed24h */
$bad = ['login_failed', 'mfa_failed', 'login_locked'];
?>
<div class="wm-admin-head"><h1>Sign-in activity</h1></div>
<p class="small text-muted">Every sign-in, failed attempt, two-step check and account security change (latest 200). What was typed is shown masked; passwords and codes are never recorded.
    This log cannot be edited or deleted. Failed or blocked attempts in the last 24 hours: <strong><?= $failed24h ?></strong>.</p>
<form class="d-flex flex-wrap gap-2 align-items-end mb-3" method="get" action="<?= e(url('/admin/audit/sign-ins')) ?>">
    <div><label class="form-label small mb-0" for="who">Accounts</label>
        <select class="form-select form-select-sm" id="who" name="who">
            <option value="staff" <?= $staffOnly ? 'selected' : '' ?>>Staff (and the staff sign-in page)</option>
            <option value="all" <?= $staffOnly ? '' : 'selected' ?>>Everyone</option>
        </select></div>
    <div><label class="form-label small mb-0" for="event">Event</label>
        <select class="form-select form-select-sm" id="event" name="event"><option value="">All</option>
            <?php foreach (AuthEvents::LABELS as $k => $l): ?><option value="<?= e($k) ?>" <?= $event === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <button class="btn btn-sm btn-outline-secondary" type="submit">Show</button>
</form>
<div class="wm-panel"><div class="table-responsive">
    <table class="table table-sm table-hover mb-0 align-middle">
        <thead><tr><th>When</th><th>Event</th><th>Account</th><th>Typed</th><th>IP address</th><th>Browser</th></tr></thead>
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
        <?php if (!$rows): ?><tr><td colspan="6" class="text-muted small">No activity yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div></div>
