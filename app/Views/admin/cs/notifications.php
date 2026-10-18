<?php /** @var list<array<string,mixed>> $rows @var string $status @var list<string> $noResend */ ?>
<div class="wm-admin-head"><h1>Notifications outbox</h1></div>
<ul class="nav nav-pills mb-3 small">
    <?php foreach (['' => 'All', 'failed' => 'Failed', 'sent' => 'Sent', 'logged' => 'Logged (dev / SMS outbox)'] as $k => $l): ?>
        <li class="nav-item"><a class="nav-link<?= $status === $k ? ' active' : '' ?>" href="<?= e(url('/admin/notifications', array_filter(['status' => $k]))) ?>"><?= e($l) ?></a></li>
    <?php endforeach; ?>
</ul>
<p class="small text-muted">Every customer message is recorded here with secrets (links, codes) redacted. SMS messages stay in the outbox until an SMS provider is connected.</p>
<div class="wm-panel"><div class="table-responsive">
    <table class="table table-sm align-top mb-0">
        <thead><tr><th>When</th><th>Channel</th><th>To</th><th>Template</th><th>Order</th><th>Message</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $n): ?>
            <tr>
                <td class="small text-nowrap"><?= e(date('d M H:i', strtotime($n['created_at']))) ?></td>
                <td class="small"><?= e($n['channel']) ?></td>
                <td class="small"><?= e($n['recipient']) ?></td>
                <td class="small"><code><?= e($n['template']) ?></code></td>
                <td class="small"><?= e((string) $n['order_number']) ?></td>
                <td class="small" style="max-width:380px"><?= $n['subject'] ? '<strong>' . e($n['subject']) . '</strong><br>' : '' ?><?= e(mb_strimwidth((string) $n['body'], 0, 220, '…')) ?></td>
                <td><span class="badge <?= ['sent' => 'badge-soft-success', 'failed' => 'badge-soft-danger', 'logged' => 'badge-soft-muted'][$n['status']] ?? 'badge-soft-warning' ?>"><?= e($n['status']) ?></span>
                    <?php if ($n['last_error']): ?><div class="small text-danger"><?= e($n['last_error']) ?></div><?php endif; ?></td>
                <td><?php if ($n['status'] === 'failed' && $n['channel'] === 'email' && !in_array($n['template'], $noResend, true)): ?>
                    <form method="post" action="<?= e(url('/admin/notifications/' . $n['id'] . '/resend')) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary" type="submit">Re-send</button></form>
                <?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-4">No notifications.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div></div>
