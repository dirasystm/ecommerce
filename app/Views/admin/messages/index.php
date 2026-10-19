<?php
use Waimart\Services\MessageTemplates;

/** @var list<array<string,mixed>> $rows */
$byGroup = [];
foreach ($rows as $t) {
    $byGroup[$t['group']][] = $t;
}
$channel = function (array $t, string $c): string {
    if (!in_array($c, $t['channels'], true)) {
        return '<span class="text-muted">—</span>';
    }
    if (in_array($c, $t['locked'], true)) {
        return '<span class="badge badge-soft-success">Always</span>';
    }
    return $t[$c . '_enabled'] ? '<span class="badge badge-soft-success">On</span>' : '<span class="badge badge-soft-muted">Off</span>';
};
?>
<div class="wm-admin-head"><h1>Messages</h1></div>
<p class="small text-muted">The emails and SMS customers and sellers receive. Edit the wording, or switch a message off. Placeholders such as
    <code>{order_number}</code> are filled in when the message is sent. Messages with codes, links or money are always sent.
    Who emails come from: Settings → Email &amp; SMS. Customers choose email/SMS updates in their account.</p>
<?php foreach (MessageTemplates::GROUPS as $g => $gTitle): if (empty($byGroup[$g])) { continue; } ?>
    <div class="wm-panel mb-3">
        <div class="wm-panel-head"><?= e($gTitle) ?></div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead><tr><th>Message</th><th>Email</th><th>SMS</th><th>Wording</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($byGroup[$g] as $t): ?>
                    <tr>
                        <td><a class="fw-semibold" href="<?= e(url('/admin/messages/' . $t['code'])) ?>"><?= e($t['name']) ?></a>
                            <?php if ($t['help']): ?><div class="small text-muted"><?= e($t['help']) ?></div><?php endif; ?></td>
                        <td><?= $channel($t, 'email') ?></td>
                        <td><?= $channel($t, 'sms') ?></td>
                        <td class="small text-nowrap"><?= $t['edited'] ? 'Edited ' . e(date('d M Y', strtotime((string) $t['updated_at']))) . ($t['updated_by'] ? ' · ' . e($t['updated_by']) : '') : 'Original' ?></td>
                        <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/admin/messages/' . $t['code'])) ?>">Edit</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endforeach; ?>
