<?php
use Waimart\Core\Auth;
use Waimart\Core\Validator;

/** @var array<string,mixed> $s @var list<array<string,mixed>> $members @var array<string,mixed>|null $payout @var array<string,mixed>|null $application @var int $productCount */
$platform = (int) $s['is_platform'] === 1;
$canManage = Auth::can('sellers.manage') && !$platform;
?>
<div class="wm-admin-head">
    <h1><?= e($s['name']) ?></h1>
    <div class="d-flex gap-2">
        <?= $s['status'] === 'active' ? '<span class="badge badge-soft-success fs-6">Active</span>' : '<span class="badge badge-soft-danger fs-6">' . e(ucfirst($s['status'])) . '</span>' ?>
        <?php if ((int) $s['is_verified']): ?><span class="badge badge-soft-success fs-6"><i class="bi bi-patch-check" aria-hidden="true"></i> Verified</span><?php endif; ?>
    </div>
</div>
<p><a href="<?= e(url('/admin/sellers')) ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> All sellers</a></p>
<?php if ($s['status'] === 'suspended'): ?>
    <div class="alert alert-danger small">Suspended <?= $s['suspended_at'] ? e(date('d M Y H:i', strtotime($s['suspended_at']))) : '' ?>: <?= e((string) $s['suspension_reason']) ?></div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-8">
        <section class="wm-panel mb-3">
            <div class="wm-panel-head">Store</div>
            <div class="wm-panel-body">
                <dl class="row small mb-0">
                    <dt class="col-sm-4">Store address</dt><dd class="col-sm-8"><a href="<?= e(url('/store/' . $s['slug'])) ?>" target="_blank" rel="noopener">/store/<?= e($s['slug']) ?></a><?= $s['status'] !== 'active' ? ' <span class="text-muted">(hidden while suspended)</span>' : '' ?>
                        · <a href="<?= e(url('/admin/products', ['seller' => $s['id']])) ?>">Products</a><?php if (Auth::can('payouts.view')): ?>
                        · <a href="<?= e(url('/admin/sellers/' . $s['id'] . '/ledger')) ?>">Ledger &amp; balances</a><?php endif; ?></dd>
                    <dt class="col-sm-4">Business</dt><dd class="col-sm-8"><?= e((string) $s['legal_name']) ?: '—' ?> (<?= $s['business_type'] === 'registered_business' ? 'registered business' : 'individual' ?>)</dd>
                    <dt class="col-sm-4">Location</dt><dd class="col-sm-8"><?= e(trim(($s['town'] ?? '') . ', ' . ($s['county_name'] ?? ''), ', ')) ?: '—' ?></dd>
                    <dt class="col-sm-4">Public contacts</dt><dd class="col-sm-8"><?= e((string) $s['public_email']) ?: '—' ?> · <?= $s['public_phone'] ? e(Validator::displayPhone((string) $s['public_phone'])) : '—' ?> <span class="text-muted">(<?= (int) $s['show_contacts'] ? 'shown on store page' : 'not shown' ?>)</span></dd>
                    <dt class="col-sm-4">Products</dt><dd class="col-sm-8"><?= $productCount ?></dd>
                    <dt class="col-sm-4">Payout destination</dt><dd class="col-sm-8"><?= $payout ? e($payout['masked']) : '—' ?></dd>
                    <dt class="col-sm-4">Approved</dt><dd class="col-sm-8"><?= $s['approved_at'] ? e(date('d M Y H:i', strtotime($s['approved_at']))) . ($s['approver_first'] ? ' by ' . e($s['approver_first'] . ' ' . $s['approver_last']) : '') : '—' ?>
                        <?php if ($application): ?> · <a href="<?= e(url('/admin/sellers/applications/' . $application['id'])) ?>"><?= e($application['application_number']) ?></a><?php endif; ?></dd>
                    <dt class="col-sm-4">Description</dt><dd class="col-sm-8"><?= nl2br(e((string) $s['description'])) ?: '—' ?></dd>
                </dl>
            </div>
        </section>
        <section class="wm-panel">
            <div class="wm-panel-head">Team</div>
            <div class="table-responsive"><table class="table table-sm mb-0" data-wm-table="10" data-wm-noun="team members">
                <thead><tr><th scope="col">Name</th><th scope="col">Email</th><th scope="col">Role</th><th scope="col">Account</th></tr></thead>
                <tbody>
                <?php foreach ($members as $m): ?>
                    <tr><td><?= e($m['first_name'] . ' ' . $m['last_name']) ?></td><td class="small"><?= e($m['email']) ?></td><td><?= e(ucfirst($m['role'])) ?></td><td><?= e(ucfirst($m['status'])) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$members): ?><tr><td colspan="4" class="text-muted small">No seller users linked<?= $platform ? ' (the Official Store is run by ' . brand_name() . ' staff)' : '' ?>.</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </section>
    </div>
    <div class="col-lg-4">
        <?php if ($canManage): ?>
            <?php if ($s['status'] === 'active'): ?>
                <form class="wm-panel mb-3" method="post" action="<?= e(url('/admin/sellers/' . $s['id'] . '/suspend')) ?>" data-confirm="Suspend this store? Its products will be hidden and the owner notified." novalidate>
                    <?= csrf_field() ?>
                    <div class="wm-panel-head">Suspend store</div>
                    <div class="wm-panel-body">
                        <label class="form-label small" for="reason">Reason (sent to the seller)</label>
                        <textarea class="form-control form-control-sm<?= invalid('reason') ?>" id="reason" name="reason" rows="3" maxlength="500"><?= e(fv('reason')) ?></textarea>
                        <?= field_error('reason') ?>
                        <button class="btn btn-outline-danger w-100 mt-2" type="submit">Suspend</button>
                    </div>
                </form>
            <?php elseif ($s['status'] === 'suspended'): ?>
                <form class="wm-panel mb-3" method="post" action="<?= e(url('/admin/sellers/' . $s['id'] . '/reactivate')) ?>">
                    <?= csrf_field() ?>
                    <div class="wm-panel-head">Reactivate store</div>
                    <div class="wm-panel-body">
                        <label class="form-label small" for="note">Note <span class="text-muted">(optional, internal)</span></label>
                        <input class="form-control form-control-sm" id="note" name="note" maxlength="500">
                        <button class="btn btn-wm-green w-100 mt-2" type="submit">Reactivate</button>
                    </div>
                </form>
            <?php endif; ?>
            <form class="wm-panel" method="post" action="<?= e(url('/admin/sellers/' . $s['id'] . '/verify')) ?>">
                <?= csrf_field() ?>
                <div class="wm-panel-head">Verified seller badge</div>
                <div class="wm-panel-body">
                    <input type="hidden" name="verified" value="<?= (int) $s['is_verified'] ? '' : '1' ?>">
                    <p class="small text-muted">Shown on the store page once you have checked this seller's documents.</p>
                    <button class="btn btn-sm btn-outline-secondary" type="submit"><?= (int) $s['is_verified'] ? 'Remove badge' : 'Give badge' ?></button>
                </div>
            </form>
        <?php elseif ($platform): ?>
            <div class="alert alert-light border small">The <?= e(brand_name()) ?> Official Store is owned by the platform. It holds the products created in the admin catalogue.</div>
        <?php endif; ?>
    </div>
</div>
