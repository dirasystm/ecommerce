<?php
use Waimart\Core\Auth;
use Waimart\Core\Validator;
use Waimart\Services\SellerDocumentPolicy;

/** @var array<string,mixed> $app @var list<array<string,mixed>> $documents @var list<array<string,mixed>> $history @var list<string> $required @var list<string> $categoryNames */
$open = in_array($app['status'], ['submitted', 'under_review'], true);
$canDecide = Auth::can('sellers.manage') && $open && (int) $app['user_id'] !== Auth::id();
$have = array_column($documents, 'doc_type');
$missing = array_diff($required, $have);
$labels = ['draft' => 'Draft', 'submitted' => 'Submitted', 'under_review' => 'Under review', 'approved' => 'Approved', 'rejected' => 'Rejected'];
?>
<div class="wm-admin-head">
    <h1>Application <?= e($app['application_number']) ?></h1>
    <span class="badge <?= ['approved' => 'badge-soft-success', 'rejected' => 'badge-soft-danger', 'draft' => 'badge-soft-muted'][$app['status']] ?? 'badge-soft-warning' ?> fs-6"><?= e($labels[$app['status']]) ?></span>
</div>
<p><a href="<?= e(url('/admin/sellers/applications', ['status' => $app['status']])) ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to applications</a></p>

<div class="row g-3">
    <div class="col-lg-8">
        <section class="wm-panel mb-3">
            <div class="wm-panel-head">Store and business</div>
            <div class="wm-panel-body">
                <dl class="row small mb-0">
                    <dt class="col-sm-4">Store name</dt><dd class="col-sm-8 fw-semibold"><?= e($app['store_name']) ?></dd>
                    <dt class="col-sm-4">Store address</dt><dd class="col-sm-8">/store/<?= e($app['store_slug']) ?></dd>
                    <dt class="col-sm-4">Business type</dt><dd class="col-sm-8"><?= $app['business_type'] === 'registered_business' ? 'Registered business' : 'Individual / sole trader' ?></dd>
                    <dt class="col-sm-4">Registered name</dt><dd class="col-sm-8"><?= e((string) $app['legal_name']) ?: '—' ?></dd>
                    <dt class="col-sm-4">Registration no.</dt><dd class="col-sm-8"><?= e((string) $app['registration_number']) ?: '—' ?></dd>
                    <dt class="col-sm-4">Location</dt><dd class="col-sm-8"><?= e($app['town'] . ', ' . $app['county_name']) ?></dd>
                    <dt class="col-sm-4">Business address</dt><dd class="col-sm-8"><?= e((string) $app['business_address']) ?: '—' ?></dd>
                    <dt class="col-sm-4">Categories</dt><dd class="col-sm-8"><?= e(implode(', ', $categoryNames)) ?></dd>
                    <dt class="col-sm-4">Description</dt><dd class="col-sm-8"><?= nl2br(e($app['business_description'])) ?></dd>
                </dl>
            </div>
        </section>
        <section class="wm-panel mb-3">
            <div class="wm-panel-head">Contact and account</div>
            <div class="wm-panel-body">
                <dl class="row small mb-0">
                    <dt class="col-sm-4">Contact person</dt><dd class="col-sm-8"><?= e($app['contact_name']) ?></dd>
                    <dt class="col-sm-4">Email / phone</dt><dd class="col-sm-8"><?= e($app['email']) ?> · <?= e(Validator::displayPhone((string) $app['phone'])) ?></dd>
                    <dt class="col-sm-4">Applicant account</dt><dd class="col-sm-8"><?= e($app['account_first'] . ' ' . $app['account_last']) ?> (<?= e($app['account_email']) ?>)</dd>
                    <dt class="col-sm-4">Payout</dt><dd class="col-sm-8"><?= e((string) $app['payout_masked']) ?: '—' ?> <span class="text-muted">(full details encrypted)</span></dd>
                    <dt class="col-sm-4">Terms accepted</dt><dd class="col-sm-8"><?= $app['terms_accepted_at'] ? 'Version ' . e((string) $app['terms_version']) . ' on ' . e(date('d M Y H:i', strtotime($app['terms_accepted_at']))) : '—' ?></dd>
                </dl>
            </div>
        </section>
        <section class="wm-panel mb-3">
            <div class="wm-panel-head">Documents</div>
            <div class="wm-panel-body">
                <?php if ($missing): ?><div class="alert alert-warning small">Missing required: <?= e(implode(', ', array_map(fn ($t) => SellerDocumentPolicy::TYPES[$t], $missing))) ?></div><?php endif; ?>
                <?php if (!$documents): ?><p class="small text-muted mb-0">No documents uploaded.</p><?php endif; ?>
                <ul class="list-unstyled small mb-0">
                    <?php foreach ($documents as $d): ?>
                        <li class="mb-1"><i class="bi bi-file-earmark-lock" aria-hidden="true"></i>
                            <strong><?= e(SellerDocumentPolicy::TYPES[$d['doc_type']] ?? $d['doc_type']) ?>:</strong>
                            <a href="<?= e(url('/admin/sellers/documents/' . $d['id'])) ?>"><?= e($d['original_name']) ?></a>
                            <span class="text-muted">(<?= number_format(max(1, (int) ceil($d['size_bytes'] / 1024))) ?> KB, <?= e($d['mime_type']) ?>)</span></li>
                    <?php endforeach; ?>
                </ul>
                <p class="small text-muted mt-2 mb-0">Downloads are recorded in the audit log.</p>
            </div>
        </section>
    </div>

    <div class="col-lg-4">
        <?php if ($canDecide): ?>
            <?php if ($app['status'] === 'submitted'): ?>
                <form class="wm-panel mb-3" method="post" action="<?= e(url('/admin/sellers/applications/' . $app['id'] . '/review')) ?>">
                    <?= csrf_field() ?>
                    <div class="wm-panel-body"><button class="btn btn-outline-secondary w-100" type="submit">Mark as under review</button></div>
                </form>
            <?php endif; ?>
            <form class="wm-panel mb-3" method="post" action="<?= e(url('/admin/sellers/applications/' . $app['id'] . '/approve')) ?>" data-confirm="Approve this seller and create their store?" novalidate>
                <?= csrf_field() ?>
                <div class="wm-panel-head">Approve</div>
                <div class="wm-panel-body">
                    <label class="form-label small" for="store_name">Store name</label>
                    <input class="form-control form-control-sm<?= invalid('store_name') ?>" id="store_name" name="store_name" value="<?= e(fv('store_name', $app['store_name'])) ?>">
                    <?= field_error('store_name') ?>
                    <label class="form-label small mt-2" for="store_slug">Store address</label>
                    <input class="form-control form-control-sm<?= invalid('store_slug') ?>" id="store_slug" name="store_slug" value="<?= e(fv('store_slug', $app['store_slug'])) ?>">
                    <?= field_error('store_slug') ?>
                    <label class="form-label small mt-2" for="note">Internal note <span class="text-muted">(optional)</span></label>
                    <input class="form-control form-control-sm" id="note" name="note" maxlength="500">
                    <button class="btn btn-wm-green w-100 mt-3" type="submit">Approve seller</button>
                </div>
            </form>
            <form class="wm-panel mb-3" method="post" action="<?= e(url('/admin/sellers/applications/' . $app['id'] . '/reject')) ?>" novalidate>
                <?= csrf_field() ?>
                <div class="wm-panel-head">Reject</div>
                <div class="wm-panel-body">
                    <label class="form-label small" for="reason">Reason (sent to the applicant)</label>
                    <textarea class="form-control form-control-sm<?= invalid('reason') ?>" id="reason" name="reason" rows="3" maxlength="1000"><?= e(fv('reason')) ?></textarea>
                    <?= field_error('reason') ?>
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" id="resubmission_allowed" name="resubmission_allowed" value="1" checked>
                        <label class="form-check-label small" for="resubmission_allowed">Allow the applicant to correct and resubmit</label>
                    </div>
                    <button class="btn btn-outline-danger w-100 mt-3" type="submit">Reject application</button>
                </div>
            </form>
        <?php elseif ($open && (int) $app['user_id'] === Auth::id()): ?>
            <div class="alert alert-info small">This is your own application, so another staff member must review it.</div>
        <?php endif; ?>
        <?php if ($app['status'] === 'approved' && $app['seller_id']): ?>
            <div class="alert alert-success small">Approved. <a class="alert-link" href="<?= e(url('/admin/sellers/' . $app['seller_id'])) ?>">Open the store</a>.</div>
        <?php elseif ($app['status'] === 'rejected'): ?>
            <div class="alert alert-danger small"><strong>Rejected:</strong> <?= e((string) $app['decision_reason']) ?><br><?= (int) $app['resubmission_allowed'] ? 'Resubmission allowed.' : 'Resubmission not allowed.' ?></div>
        <?php endif; ?>
        <section class="wm-panel">
            <div class="wm-panel-head">History</div>
            <ol class="list-unstyled small wm-panel-body mb-0">
                <?php foreach ($history as $h): ?>
                    <li class="mb-2"><span class="text-muted"><?= e(date('d M Y H:i', strtotime($h['created_at']))) ?></span><br>
                        <?= e($labels[$h['to_status']] ?? $h['to_status']) ?><?= $h['first_name'] ? ' · ' . e($h['first_name'] . ' ' . $h['last_name']) : '' ?>
                        <?php if ($h['note']): ?><div class="text-muted"><?= e($h['note']) ?></div><?php endif; ?></li>
                <?php endforeach; ?>
            </ol>
        </section>
    </div>
</div>
