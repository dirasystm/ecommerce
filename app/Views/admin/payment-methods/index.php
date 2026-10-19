<?php
use Waimart\Core\Auth;

/** @var array<string,array<string,mixed>> $methods @var array<string,array<string,mixed>> $health @var string $maxSubmissions */
$badge = ['on' => 'badge-soft-success', 'off' => 'badge-soft-muted', 'incomplete' => 'badge-soft-warning'];
?>
<div class="wm-admin-head"><h1>Payment methods</h1></div>
<p class="small text-muted">Choose which methods customers can use at checkout, what they are called, and the instructions customers see.
    These are manual methods: customers pay by M-Pesa and enter the transaction code, and staff verify each payment against the M-Pesa statement
    (<?php if (Auth::can('payments.view')): ?><a href="<?= e(url('/admin/payments')) ?>">M-Pesa payments</a><?php else: ?>M-Pesa payments<?php endif; ?>);
    cash on delivery is confirmed by staff. No payment gateway is connected, so there are no gateway callbacks or keys to manage.</p>
<div class="wm-panel mb-3"><div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
        <thead><tr><th>Method</th><th>At checkout</th><th>Limits</th><th>Waiting</th><th>Last 30 days</th><th>Last verified</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($methods as $code => $m): $h = $health[$code]; ?>
            <tr>
                <td><a class="fw-semibold" href="<?= e(url('/admin/payment-methods/' . $code)) ?>"><?= e($m['name']) ?></a>
                    <div class="small text-muted">Badge: <?= e($m['short_name']) ?> · order <?= (int) $m['sort_order'] ?></div></td>
                <td><span class="badge <?= $badge[$h['status']] ?>"><?= e($h['status_label']) ?></span></td>
                <td class="small text-nowrap"><?php
                    $lim = array_filter([$m['min_order_total'] !== null ? 'from ' . money($m['min_order_total']) : null, $m['max_order_total'] !== null ? 'up to ' . money($m['max_order_total']) : null]);
                    echo $lim ? e(implode(', ', $lim)) : '—'; ?></td>
                <td class="small"><?= $h['pending'] ? '<strong>' . (int) $h['pending'] . '</strong> ' . ($code === 'cod' ? 'to confirm' : 'to verify') : '—' ?></td>
                <td class="small text-nowrap"><?= $code === 'cod' ? '—' : (int) $h['verified_30d'] . ' verified · ' . (int) $h['rejected_30d'] . ' rejected' ?></td>
                <td class="small text-nowrap"><?= $h['last_verified'] ? e(date('d M Y H:i', strtotime((string) $h['last_verified']))) : '—' ?></td>
                <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/admin/payment-methods/' . $code)) ?>">Edit</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div></div>

<form class="wm-panel" method="post" action="<?= e(url('/admin/payment-methods/rules')) ?>" style="max-width:760px" novalidate>
    <?= csrf_field() ?>
    <div class="wm-panel-head">Rules for M-Pesa payments</div>
    <div class="wm-panel-body row g-3 align-items-end">
        <div class="col-md-6">
            <label class="form-label" for="max_payment_submissions">Max payment code submissions per order</label>
            <input class="form-control<?= invalid('max_payment_submissions') ?>" id="max_payment_submissions" name="max_payment_submissions" inputmode="numeric" value="<?= e((string) fv('max_payment_submissions', $maxSubmissions)) ?>" aria-describedby="maxSubHelp">
            <div class="form-text" id="maxSubHelp">How many times a customer may enter an M-Pesa code for one order (1–10). Limits guessing.</div>
            <?= field_error('max_payment_submissions') ?>
        </div>
        <div class="col-md-6"><button class="btn btn-wm-green" type="submit">Save rules</button></div>
    </div>
</form>
