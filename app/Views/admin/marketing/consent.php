<?php
use Waimart\Core\View;
use Waimart\Services\Marketing\LeadService;

/**
 * @var array<string,mixed> $counts @var list<array<string,mixed>> $rows @var int $page @var int $pages
 * @var list<array<string,mixed>> $suppressed @var bool $full
 */
$customers = max(1, (int) $counts['customers']);
$sources = ['registration' => 'Sign-up form', 'preferences' => 'Privacy & marketing page', 'newsletter' => 'Newsletter form', 'unsubscribe_link' => 'Unsubscribe link',
            'admin' => 'Staff', 'cookie_banner' => 'Privacy banner', 'checkout' => 'Checkout'];
?>
<div class="wm-admin-head"><h1>Consent &amp; suppression</h1></div>
<?= View::partial('admin/marketing/_nav', ['active' => '/admin/marketing/consent']) ?>
<p class="small text-muted">Customers choose for themselves under My account → Privacy &amp; marketing, on the sign-up form, or with the unsubscribe link in every marketing message. Every change is kept with its date and the wording shown (Kenya Data Protection Act, 2019). Staff cannot switch marketing on for a customer.</p>
<div class="row g-3 mb-3">
    <?php foreach ([['Marketing email allowed', (int) $counts['email']], ['Marketing SMS allowed', (int) $counts['sms']], ['Personalisation switched off', (int) $counts['no_personal']]] as [$t, $n]): ?>
        <div class="col-sm-4"><div class="wm-panel wm-stat h-100">
            <div class="wm-stat-label"><?= e($t) ?></div>
            <div class="wm-stat-value"><?= number_format($n) ?></div>
            <div class="small text-muted"><?= number_format($n / $customers * 100, 1) ?>% of <?= number_format((int) $counts['customers']) ?> active customers</div>
        </div></div>
    <?php endforeach; ?>
</div>

<section class="wm-panel mb-3">
    <div class="wm-panel-head">Messages not sent (last 30 days)</div>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <caption class="visually-hidden">Why marketing messages were not sent</caption>
        <thead><tr><th scope="col">Reason</th><th scope="col">Channel</th><th scope="col" class="text-end">Messages</th><th scope="col">Latest</th></tr></thead>
        <tbody>
        <?php foreach ($suppressed as $s): ?>
            <tr><td class="small"><?= e((string) ($s['reason'] ?? 'Not given')) ?></td><td class="small"><?= e(strtoupper((string) $s['channel'])) ?></td>
                <td class="text-end small"><?= number_format((int) $s['n']) ?></td><td class="small text-nowrap"><?= e(date('d M Y H:i', strtotime((string) $s['latest']))) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$suppressed): ?><tr><td colspan="4" class="text-center text-muted small py-3">None.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</section>

<section class="wm-panel">
    <div class="wm-panel-head">Consent changes</div>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <caption class="visually-hidden">Consent history, newest first</caption>
        <thead><tr><th scope="col">When</th><th scope="col">Who</th><th scope="col">Channel</th><th scope="col">Choice</th><th scope="col">Where</th><th scope="col">Wording</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $email = $r['user_email'] ?? $r['lead_email']; ?>
            <tr>
                <td class="small text-nowrap"><?= e(date('d M Y H:i', strtotime((string) $r['created_at']))) ?></td>
                <td class="small"><?= e($full ? (string) ($email ?? '—') : LeadService::maskEmail($email)) ?></td>
                <td class="small"><?= e(['email' => 'Email', 'sms' => 'SMS', 'personalisation' => 'Personalisation'][$r['channel']] ?? (string) $r['channel']) ?></td>
                <td><span class="badge <?= $r['granted'] ? 'badge-soft-success' : 'badge-soft-danger' ?>"><?= $r['granted'] ? 'Allowed' : 'Refused / withdrawn' ?></span></td>
                <td class="small"><?= e($sources[$r['source']] ?? (string) $r['source']) ?></td>
                <td class="small"><?= e((string) $r['wording_version']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted small py-3">No consent changes recorded yet.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</section>
<?= View::partial('partials/pagination', ['page' => $page, 'pages' => $pages, 'path' => '/admin/marketing/consent']) ?>
