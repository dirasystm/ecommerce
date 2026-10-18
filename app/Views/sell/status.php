<?php
use Waimart\Core\Validator;
use Waimart\Services\SellerDocumentPolicy;

/**
 * @var array<string,mixed> $app
 * @var list<array<string,mixed>> $documents
 * @var list<array<string,mixed>> $history
 * @var list<string> $required
 * @var list<string> $requested
 * @var string $county
 * @var list<string> $categoryNames
 */
$draft = $app['status'] === 'draft';
$badges = [
    'draft' => ['badge-soft-muted', 'Draft — not yet submitted'], 'submitted' => ['badge-soft-warning', 'Submitted — waiting for review'],
    'under_review' => ['badge-soft-warning', 'Under review'], 'approved' => ['badge-soft-success', 'Approved'], 'rejected' => ['badge-soft-danger', 'Not approved'],
];
[$badgeClass, $badgeText] = $badges[$app['status']];
$have = array_column($documents, 'doc_type');
$missing = array_diff($required, $have);
$lastRejection = null;
foreach ($history as $h) {
    if ($h['to_status'] === 'rejected') {
        $lastRejection = $h;
    }
}
?>
<div class="container-wm">
    <nav class="wm-breadcrumb" aria-label="Breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= e(url('/')) ?>">Home</a></li>
            <li class="breadcrumb-item"><a href="<?= e(url('/sell')) ?>">Sell on Waimart</a></li>
            <li class="breadcrumb-item active" aria-current="page">Your application</li>
        </ol>
    </nav>

    <section class="wm-card wm-page mb-3">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
            <div>
                <h1 class="mb-1">Your seller application</h1>
                <div class="text-muted small"><?= e($app['application_number']) ?><?= $app['submitted_at'] ? ' · submitted ' . e(date('d M Y H:i', strtotime($app['submitted_at']))) : '' ?></div>
            </div>
            <span class="badge <?= $badgeClass ?> fs-6"><?= e($badgeText) ?></span>
        </div>

        <?php if ($app['status'] === 'approved'): ?>
            <div class="alert alert-success mt-3 mb-0">Your store is ready. <a class="alert-link" href="<?= e(url('/seller')) ?>">Go to your Seller Centre</a>.</div>
        <?php elseif ($app['status'] === 'rejected'): ?>
            <div class="alert alert-danger mt-3 mb-0">
                <div><strong>Reason:</strong> <?= e((string) $app['decision_reason']) ?></div>
                <?php if ((int) $app['resubmission_allowed'] === 1): ?>
                    <form method="post" action="<?= e(url('/sell/application/reopen')) ?>" class="mt-2">
                        <?= csrf_field() ?>
                        <button class="btn btn-sm btn-wm" type="submit">Update and resubmit my application</button>
                    </form>
                <?php else: ?>
                    <div class="small mt-1">This application cannot be resubmitted. Please <a class="alert-link" href="<?= e(url('/contact')) ?>">contact us</a> if you have questions.</div>
                <?php endif; ?>
            </div>
        <?php elseif (in_array($app['status'], ['submitted', 'under_review'], true)): ?>
            <p class="mt-3 mb-0">We will email <strong><?= e($app['email']) ?></strong> when a decision has been made. Your application can no longer be changed while it is being reviewed.</p>
        <?php else: ?>
            <?php if ($lastRejection): ?><div class="alert alert-warning mt-3 mb-0 small">Previously not approved: <?= e((string) $lastRejection['note']) ?></div><?php endif; ?>
            <p class="mt-3 mb-0">Step 2 of 2: upload your documents, add payout details and submit.</p>
        <?php endif; ?>
    </section>

    <div class="row g-3">
        <div class="col-lg-7">
            <section class="wm-card wm-page mb-3">
                <div class="d-flex justify-content-between align-items-center">
                    <h2 class="h5 mb-0">Business details</h2>
                    <?php if ($draft): ?><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/sell/apply')) ?>">Edit details</a><?php endif; ?>
                </div>
                <dl class="row small mt-3 mb-0">
                    <dt class="col-sm-4">Store name</dt><dd class="col-sm-8"><?= e($app['store_name']) ?></dd>
                    <dt class="col-sm-4">Store address</dt><dd class="col-sm-8">/store/<?= e($app['store_slug']) ?></dd>
                    <dt class="col-sm-4">Business type</dt><dd class="col-sm-8"><?= $app['business_type'] === 'registered_business' ? 'Registered business' : 'Individual / sole trader' ?></dd>
                    <?php if ($app['legal_name']): ?><dt class="col-sm-4">Registered name</dt><dd class="col-sm-8"><?= e($app['legal_name']) ?></dd><?php endif; ?>
                    <?php if ($app['registration_number']): ?><dt class="col-sm-4">Registration no.</dt><dd class="col-sm-8"><?= e($app['registration_number']) ?></dd><?php endif; ?>
                    <dt class="col-sm-4">Location</dt><dd class="col-sm-8"><?= e($app['town'] . ', ' . $county) ?></dd>
                    <dt class="col-sm-4">Categories</dt><dd class="col-sm-8"><?= e(implode(', ', $categoryNames)) ?></dd>
                    <dt class="col-sm-4">Contact</dt><dd class="col-sm-8"><?= e($app['contact_name']) ?> · <?= e($app['email']) ?> · <?= e(Validator::displayPhone((string) $app['phone'])) ?></dd>
                    <dt class="col-sm-4">Description</dt><dd class="col-sm-8"><?= nl2br(e($app['business_description'])) ?></dd>
                </dl>
            </section>

            <section class="wm-card wm-page mb-3">
                <h2 class="h5">Documents</h2>
                <?php if ($required): ?>
                    <p class="small">Required: <?= e(implode(', ', array_map(fn ($t) => SellerDocumentPolicy::TYPES[$t], $required))) ?>. PDF, JPEG or PNG, up to 5 MB each. Documents are kept private.</p>
                <?php else: ?>
                    <p class="small">No documents are required, but you may add supporting documents. PDF, JPEG or PNG, up to 5 MB each.</p>
                <?php endif; ?>
                <?php if ($documents): ?>
                    <ul class="list-group mb-3">
                        <?php foreach ($documents as $d): ?>
                            <li class="list-group-item d-flex flex-wrap justify-content-between align-items-center gap-2">
                                <span class="small"><strong><?= e(SellerDocumentPolicy::TYPES[$d['doc_type']] ?? $d['doc_type']) ?></strong><br>
                                    <a href="<?= e(url('/sell/application/documents/' . $d['id'])) ?>"><?= e($d['original_name']) ?></a> · <?= number_format(max(1, (int) ceil($d['size_bytes'] / 1024))) ?> KB</span>
                                <?php if ($draft): ?>
                                    <form method="post" action="<?= e(url('/sell/application/documents/' . $d['id'] . '/delete')) ?>" data-confirm="Remove this document?">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm btn-outline-danger" type="submit">Remove</button>
                                    </form>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if ($draft && $requested): ?>
                    <form method="post" action="<?= e(url('/sell/application/documents')) ?>" enctype="multipart/form-data" class="row g-2 align-items-end" novalidate>
                        <?= csrf_field() ?>
                        <div class="col-sm-5">
                            <label class="form-label" for="doc_type">Document type</label>
                            <select class="form-select<?= invalid('doc_type') ?>" id="doc_type" name="doc_type">
                                <?php foreach ($requested as $t): ?>
                                    <option value="<?= e($t) ?>"><?= e(SellerDocumentPolicy::TYPES[$t]) ?><?= in_array($t, $required, true) ? ' (required)' : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?= field_error('doc_type') ?>
                        </div>
                        <div class="col-sm-5">
                            <label class="form-label" for="document">File</label>
                            <input class="form-control<?= invalid('document') ?>" type="file" id="document" name="document" accept="application/pdf,image/jpeg,image/png">
                        </div>
                        <div class="col-sm-2"><button class="btn btn-outline-wm w-100" type="submit">Upload</button></div>
                        <div class="col-12"><?= field_error('document') ?></div>
                    </form>
                <?php endif; ?>
            </section>
        </div>

        <div class="col-lg-5">
            <?php if ($draft): ?>
                <form class="wm-card wm-page mb-3" method="post" action="<?= e(url('/sell/application/submit')) ?>" novalidate>
                    <?= csrf_field() ?>
                    <h2 class="h5">Payout details</h2>
                    <p class="small text-muted">Where Waimart sends your earnings. Stored encrypted and visible only to authorised Waimart finance staff.</p>
                    <?php if ($app['payout_masked']): ?>
                        <div class="alert alert-light border small">On file: <strong><?= e($app['payout_masked']) ?></strong>. Leave the fields below blank to keep it.</div>
                    <?php endif; ?>
                    <?php $pm = (string) (fv('payout_method', $app['payout_method'] ?? 'mpesa') ?: 'mpesa'); ?>
                    <fieldset class="mb-3">
                        <legend class="form-label fs-6">Receive payouts by</legend>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="payout_method" id="pm_mpesa" value="mpesa" <?= $pm === 'mpesa' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="pm_mpesa">M-Pesa</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="payout_method" id="pm_bank" value="bank" <?= $pm === 'bank' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="pm_bank">Bank account</label>
                        </div>
                        <?= field_error('payout_method') ?>
                    </fieldset>
                    <div class="border rounded p-2 mb-3">
                        <div class="small fw-semibold mb-2">If M-Pesa</div>
                        <label class="form-label small" for="payout_phone">M-Pesa number</label>
                        <input class="form-control form-control-sm<?= invalid('payout_phone') ?>" type="tel" id="payout_phone" name="payout_phone" autocomplete="off">
                        <?= field_error('payout_phone') ?>
                        <label class="form-label small mt-2" for="payout_name">Name registered on M-Pesa</label>
                        <input class="form-control form-control-sm<?= invalid('payout_name') ?>" id="payout_name" name="payout_name" maxlength="120" value="<?= e(fv('payout_name')) ?>">
                        <?= field_error('payout_name') ?>
                    </div>
                    <div class="border rounded p-2 mb-3">
                        <div class="small fw-semibold mb-2">If bank account</div>
                        <label class="form-label small" for="bank_name">Bank</label>
                        <input class="form-control form-control-sm<?= invalid('bank_name') ?>" id="bank_name" name="bank_name" maxlength="80" value="<?= e(fv('bank_name')) ?>">
                        <?= field_error('bank_name') ?>
                        <label class="form-label small mt-2" for="bank_branch">Branch <span class="text-muted">(optional)</span></label>
                        <input class="form-control form-control-sm" id="bank_branch" name="bank_branch" maxlength="80" value="<?= e(fv('bank_branch')) ?>">
                        <label class="form-label small mt-2" for="account_name">Account name</label>
                        <input class="form-control form-control-sm<?= invalid('account_name') ?>" id="account_name" name="account_name" maxlength="120" value="<?= e(fv('account_name')) ?>">
                        <?= field_error('account_name') ?>
                        <label class="form-label small mt-2" for="account_number">Account number</label>
                        <input class="form-control form-control-sm<?= invalid('account_number') ?>" id="account_number" name="account_number" inputmode="numeric" autocomplete="off">
                        <?= field_error('account_number') ?>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input<?= invalid('accept_terms') ?>" type="checkbox" id="accept_terms" name="accept_terms" value="1">
                        <label class="form-check-label" for="accept_terms">I have read and accept the <a href="<?= e(url('/sell/terms')) ?>" target="_blank" rel="noopener">seller terms and policies</a>.</label>
                        <?= field_error('accept_terms') ?>
                    </div>
                    <?php if ($missing): ?><div class="alert alert-warning small">Upload the required documents before submitting.</div><?php endif; ?>
                    <?= field_error('store_slug') ?>
                    <button class="btn btn-wm w-100" type="submit">Submit application for review</button>
                </form>
            <?php elseif ($app['payout_masked']): ?>
                <section class="wm-card wm-page mb-3"><h2 class="h5">Payout details</h2><p class="small mb-0"><?= e($app['payout_masked']) ?></p></section>
            <?php endif; ?>

            <section class="wm-card wm-page">
                <h2 class="h5">History</h2>
                <ol class="list-unstyled small mb-0">
                    <?php foreach ($history as $h): ?>
                        <li class="mb-2"><span class="text-muted"><?= e(date('d M Y H:i', strtotime($h['created_at']))) ?></span> — <?= e(ucfirst(str_replace('_', ' ', $h['to_status']))) ?>
                            <?php if ($h['note'] && $h['to_status'] !== 'rejected'): ?><div class="text-muted"><?= e($h['note']) ?></div><?php endif; ?></li>
                    <?php endforeach; ?>
                </ol>
            </section>
        </div>
    </div>
</div>
