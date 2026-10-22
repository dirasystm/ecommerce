<?php
use Waimart\Services\Marketing\CampaignService;

/** Campaign fields (create and edit). @var array<string,mixed>|null $c @var array<string,array{name:string,count:int}> $segments */
$v = fn (string $k) => (string) fv($k, (string) ($c[$k] ?? ''));
?>
<div class="col-md-8">
    <label class="form-label" for="name">Campaign name</label>
    <input class="form-control<?= invalid('name') ?>" id="name" name="name" maxlength="120" required value="<?= e($v('name')) ?>" placeholder="e.g. December phones on Facebook">
    <?= field_error('name') ?>
</div>
<div class="col-md-4">
    <label class="form-label" for="objective">Objective</label>
    <select class="form-select<?= invalid('objective') ?>" id="objective" name="objective">
        <?php foreach (CampaignService::OBJECTIVES as $k => $l): ?><option value="<?= e($k) ?>" <?= $v('objective') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
    </select>
</div>
<div class="col-md-4">
    <label class="form-label" for="channel">Channel</label>
    <select class="form-select<?= invalid('channel') ?>" id="channel" name="channel">
        <?php foreach (CampaignService::CHANNELS as $k => $l): ?><option value="<?= e($k) ?>" <?= $v('channel') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
    </select>
</div>
<div class="col-md-4">
    <label class="form-label" for="platform">Platform <span class="text-muted small">(optional)</span></label>
    <select class="form-select<?= invalid('platform') ?>" id="platform" name="platform">
        <option value="">—</option>
        <?php foreach (CampaignService::PLATFORMS as $k => $l): ?><option value="<?= e($k) ?>" <?= $v('platform') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
    </select>
</div>
<div class="col-md-4">
    <label class="form-label" for="segment">Audience <span class="text-muted small">(optional, for your records)</span></label>
    <select class="form-select<?= invalid('segment') ?>" id="segment" name="segment">
        <option value="">Everyone</option>
        <?php foreach ($segments as $k => $s): ?><option value="<?= e($k) ?>" <?= $v('segment') === $k ? 'selected' : '' ?>><?= e($s['name']) ?> (<?= (int) $s['count'] ?>)</option><?php endforeach; ?>
    </select>
</div>
<div class="col-md-4">
    <label class="form-label" for="starts_on">Start date <span class="text-muted small">(optional)</span></label>
    <input class="form-control<?= invalid('starts_on') ?>" type="date" id="starts_on" name="starts_on" value="<?= e($v('starts_on')) ?>">
    <?= field_error('starts_on') ?>
</div>
<div class="col-md-4">
    <label class="form-label" for="ends_on">End date <span class="text-muted small">(optional)</span></label>
    <input class="form-control<?= invalid('ends_on') ?>" type="date" id="ends_on" name="ends_on" value="<?= e($v('ends_on')) ?>">
    <?= field_error('ends_on') ?>
</div>
<div class="col-md-4">
    <label class="form-label" for="cost">Actual cost (KSh) <span class="text-muted small">(optional)</span></label>
    <input class="form-control<?= invalid('cost') ?>" id="cost" name="cost" inputmode="decimal" value="<?= e($v('cost')) ?>" aria-describedby="costHelp">
    <div class="form-text" id="costHelp">What you really paid (ads, printing, influencer). Used only for return on ad spend; never estimated.</div>
    <?= field_error('cost') ?>
</div>
<div class="col-12">
    <label class="form-label" for="notes">Notes <span class="text-muted small">(optional)</span></label>
    <textarea class="form-control<?= invalid('notes') ?>" id="notes" name="notes" rows="2" maxlength="1000"><?= e($v('notes')) ?></textarea>
</div>
