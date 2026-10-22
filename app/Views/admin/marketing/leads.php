<?php
use Waimart\Core\Validator;
use Waimart\Core\View;
use Waimart\Services\Marketing\LeadService;

/**
 * @var list<array<string,mixed>> $rows @var \Waimart\Core\ListParams $lp @var array<string,string> $f
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
<?php
$stageOpts = ['' => 'All stages'] + LeadService::STAGES;
$campOpts = ['' => 'Any campaign'];
foreach ($campaigns as $c) {
    $campOpts[(string) $c['id']] = (string) $c['name'];
}
?>
<?= View::partial('partials/table/toolbar', ['id' => 'leads', 'path' => '/admin/marketing/leads', 'lp' => $lp, 'label' => 'Search leads',
    'placeholder' => $full ? 'Name, email or phone' : 'Name',
    'filters' => [
        ['name' => 'stage', 'label' => 'Stage', 'value' => (string) ($f['stage'] ?? ''), 'options' => $stageOpts],
        ['name' => 'consent', 'label' => 'Consent', 'value' => (string) ($f['consent'] ?? ''), 'options' => ['' => 'Any consent', 'email' => 'Email allowed', 'sms' => 'SMS allowed', 'none' => 'No marketing']],
        ['name' => 'campaign', 'label' => 'Campaign', 'value' => (string) ($f['campaign'] ?? ''), 'options' => $campOpts],
    ]]) ?>
<div class="wm-panel"><div id="leads" class="wm-table-region" data-table-region data-loading-text="Loading leads…"><div class="table-responsive">
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
        <?php if (!$rows): ?><?= empty_row(8, $lp->q !== '' || ($f['stage'] ?? '') !== '' || ($f['consent'] ?? '') !== '' || ($f['campaign'] ?? '') !== '', 'No leads yet. They appear when people sign up for the newsletter or create an account.') ?><?php endif; ?>
        </tbody>
    </table>
</div>
<?= View::partial('partials/table/footer', ['lp' => $lp, 'path' => '/admin/marketing/leads', 'id' => 'leads', 'noun' => 'leads']) ?>
</div></div>
