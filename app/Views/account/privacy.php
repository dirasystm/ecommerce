<?php
use Waimart\Core\View;
use Waimart\Services\Marketing\Consent;

/** @var array{email:bool,sms:bool,personalisation:bool,frequency:string} $choices @var list<array<string,mixed>> $history @var bool $hasPhone */
$channelName = ['email' => 'Marketing emails', 'sms' => 'Marketing SMS', 'personalisation' => 'Personalised suggestions'];
$sourceName = ['registration' => 'When you registered', 'preferences' => 'On this page', 'newsletter' => 'Newsletter sign-up',
               'unsubscribe_link' => 'Unsubscribe link', 'admin' => 'By our team', 'cookie_banner' => 'Website banner'];
ob_start(); ?>
<p class="small text-muted">Order and account messages (confirmations, delivery updates, codes) are separate and are set under <a href="<?= e(url('/account/notifications')) ?>">Notifications</a>. The choices below are only about offers and suggestions, and you can change them at any time.</p>
<form method="post" action="<?= e(url('/account/privacy')) ?>" style="max-width:640px">
    <?= csrf_field() ?>
    <fieldset class="mb-3">
        <legend class="fs-6 fw-semibold">Offers and news</legend>
        <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" id="marketing_email" name="marketing_email" value="1" <?= $choices['email'] ? 'checked' : '' ?>>
            <label class="form-check-label" for="marketing_email"><?= e(Consent::WORDING['email']) ?></label>
        </div>
        <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" id="marketing_sms" name="marketing_sms" value="1" <?= $choices['sms'] ? 'checked' : '' ?> <?= $hasPhone ? '' : 'disabled' ?> aria-describedby="smsHelp">
            <label class="form-check-label" for="marketing_sms"><?= e(Consent::WORDING['sms']) ?></label>
            <?php if (!$hasPhone): ?><div class="form-text" id="smsHelp">Add a phone number under <a href="<?= e(url('/account/profile')) ?>">Profile</a> first.</div><?php endif; ?>
        </div>
        <label class="form-label small mt-1" for="frequency">How often</label>
        <select class="form-select form-select-sm" id="frequency" name="frequency" style="max-width:260px">
            <option value="normal" <?= $choices['frequency'] === 'normal' ? 'selected' : '' ?>>Normal</option>
            <option value="less" <?= $choices['frequency'] === 'less' ? 'selected' : '' ?>>Less often (at most once a week)</option>
        </select>
    </fieldset>
    <fieldset class="mb-3">
        <legend class="fs-6 fw-semibold">Personalisation</legend>
        <div class="form-check">
            <input class="form-check-input" type="checkbox" id="personalisation" name="personalisation" value="1" <?= $choices['personalisation'] ? 'checked' : '' ?> aria-describedby="persHelp">
            <label class="form-check-label" for="personalisation"><?= e(Consent::WORDING['personalisation']) ?></label>
            <div class="form-text" id="persHelp">When off, we stop recording what you browse and show popular products instead. See your <a href="<?= e(url('/account/history')) ?>">shopping history</a>.</div>
        </div>
    </fieldset>
    <button class="btn btn-wm" type="submit">Save choices</button>
</form>

<h2 class="fs-6 fw-bold text-uppercase mt-4">Your data</h2>
<p class="small mb-2">Download what we keep about your shopping activity, consent choices and marketing messages.</p>
<a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/account/privacy/export')) ?>"><i class="bi bi-download" aria-hidden="true"></i> Download my data</a>

<?php if ($history): ?>
    <h2 class="fs-6 fw-bold text-uppercase mt-4">History of your choices</h2>
    <div class="table-responsive"><table class="table table-sm small">
        <caption class="visually-hidden">Your consent history, newest first</caption>
        <thead><tr><th scope="col">When</th><th scope="col">Choice</th><th scope="col">Where</th></tr></thead>
        <tbody>
        <?php foreach ($history as $h): ?>
            <tr><td class="text-nowrap"><?= e(date('d M Y, H:i', strtotime((string) $h['created_at']))) ?></td>
                <td><?= e($channelName[$h['channel']] ?? (string) $h['channel']) ?>: <strong><?= (int) $h['granted'] ? 'Yes' : 'No' ?></strong></td>
                <td><?= e($sourceName[$h['source']] ?? (string) $h['source']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
<?php endif; ?>
<?php
$body = (string) ob_get_clean();
echo View::partial('account/_layout', compact('title', 'section', 'body'));
