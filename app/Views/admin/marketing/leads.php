<?php
use Waimart\Core\Validator;
use Waimart\Core\View;
use Waimart\Services\Marketing\LeadService;

/**
 * @var list<array<string,mixed>> $rows @var int $total @var int $page @var int $pages @var array<string,string> $f
 * @var bool $full contact details visible (marketing.leads) @var list<array{id:int,name:string}> $campaigns
 */
$phone = fn (?string $p) => !$p ? '—' : ($full ? Validator::displayPhone($p) : substr(Validator::displayPhone($p), 0, 2) . '•• ••• ' . substr($p, -3));
?>
<div class="wm-admin-head">
    <h1>Leads</h1>
    <?php if ($full): ?><a class="btn btn-outline-secondary" href="<?= e(url('/admin/marketing/leads/export', array_filter($f))) ?>"><i class="bi bi-download" aria-hidden="true"></i> Export CSV</a><?php endif; ?>
</div>
<?= View::partial('admin/marketing/_nav', ['active' => '/admin/marketing/leads']) ?>
<p class="small text-muted">People who gave their contact details through the newsletter form or by creating an account. Only people who agreed are ever sent marketing.
    <?= $full ? 'Exports are recorded in the audit log; keep exported files safe and delete them when done (Data Protection Act).' : 'Contact details are hidden: you need the "See and export leads" permission.' ?></p>
<form class="d-flex flex-wrap gap-2 mb-3" method="get" action="<?= e(url('/admin/marketing/leads')) ?>" role="search">
    <label class="visually-hidden" for="lq">Search leads</label>
    <input class="form-control form-control-sm" type="search" id="lq" name="q" maxlength="100" placeholder="Name, email or phone" value="<?= e($f['q'] ?? '') ?>" style="max-width:240px">
    <label class="visually-hidden" for="stage">Stage</label>
    <select class="form-select form-select-sm" id="stage" name="stage" style="max-width:220px">
        <option value="">All stages</option>
        <?php foreach (LeadService::STAGES as $k => $l): ?><option value="<?= e($k) ?>" <?= ($f['stage'] ?? '') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
    </select>
    <label class="visually-hidden" for="consent">Consent</label>
    <select class="form-select form-select-sm" id="consent" name="consent" style="max-width:200px">
        <?php foreach (['' => 'Any consent', 'email' => 'Email allowed', 'sms' => 'SMS allowed', 'none' => 'No marketing'] as $k => $l): ?><option value="<?= e($k) ?>" <?= ($f['consent'] ?? '') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
    </select>
    <label class="visually-hidden" for="campaign">Campaign</label>
    <select class="form-select form-select-sm" id="campaign" name="campaign" style="max-width:220px">
        <option value="">Any campaign</option>
        <?php foreach ($campaigns as $c): ?><option value="<?= (int) $c['id'] ?>" <?= ($f['campaign'] ?? '') === (string) $c['id'] ? 'selected' : '' ?>><?= e((string) $c['name']) ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-sm btn-outline-secondary" type="submit">Filter</button>
</form>
<p class="small text-muted"><?= number_format($total) ?> lead<?= $total === 1 ? '' : 's' ?></p>
<?= View::partial('partials/pagination', ['page' => $page, 'pages' => $pages, 'path' => '/admin/marketing/leads', 'compact' => true]) ?>
<div class="wm-panel"><div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
        <caption class="visually-hidden">Leads</caption>
        <thead><tr><th scope="col">Lead</th><th scope="col">Phone</th><th scope="col">Stage</th><th scope="col">Consent</th><th scope="col">Campaign (first → last)</th><th scope="col">Interest</th><th scope="col">Last marketing</th><th scope="col">Seen</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><span class="fw-semibold"><?= e($full ? ((string) $r['name'] ?: '—') : (mb_substr((string) $r['name'], 0, 1) ?: '—') . '…') ?></span>
                    <div class="small text-muted"><?= e($full ? ((string) $r['email'] ?: '—') : LeadService::maskEmail($r['email'])) ?></div></td>
                <td class="small text-nowrap"><?= e($phone($r['phone'])) ?></td>
                <td><span class="badge badge-soft-muted"><?= e(LeadService::STAGES[$r['stage']] ?? $r['stage']) ?></span><div class="small text-muted"><?= e(ucfirst((string) $r['source'])) ?></div></td>
                <td class="small"><?= $r['consent_email'] ? '<span class="badge badge-soft-success">Email</span> ' : '' ?><?= $r['consent_sms'] ? '<span class="badge badge-soft-success">SMS</span>' : '' ?><?= !$r['consent_email'] && !$r['consent_sms'] ? '<span class="text-muted">None</span>' : '' ?></td>
                <td class="small"><?= e((string) ($r['first_campaign'] ?? '—')) ?><?= $r['last_campaign'] && $r['last_campaign'] !== $r['first_campaign'] ? ' → ' . e((string) $r['last_campaign']) : '' ?></td>
                <td class="small"><?= e((string) ($r['interest'] ?? '—')) ?></td>
                <td class="small text-nowrap"><?= $r['last_message_at'] ? e(date('d M Y', strtotime((string) $r['last_message_at']))) : '—' ?></td>
                <td class="small text-nowrap"><?= e(date('d M Y', strtotime((string) $r['last_seen_at']))) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-4">No leads yet. They appear when people sign up for the newsletter or create an account.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div></div>
<?= View::partial('partials/pagination', ['page' => $page, 'pages' => $pages, 'path' => '/admin/marketing/leads']) ?>
