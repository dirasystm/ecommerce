<?php
/**
 * Order journey: estimated arrival, then every step with the office handling it.
 * @var array{steps:list<array<string,mixed>>,eta:?array<string,mixed>,alert:?array{tone:string,title:string,text:string}} $journey
 */
$eta = $journey['eta'];
$alert = $journey['alert'];
$stateLabel = ['done' => 'Completed', 'current' => 'In progress', 'upcoming' => 'Not started yet', 'stopped' => 'Will not happen'];
?>
<div class="wm-journey-wrap mt-3">
    <?php if ($eta !== null): ?>
        <div class="wm-eta wm-eta-<?= e((string) $eta['state']) ?>">
            <i class="bi bi-<?= $eta['state'] === 'done' ? 'check2-circle' : ($eta['kind'] === 'pickup' ? 'shop' : 'calendar-check') ?>" aria-hidden="true"></i>
            <div>
                <div class="wm-eta-label"><?= e((string) $eta['label']) ?></div>
                <div class="wm-eta-date"><?= e((string) $eta['text']) ?></div>
                <?php if ($eta['state'] === 'estimated'): ?>
                    <div class="wm-eta-note">An estimate in working days (Monday to Saturday). We send you an SMS and email at every step.</div>
                <?php elseif ($eta['state'] === 'today'): ?>
                    <div class="wm-eta-note">Our rider is on the way. Please keep your phone on.</div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($alert !== null): ?>
        <div class="alert alert-<?= e($alert['tone']) ?> small mt-3 mb-0" role="status"><strong><?= e($alert['title']) ?>.</strong> <?= e($alert['text']) ?></div>
    <?php endif; ?>

    <h3 class="fs-6 fw-bold mt-4 mb-2">Order progress</h3>
    <ol class="wm-journey" aria-label="Order progress">
        <?php foreach ($journey['steps'] as $i => $s): ?>
            <li class="wm-journey-step is-<?= e((string) $s['state']) ?>"<?= $s['state'] === 'current' ? ' aria-current="step"' : '' ?>>
                <span class="wm-journey-marker" aria-hidden="true">
                    <?php if ($s['state'] === 'done'): ?><i class="bi bi-check-lg"></i><?php elseif ($s['state'] === 'stopped'): ?><i class="bi bi-dash-lg"></i><?php else: ?><?= $i + 1 ?><?php endif; ?>
                </span>
                <div class="wm-journey-body">
                    <div class="d-flex flex-wrap align-items-baseline gap-2">
                        <strong class="wm-journey-title"><?= e((string) $s['title']) ?></strong>
                        <span class="visually-hidden">(<?= e($stateLabel[$s['state']]) ?>)</span>
                        <?php if ($s['state'] === 'current'): ?><span class="badge badge-soft-warning">In progress</span><?php endif; ?>
                        <?php if ($s['at']): ?><span class="small text-muted"><?= e(date('j M Y, g:i a', strtotime((string) $s['at']))) ?></span><?php endif; ?>
                    </div>
                    <div class="wm-journey-office small"><i class="bi bi-building" aria-hidden="true"></i>
                        <?= $s['state'] === 'done' ? 'Handled by' : ($s['state'] === 'current' ? 'With' : 'Handled by') ?> <span><?= e((string) $s['office']) ?></span></div>
                    <?php if ($s['text'] !== ''): ?><p class="small mb-0 mt-1"><?= e((string) $s['text']) ?></p><?php endif; ?>
                    <?php if (!empty($s['sub'])): ?><p class="small text-muted mb-0"><?= e((string) $s['sub']) ?></p><?php endif; ?>
                    <?php if (!empty($s['tracking'])): ?><p class="small mb-0 mt-1">Tracking number: <strong><?= e(implode(', ', $s['tracking'])) ?></strong></p><?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ol>
</div>
