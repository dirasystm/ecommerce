<?php
use Waimart\Core\Validator;
use Waimart\Services\PickupApplicationService as P;

/** @var array<string,mixed> $a @var array<string,string>|null $station @var list<array<string,mixed>> $counties @var list<array<string,mixed>> $nearby */
$badge = ['pending' => 'badge-soft-warning', 'approved' => 'badge-soft-success', 'rejected' => 'badge-soft-danger'];
$s = fn (string $k) => (string) fv($k, $station[$k] ?? '');
$mapLink = $a['latitude'] !== null ? 'https://www.google.com/maps?q=' . $a['latitude'] . ',' . $a['longitude'] : (string) ($a['map_url'] ?? '');
?>
<div class="wm-admin-head">
    <h1><?= e((string) $a['business_name']) ?> <span class="badge <?= $badge[$a['status']] ?? 'badge-soft-muted' ?> fs-6 align-middle"><?= e(P::STATUSES[$a['status']] ?? (string) $a['status']) ?></span></h1>
    <a class="btn btn-outline-secondary" href="<?= e(url('/admin/pickup-points/applications')) ?>">All applications</a>
</div>
<p class="small text-muted">Reference <code><?= e((string) $a['reference']) ?></code> · applied <?= e(date('d M Y, H:i', strtotime((string) $a['created_at']))) ?>
    <?php if ($a['reviewed_at']): ?> · decided <?= e(date('d M Y, H:i', strtotime((string) $a['reviewed_at']))) ?><?= $a['reviewer'] ? ' by ' . e((string) $a['reviewer']) : '' ?><?php endif; ?></p>

<div class="row g-3">
    <div class="col-lg-7">
        <section class="wm-panel mb-3" aria-labelledby="bizTitle">
            <div class="wm-panel-head" id="bizTitle">Business and contact</div>
            <div class="wm-panel-body">
                <dl class="row small mb-0">
                    <dt class="col-sm-4 fw-normal text-muted">Type of business</dt><dd class="col-sm-8"><?= e(P::BUSINESS_TYPES[$a['business_type']] ?? (string) $a['business_type']) ?></dd>
                    <dt class="col-sm-4 fw-normal text-muted">Registration / permit</dt><dd class="col-sm-8"><?= e((string) ($a['registration_number'] ?: 'Not given')) ?></dd>
                    <dt class="col-sm-4 fw-normal text-muted">Contact person</dt><dd class="col-sm-8"><?= e((string) $a['contact_name']) ?></dd>
                    <dt class="col-sm-4 fw-normal text-muted">Phone</dt><dd class="col-sm-8"><a href="tel:+<?= e((string) $a['phone']) ?>"><?= e(Validator::displayPhone((string) $a['phone'])) ?></a></dd>
                    <dt class="col-sm-4 fw-normal text-muted">Email</dt><dd class="col-sm-8"><a href="mailto:<?= e((string) $a['email']) ?>"><?= e((string) $a['email']) ?></a></dd>
                </dl>
            </div>
        </section>
        <section class="wm-panel mb-3" aria-labelledby="locTitle">
            <div class="wm-panel-head" id="locTitle">Location and hours</div>
            <div class="wm-panel-body">
                <dl class="row small mb-0">
                    <dt class="col-sm-4 fw-normal text-muted">Address</dt><dd class="col-sm-8"><?= e((string) $a['address']) ?></dd>
                    <dt class="col-sm-4 fw-normal text-muted">Town, county</dt><dd class="col-sm-8"><?= e((string) $a['town']) ?>, <?= e((string) $a['county_name']) ?></dd>
                    <dt class="col-sm-4 fw-normal text-muted">Landmark</dt><dd class="col-sm-8"><?= e((string) ($a['landmark'] ?: '—')) ?></dd>
                    <dt class="col-sm-4 fw-normal text-muted">Map</dt><dd class="col-sm-8"><?php if ($mapLink !== ''): ?><a href="<?= e($mapLink) ?>" target="_blank" rel="noopener noreferrer">Open in Google Maps</a><?= $a['latitude'] !== null ? ' <span class="text-muted">(' . e((string) $a['latitude']) . ', ' . e((string) $a['longitude']) . ')</span>' : '' ?><?php else: ?>Not given<?php endif; ?></dd>
                    <dt class="col-sm-4 fw-normal text-muted">Opening hours</dt><dd class="col-sm-8"><?= e((string) $a['opening_hours']) ?></dd>
                    <dt class="col-sm-4 fw-normal text-muted">Space</dt><dd class="col-sm-8"><?= e(P::STORAGE[$a['storage_space']] ?? (string) $a['storage_space']) ?><?= $a['daily_capacity'] ? ' · about ' . (int) $a['daily_capacity'] . ' parcels a day' : '' ?></dd>
                    <dt class="col-sm-4 fw-normal text-muted">Lockable storage</dt><dd class="col-sm-8"><?= (int) $a['secure_storage'] ? 'Yes' : 'No' ?></dd>
                    <dt class="col-sm-4 fw-normal text-muted">Cash on delivery</dt><dd class="col-sm-8"><?= (int) $a['accepts_cod'] ? 'Can collect cash' : 'No' ?></dd>
                    <dt class="col-sm-4 fw-normal text-muted">Notes</dt><dd class="col-sm-8"><?= $a['notes'] ? nl2br(e((string) $a['notes'])) : '—' ?></dd>
                </dl>
            </div>
        </section>
        <?php if ($a['photo_stored_name']): ?>
            <section class="wm-panel mb-3" aria-labelledby="photoTitle">
                <div class="wm-panel-head" id="photoTitle">Shop front</div>
                <div class="wm-panel-body"><img src="<?= e(url('/admin/pickup-points/applications/' . $a['id'] . '/photo')) ?>" alt="Photo of the shop front of <?= e((string) $a['business_name']) ?>" class="img-fluid rounded" style="max-height:360px"></div>
            </section>
        <?php endif; ?>
    </div>

    <div class="col-lg-5">
        <section class="wm-panel mb-3" aria-labelledby="nearTitle">
            <div class="wm-panel-head" id="nearTitle">Existing stations in <?= e((string) $a['county_name']) ?></div>
            <div class="wm-panel-body small">
                <?php if ($nearby): ?>
                    <ul class="mb-0 ps-3"><?php foreach ($nearby as $n): ?><li><code><?= e((string) $n['code']) ?></code> <?= e((string) $n['name']) ?> <span class="text-muted">(<?= e((string) $n['town']) ?>)</span></li><?php endforeach; ?></ul>
                <?php else: ?>
                    <p class="mb-0">None yet: this would be the first station in the county.</p>
                <?php endif; ?>
            </div>
        </section>

        <?php if ($a['status'] === 'pending' && $station !== null): ?>
            <section class="wm-panel mb-3" aria-labelledby="approveTitle">
                <div class="wm-panel-head" id="approveTitle">Approve and create the station</div>
                <div class="wm-panel-body">
                    <p class="small text-muted">Checked the details? Adjust anything below, then approve. The station goes live at checkout straight away.</p>
                    <form method="post" action="<?= e(url('/admin/pickup-points/applications/' . $a['id'] . '/approve')) ?>" novalidate class="row g-2"
                          data-confirm="Approve this application? The station will be offered to customers at checkout and the business will be told.">
                        <?= csrf_field() ?>
                        <div class="col-sm-5"><label class="form-label small" for="code">Station code</label>
                            <input class="form-control form-control-sm<?= invalid('code') ?>" id="code" name="code" maxlength="20" required value="<?= e($s('code')) ?>"><?= field_error('code') ?></div>
                        <div class="col-sm-7"><label class="form-label small" for="name">Name customers see</label>
                            <input class="form-control form-control-sm<?= invalid('name') ?>" id="name" name="name" maxlength="120" required value="<?= e($s('name')) ?>"><?= field_error('name') ?></div>
                        <div class="col-sm-6"><label class="form-label small" for="county_id">County</label>
                            <select class="form-select form-select-sm<?= invalid('county_id') ?>" id="county_id" name="county_id"><?php foreach ($counties as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $s('county_id') === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
                        <div class="col-sm-6"><label class="form-label small" for="town">Town</label>
                            <input class="form-control form-control-sm<?= invalid('town') ?>" id="town" name="town" maxlength="100" required value="<?= e($s('town')) ?>"><?= field_error('town') ?></div>
                        <div class="col-12"><label class="form-label small" for="address">Address</label>
                            <input class="form-control form-control-sm<?= invalid('address') ?>" id="address" name="address" maxlength="255" required value="<?= e($s('address')) ?>"><?= field_error('address') ?></div>
                        <div class="col-12"><label class="form-label small" for="opening_hours">Opening hours</label>
                            <input class="form-control form-control-sm<?= invalid('opening_hours') ?>" id="opening_hours" name="opening_hours" maxlength="500" required value="<?= e($s('opening_hours')) ?>"><?= field_error('opening_hours') ?></div>
                        <div class="col-12"><label class="form-label small" for="instructions">Instructions for customers</label>
                            <input class="form-control form-control-sm<?= invalid('instructions') ?>" id="instructions" name="instructions" maxlength="1000" value="<?= e($s('instructions')) ?>"><?= field_error('instructions') ?></div>
                        <div class="col-sm-4"><label class="form-label small" for="pickup_fee">Pickup fee (KSh)</label>
                            <input class="form-control form-control-sm<?= invalid('pickup_fee') ?>" id="pickup_fee" name="pickup_fee" inputmode="decimal" value="<?= e($s('pickup_fee')) ?>"><?= field_error('pickup_fee') ?></div>
                        <div class="col-sm-4"><label class="form-label small" for="daily_capacity">Parcels a day</label>
                            <input class="form-control form-control-sm<?= invalid('daily_capacity') ?>" id="daily_capacity" name="daily_capacity" inputmode="numeric" value="<?= e($s('daily_capacity')) ?>"><?= field_error('daily_capacity') ?></div>
                        <div class="col-sm-4 d-flex align-items-end"><div class="form-check mb-1">
                            <input class="form-check-input" type="checkbox" id="cod_allowed" name="cod_allowed" value="1" <?= $s('cod_allowed') !== '' ? 'checked' : '' ?>>
                            <label class="form-check-label small" for="cod_allowed">Cash on delivery</label></div></div>
                        <?php foreach (['latitude', 'longitude', 'map_url', 'phone', 'email', 'max_order_value'] as $h): ?><input type="hidden" name="<?= $h ?>" value="<?= e($s($h)) ?>"><?php endforeach; ?>
                        <div class="col-12"><label class="form-label small" for="decision_note">Internal note (optional)</label>
                            <input class="form-control form-control-sm" id="decision_note" name="decision_note" maxlength="1000" placeholder="e.g. Visited 22 Oct, premises fine"></div>
                        <div class="col-12"><button class="btn btn-wm-green w-100" type="submit"><i class="bi bi-check-circle" aria-hidden="true"></i> Approve and create station</button></div>
                    </form>
                </div>
            </section>
            <section class="wm-panel mb-3" aria-labelledby="rejectTitle">
                <div class="wm-panel-head" id="rejectTitle">Reject</div>
                <div class="wm-panel-body">
                    <form method="post" action="<?= e(url('/admin/pickup-points/applications/' . $a['id'] . '/reject')) ?>" novalidate data-confirm="Reject this application? The business will be told the reason.">
                        <?= csrf_field() ?>
                        <label class="form-label small" for="reason">Reason (the business will see this)</label>
                        <textarea class="form-control form-control-sm<?= invalid('reason') ?>" id="reason" name="reason" rows="3" maxlength="1000" required placeholder="e.g. We already have a station on the same street."><?= e((string) fv('reason', '')) ?></textarea>
                        <?= field_error('reason') ?>
                        <button class="btn btn-sm btn-outline-danger mt-2" type="submit">Reject application</button>
                    </form>
                </div>
            </section>
        <?php else: ?>
            <section class="wm-panel mb-3" aria-labelledby="decTitle">
                <div class="wm-panel-head" id="decTitle">Decision</div>
                <div class="wm-panel-body small">
                    <?php if ($a['status'] === 'approved'): ?>
                        <p class="mb-1">Approved<?= $a['station_code'] ? ' as <code>' . e((string) $a['station_code']) . '</code> ' . e((string) $a['station_name']) : '' ?>.</p>
                        <?php if ($a['pickup_point_id']): ?><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/admin/pickup-points/' . $a['pickup_point_id'] . '/edit')) ?>">Open the station</a><?php endif; ?>
                    <?php else: ?>
                        <p class="mb-0"><strong>Reason given:</strong> <?= e((string) $a['decision_note']) ?></p>
                    <?php endif; ?>
                    <?php if ($a['status'] === 'approved' && $a['decision_note']): ?><p class="mt-2 mb-0 text-muted">Note: <?= e((string) $a['decision_note']) ?></p><?php endif; ?>
                </div>
            </section>
        <?php endif; ?>
    </div>
</div>
