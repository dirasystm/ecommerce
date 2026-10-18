<?php
use Waimart\Core\Auth;
use Waimart\Services\SellerDocumentPolicy;

/** @var array<string,mixed>|null $application @var bool $isSeller @var array<string,list<string>> $docs */
$names = fn (array $types) => implode(', ', array_map(fn ($t) => SellerDocumentPolicy::TYPES[$t] ?? $t, $types));
$statusText = [
    'draft' => 'You have a draft application.', 'submitted' => 'Your application has been submitted and is waiting for review.',
    'under_review' => 'Your application is being reviewed.', 'approved' => 'Your application has been approved.',
    'rejected' => 'Your application was not approved.',
];
?>
<div class="container-wm">
    <nav class="wm-breadcrumb" aria-label="Breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= e(url('/')) ?>">Home</a></li>
            <li class="breadcrumb-item active" aria-current="page">Sell on Waimart</li>
        </ol>
    </nav>

    <section class="wm-card wm-page mb-3">
        <div class="row g-4 align-items-center">
            <div class="col-lg-7">
                <h1>Sell on Waimart</h1>
                <p class="lead">Reach customers across Kenya from one trusted marketplace. You list and prepare your products — Waimart handles payment collection, delivery and pickup.</p>
                <div class="d-flex flex-wrap gap-2 mt-3">
                    <?php if ($isSeller): ?>
                        <a class="btn btn-wm btn-lg" href="<?= e(url('/seller')) ?>">Go to your Seller Centre</a>
                    <?php elseif ($application): ?>
                        <a class="btn btn-wm btn-lg" href="<?= e(url('/sell/application')) ?>">View your application</a>
                    <?php elseif (Auth::check()): ?>
                        <a class="btn btn-wm btn-lg" href="<?= e(url('/sell/apply')) ?>">Start your application</a>
                    <?php else: ?>
                        <a class="btn btn-wm btn-lg" href="<?= e(url('/register')) ?>">Create an account to apply</a>
                        <a class="btn btn-outline-wm btn-lg" href="<?= e(url('/login')) ?>">Sign in</a>
                    <?php endif; ?>
                </div>
                <?php if ($application && !$isSeller): ?>
                    <p class="small mt-2 mb-0"><?= e($statusText[$application['status']] ?? '') ?> (<?= e($application['application_number']) ?>)</p>
                <?php elseif (!Auth::check()): ?>
                    <p class="small text-muted mt-2 mb-0">Already selling on Waimart? Sign in to reach your Seller Centre.</p>
                <?php endif; ?>
            </div>
            <div class="col-lg-5">
                <div class="wm-callout mb-0">
                    <h2 class="h6">What you will need</h2>
                    <ul class="small mb-0">
                        <li>Your store name and a short description of what you sell</li>
                        <li>Contact details and your county and town</li>
                        <li>An M-Pesa number or bank account for payouts</li>
                        <?php if ($docs['individual']): ?><li>Individuals: <?= e($names($docs['individual'])) ?></li><?php endif; ?>
                        <?php if ($docs['registered_business']): ?><li>Registered businesses: <?= e($names($docs['registered_business'])) ?></li><?php endif; ?>
                        <?php if ($docs['optional']): ?><li>Optional: <?= e($names($docs['optional'])) ?></li><?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <section class="wm-card wm-page mb-3">
        <h2>How it works</h2>
        <ol class="row g-3 list-unstyled mb-0">
            <?php foreach ([
                ['person-plus', 'Apply', 'Tell us about your business and upload the requested documents.'],
                ['patch-check', 'Get approved', 'Our team reviews your application and emails you the decision.'],
                ['box-seam', 'List products', 'Add your products, prices and stock from your Seller Centre.'],
                ['truck', 'Prepare orders', 'Accept and pack your orders, then hand them over to Waimart for delivery or pickup.'],
                ['cash-coin', 'Get paid', 'Customers pay Waimart. After delivery or collection, your earnings minus commission become available for payout.'],
            ] as $i => [$icon, $head, $text]): ?>
                <li class="col-sm-6 col-lg">
                    <div class="h-100">
                        <i class="bi bi-<?= $icon ?> fs-3 text-success" aria-hidden="true"></i>
                        <div class="fw-semibold mt-1"><?= $i + 1 ?>. <?= e($head) ?></div>
                        <div class="small text-muted"><?= e($text) ?></div>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>
        <p class="small mt-3 mb-0">Read the <a href="<?= e(url('/sell/terms')) ?>">seller terms and policies</a> before you apply.</p>
    </section>
</div>
