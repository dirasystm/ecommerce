<?php
use Waimart\Core\Validator;

/** @var array<string,list<array<string,mixed>>> $byCounty */
?>
<div class="container-wm">
    <nav class="wm-breadcrumb" aria-label="Breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= e(url('/')) ?>">Home</a></li>
            <li class="breadcrumb-item"><a href="<?= e(url('/delivery-pickup')) ?>">Delivery &amp; pickup</a></li>
            <li class="breadcrumb-item active" aria-current="page">Pickup stations</li>
        </ol>
    </nav>
    <div class="wm-card wm-page">
        <h1>Pickup stations</h1>
        <p>Choose a pickup station at checkout. We will notify you with a private collection code when your order is <strong>ready for collection</strong> — please wait for that message before visiting.</p>
        <?php if (!$byCounty): ?>
            <p class="text-muted">No pickup stations are open at the moment.</p>
        <?php endif; ?>
        <?php foreach ($byCounty as $county => $points): ?>
            <h2><?= e($county) ?></h2>
            <div class="row g-3">
                <?php foreach ($points as $p): ?>
                    <div class="col-md-6">
                        <article class="border rounded p-3 h-100">
                            <h3 class="fs-6 fw-bold mb-1"><?= e($p['name']) ?></h3>
                            <p class="small mb-1"><i class="bi bi-geo-alt" aria-hidden="true"></i> <?= e($p['address']) ?>, <?= e($p['town']) ?></p>
                            <p class="small mb-1"><i class="bi bi-clock" aria-hidden="true"></i> <?= e($p['opening_hours']) ?></p>
                            <?php if ($p['phone']): ?><p class="small mb-1"><i class="bi bi-telephone" aria-hidden="true"></i> <?= e(Validator::displayPhone((string) $p['phone'])) ?></p><?php endif; ?>
                            <?php if ($p['instructions']): ?><p class="small text-muted mb-1"><?= e($p['instructions']) ?></p><?php endif; ?>
                            <p class="small mb-0">
                                <?= (float) $p['pickup_fee'] > 0 ? 'Pickup fee ' . e(money($p['pickup_fee'])) : 'Free pickup' ?>
                                · <?= (int) $p['cod_allowed'] ? 'Cash on collection accepted' : 'Prepaid (M-Pesa) orders only' ?>
                                <?php if ($p['map_url']): ?> · <a href="<?= e($p['map_url']) ?>" target="_blank" rel="noopener noreferrer">Map</a><?php endif; ?>
                            </p>
                        </article>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>
