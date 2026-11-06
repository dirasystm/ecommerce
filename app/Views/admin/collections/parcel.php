<?php
use Waimart\Services\OrderPresenter;

/**
 * The page a label's QR code opens on a staff member's phone: the parcel and its one next step.
 * @var array<string,mixed> $order @var array<string,mixed> $f @var bool $canDesk @var int $units @var ?string $returning
 */
$pickup = $order['fulfilment_method'] === 'pickup';
$status = (string) $order['status'];
// Cash still to take: a cash order not yet handed over (afterwards the question is whether it was recorded).
$cash = $order['payment_method'] === 'cod' && !in_array($status, ['cancelled', 'expired', 'collected', 'delivered', 'return_requested', 'returned'], true)
    ? \Waimart\Services\SellerOrderService::amountDue($order) : null;
$cashUnrecorded = $order['payment_method'] === 'cod' && in_array($status, ['collected', 'delivered'], true) && $order['payment_status'] === 'cod_pending';
?>
<div class="wm-parcel mx-auto">
    <div class="wm-panel wm-panel-body mb-3">
        <div class="d-flex justify-content-between align-items-start gap-2">
            <div>
                <div class="small text-muted text-uppercase fw-semibold">Parcel</div>
                <h1 class="h3 mb-1 font-monospace"><?= e($order['order_number']) ?></h1>
            </div>
            <span class="badge fs-6 <?= OrderPresenter::badgeClass($status) ?>"><?= e(OrderPresenter::status($status)) ?></span>
        </div>
        <dl class="row mb-0 mt-2 small">
            <dt class="col-5 text-muted fw-normal"><?= $pickup ? 'Pickup station' : 'Deliver to' ?></dt>
            <dd class="col-7 fw-semibold mb-1"><?= e($pickup ? (string) $f['pickup_name'] : $f['town'] . ', ' . $f['county_name']) ?></dd>
            <dt class="col-5 text-muted fw-normal">Customer</dt>
            <dd class="col-7 mb-1"><?= e(OrderPresenter::firstName((string) $order['customer_name'])) ?></dd>
            <dt class="col-5 text-muted fw-normal">Contents</dt>
            <dd class="col-7 mb-1"><?= $units ?> item<?= $units === 1 ? '' : 's' ?></dd>
            <dt class="col-5 text-muted fw-normal">Payment</dt>
            <dd class="col-7 mb-0"><?= $cash !== null ? '<span class="badge badge-soft-warning">Collect ' . e(money($cash)) . ' cash</span>'
                : ($cashUnrecorded ? '<span class="badge badge-soft-danger">Cash taken, not yet recorded</span>' : e(OrderPresenter::payment((string) $order['payment_status']))) ?></dd>
        </dl>
    </div>

    <?php if ($canDesk && $pickup && $status === 'processing'): ?>
        <form method="post" action="<?= e(url('/admin/collections/' . $order['id'] . '/arrived')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="from" value="parcel">
            <button class="btn btn-wm-green btn-lg w-100 py-3" type="submit" data-loading-text="Notifying the customer…">
                <i class="bi bi-check2-circle" aria-hidden="true"></i> Confirm arrival at <?= e((string) $f['pickup_name']) ?>
            </button>
            <p class="small text-muted mt-2 mb-0">The customer is sent an SMS and email with the station, their collection code, a reminder to bring ID and the last day to collect.</p>
        </form>
    <?php elseif ($canDesk && $pickup && $status === 'ready_for_collection'): ?>
        <a class="btn btn-wm-green btn-lg w-100 py-3" href="<?= e(url('/admin/collections/' . $order['id'])) ?>">
            <i class="bi bi-person-check" aria-hidden="true"></i> Hand over to the customer
        </a>
        <p class="small text-muted mt-2 mb-0">Waiting at the station. When the customer comes, open the hand-over: their collection code and ID are checked there.</p>
    <?php elseif ($status === 'collected'): ?>
        <div class="alert alert-success mb-0"><i class="bi bi-check-circle-fill me-1" aria-hidden="true"></i>Collected<?= !empty($f['collected_at']) ? ' on ' . e(date('j M Y \a\t H:i', strtotime((string) $f['collected_at']))) : '' ?>. Nothing more to do.</div>
    <?php elseif ($status === 'cancelled' && $returning === 'awaiting_return'): ?>
        <div class="alert alert-warning mb-0">This order was cancelled (not collected). Send the parcel back to the warehouse; it is listed under <a href="<?= e(url('/admin/collections', ['tab' => 'returns'])) ?>">Returns to warehouse</a>.</div>
    <?php elseif (in_array($status, ['cancelled', 'expired'], true)): ?>
        <div class="alert alert-warning mb-0">This order is <?= e(strtolower(OrderPresenter::status($status))) ?>. Do not hand the parcel over.</div>
    <?php elseif (!$pickup): ?>
        <div class="alert alert-info mb-0">This is a home-delivery parcel. It should not be at a pickup station.</div>
    <?php elseif (!$canDesk && $pickup && in_array($status, ['processing', 'ready_for_collection'], true)): ?>
        <div class="alert alert-info mb-0">Your role cannot use the Collection desk. Ask a station attendant or a manager.</div>
    <?php else: ?>
        <div class="alert alert-info mb-0">This parcel has not been sent to the station yet.</div>
    <?php endif; ?>

    <?php if (\Waimart\Core\Auth::can('orders.view')): ?>
        <a class="d-block text-center small mt-3" href="<?= e(url('/admin/orders/' . $order['id'])) ?>">Open the full order</a>
    <?php endif; ?>
</div>
