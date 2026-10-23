<?php
use Waimart\Core\Csrf;

/** @var array<string,mixed>|null $active @var list<array<string,mixed>> $history @var int $maxMb @var list<array<string,mixed>> $dbPending */
$latestCompleted = null;
foreach ($history as $h) {
    if ($h['status'] === 'completed') {
        $latestCompleted = $h['uuid'];
        break;
    }
}
$badge = ['completed' => 'badge-soft-success', 'rolled_back' => 'badge-soft-warning', 'failed' => 'badge-soft-danger', 'blocked' => 'badge-soft-danger',
          'cancelled' => 'badge-soft-muted', 'nothing' => 'badge-soft-muted'];
$label = ['completed' => 'Installed', 'rolled_back' => 'Rolled back', 'failed' => 'Failed', 'blocked' => 'Blocked', 'cancelled' => 'Cancelled',
          'nothing' => 'Nothing to install', 'ready' => 'Ready to install', 'uploading' => 'Uploading'];
?>
<div class="wm-admin-head"><h1>Website updates</h1></div>
<p class="small text-muted">Upload a <strong>.zip of the project</strong> to update the website while it stays open. Only files that changed and new files are
    installed; nothing is deleted. Database additions (new tables, columns, indexes and reference rows) install with the update. Database changes that
    would modify existing data are <strong>not</strong> run with it: they wait under <a href="#database">Database updates</a> below, for you to run when
    ready. Your settings file, uploads and storage are never touched. Every update can be rolled back.</p>

<div data-updater
     data-base="<?= e(url('/admin/updates')) ?>" data-rescue="<?= e(url('/update-rescue.php')) ?>"
     data-health="<?= e(url('/health')) ?>" data-home="<?= e(url('/')) ?>" data-csrf="<?= e(Csrf::token()) ?>"
     data-active="<?= e((string) json_encode($active)) ?>" data-max="<?= $maxMb * 1024 * 1024 ?>">

    <!-- 1. Upload -->
    <section class="wm-panel mb-3" data-view="upload" <?= $active ? 'hidden' : '' ?>>
        <div class="wm-panel-head">Upload an update</div>
        <div class="wm-panel-body">
            <form class="row g-2 align-items-end" data-upload-form novalidate>
                <div class="col-md-8">
                    <label class="form-label" for="package">Project package (.zip, up to <?= e(\Waimart\Services\SiteUpdater::human($maxMb * 1024 * 1024)) ?>)</label>
                    <input class="form-control" type="file" id="package" accept=".zip,application/zip" required aria-describedby="packageHelp">
                    <div class="form-text" id="packageHelp">Zip the whole project folder (with or without vendor/). It is checked first: nothing on the website changes until you confirm.</div>
                </div>
                <div class="col-md-4"><button class="btn btn-wm-green w-100" type="submit"><i class="bi bi-cloud-arrow-up" aria-hidden="true"></i> Upload and check</button></div>
            </form>
            <p class="small text-danger mt-2 mb-0" data-upload-error role="alert" hidden></p>
        </div>
    </section>

    <!-- 2. Progress: spinner, what is happening, percentage -->
    <section class="wm-panel mb-3" data-view="progress" hidden>
        <div class="wm-panel-body">
            <div class="d-flex align-items-center gap-3">
                <div class="spinner-border text-success flex-shrink-0" style="width:2.6rem;height:2.6rem" role="status" data-spinner><span class="visually-hidden">Working…</span></div>
                <div class="flex-grow-1">
                    <div class="d-flex justify-content-between align-items-baseline">
                        <h2 class="fs-6 fw-bold mb-0" data-phase>Working…</h2>
                        <span class="fs-4 fw-bold text-success" data-percent>0%</span>
                    </div>
                    <p class="small mb-2" data-message role="status" aria-live="polite">Starting…</p>
                    <div class="progress" style="height:10px" role="progressbar" aria-label="Update progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" data-bar-wrap>
                        <div class="progress-bar bg-success progress-bar-striped progress-bar-animated" style="width:0%" data-bar></div>
                    </div>
                </div>
            </div>
            <ol class="wm-update-steps small mt-3 mb-0" data-steps></ol>
            <p class="small text-muted mt-2 mb-0">You can keep using the website, and customers are not affected. Keep this page open until it finishes.</p>
        </div>
    </section>

    <!-- 3. Review -->
    <section class="wm-panel mb-3" data-view="review" hidden>
        <div class="wm-panel-head">Review the update</div>
        <div class="wm-panel-body">
            <p class="mb-2"><strong data-package-name></strong></p>
            <div class="row g-2 text-center mb-3">
                <div class="col-6 col-md-3"><div class="wm-update-stat"><span data-count="changed">0</span>files changed</div></div>
                <div class="col-6 col-md-3"><div class="wm-update-stat"><span data-count="added">0</span>new files</div></div>
                <div class="col-6 col-md-3"><div class="wm-update-stat"><span data-count="unchanged">0</span>unchanged (left alone)</div></div>
                <div class="col-6 col-md-3"><div class="wm-update-stat"><span data-count="db">0</span>database changes</div></div>
            </div>
            <div class="alert alert-danger small" data-blocked hidden>
                <p class="fw-bold mb-1">This update cannot be installed:</p>
                <ul class="mb-1" data-blocked-list></ul>
                <p class="mb-0">Fix the package and upload it again.</p>
            </div>
            <div class="alert alert-warning small" data-deferred hidden>
                <p class="fw-bold mb-1"><i class="bi bi-database-exclamation me-1" aria-hidden="true"></i>Database changes left for later</p>
                <p class="mb-1">These change existing data, so they are <strong>not</strong> run with this update. The files install now; run these
                    afterwards under <strong>Database updates</strong> on this page (a backup is taken first). Until then, anything that needs them may not work.</p>
                <ul class="mb-0" data-deferred-list></ul>
            </div>
            <details class="mb-2"><summary class="small">Files that will change</summary><ul class="small font-monospace mt-2 mb-0" data-list="changed"></ul></details>
            <details class="mb-2"><summary class="small">New files</summary><ul class="small font-monospace mt-2 mb-0" data-list="added"></ul></details>
            <details class="mb-3"><summary class="small">Database additions installed with this update</summary><ul class="small mt-2 mb-0" data-list="db"></ul></details>
            <form class="row g-2 align-items-end" data-install-form novalidate>
                <div class="col-md-6">
                    <label class="form-label" for="installPassword">Your password, to confirm</label>
                    <input class="form-control" type="password" id="installPassword" autocomplete="current-password" required>
                </div>
                <div class="col-md-3"><button class="btn btn-wm-green w-100" type="submit" data-install-button>Install update</button></div>
                <div class="col-md-3"><button class="btn btn-outline-secondary w-100" type="button" data-cancel>Cancel</button></div>
            </form>
            <p class="small text-danger mt-2 mb-0" data-install-error role="alert" hidden></p>
        </div>
    </section>

    <!-- 4. Result -->
    <section class="wm-panel mb-3" data-view="result" hidden>
        <div class="wm-panel-body d-flex gap-3 align-items-start">
            <i class="bi fs-1" data-result-icon aria-hidden="true"></i>
            <div>
                <h2 class="fs-5 fw-bold" data-result-title></h2>
                <p class="mb-2" data-result-message></p>
                <details class="small"><summary>What happened</summary><ol class="mt-2 mb-0" data-result-log></ol></details>
                <a class="btn btn-sm btn-outline-secondary mt-3" href="<?= e(url('/admin/updates')) ?>">Done</a>
            </div>
        </div>
    </section>
</div>

<section class="wm-panel mb-3" id="database" aria-labelledby="dbUpdatesTitle">
    <div class="wm-panel-head"><span id="dbUpdatesTitle"><i class="bi bi-database-gear me-1" aria-hidden="true"></i>Database updates</span>
        <?php if ($dbPending): ?><span class="badge text-bg-danger"><?= count($dbPending) ?> waiting</span><?php else: ?><span class="badge badge-soft-success">Up to date</span><?php endif; ?></div>
    <div class="wm-panel-body">
        <?php if (!$dbPending): ?>
            <p class="mb-0 small text-muted"><i class="bi bi-check-circle-fill text-success me-1" aria-hidden="true"></i>The database has every change the installed version needs. Nothing to run.</p>
        <?php else: ?>
            <p class="small mb-2">The installed version includes database changes that have not been run yet. They run in this order. A full backup of the
                database is taken first, and every step is recorded in the audit log.</p>
            <ol class="small mb-3">
                <?php foreach ($dbPending as $m): ?>
                    <li class="mb-1"><strong class="font-monospace"><?= e($m['file']) ?></strong>
                        <?= $m['changes_data'] ? '<span class="badge badge-soft-warning ms-1">changes existing data</span>' : '<span class="badge badge-soft-success ms-1">additions only</span>' ?>
                        <details><summary class="text-muted">What it does (<?= count($m['statements']) ?> step<?= count($m['statements']) === 1 ? '' : 's' ?>)</summary>
                            <ul class="mt-1 mb-0">
                                <?php foreach ($m['statements'] as $s): ?><li class="<?= $s['ok'] ? '' : 'text-warning-emphasis' ?>"><?= e($s['summary']) ?></li><?php endforeach; ?>
                            </ul></details>
                    </li>
                <?php endforeach; ?>
            </ol>
            <?php if ($active): ?>
                <p class="small text-muted mb-0">Finish or cancel the website update above first.</p>
            <?php else: ?>
                <form class="row g-2 align-items-end" method="post" action="<?= e(url('/admin/updates/database')) ?>"
                      data-confirm="Run <?= count($dbPending) ?> database update<?= count($dbPending) === 1 ? '' : 's' ?> now? A backup is taken first. Best done when the shop is quiet.">
                    <?= csrf_field() ?>
                    <div class="col-md-6">
                        <label class="form-label" for="dbPassword">Your password, to confirm</label>
                        <input class="form-control<?= invalid('password') ?>" type="password" id="dbPassword" name="password" autocomplete="current-password" required>
                        <?= field_error('password') ?>
                    </div>
                    <div class="col-md-6"><button class="btn btn-wm-green w-100" type="submit" data-loading-text="Backing up and updating the database…"><i class="bi bi-database-check" aria-hidden="true"></i> Back up and run database updates</button></div>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<section class="wm-panel">
    <div class="wm-panel-head">Update history</div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead><tr><th scope="col">When</th><th scope="col">Package</th><th scope="col">By</th><th scope="col">Changes</th><th scope="col">Status</th><th scope="col"></th></tr></thead>
            <tbody>
            <?php foreach ($history as $h): ?>
                <tr>
                    <td class="small text-nowrap"><?= e(date('d M Y H:i', strtotime((string) ($h['completed_at'] ?? $h['created_at'])))) ?></td>
                    <td class="small"><?= e($h['package_name']) ?></td>
                    <td class="small"><?= e(trim((string) $h['installer'])) ?: '—' ?></td>
                    <td class="small text-nowrap"><?= (int) $h['files_changed'] ?> changed · <?= (int) $h['files_added'] ?> new · <?= (int) $h['db_changes'] ?> database</td>
                    <td><span class="badge <?= $badge[$h['status']] ?? 'badge-soft-muted' ?>"><?= e($label[$h['status']] ?? ucfirst(str_replace('_', ' ', (string) $h['status']))) ?></span>
                        <?php if ($h['error']): ?><div class="small text-muted text-truncate" style="max-width:280px" title="<?= e((string) $h['error']) ?>"><?= e((string) $h['error']) ?></div><?php endif; ?></td>
                    <td class="text-end">
                        <?php if ($h['uuid'] === $latestCompleted && !$active): ?>
                            <form class="d-flex gap-1 justify-content-end" method="post" action="<?= e(url('/admin/updates/' . $h['uuid'] . '/rollback')) ?>"
                                  data-confirm="Roll back this update? The changed files are restored, new files removed, and the tables and columns it added are removed (with anything saved in them since).">
                                <?= csrf_field() ?>
                                <label class="visually-hidden" for="rb<?= (int) $h['id'] ?>">Your password</label>
                                <input class="form-control form-control-sm<?= invalid('password') ?>" style="max-width:150px" type="password" id="rb<?= (int) $h['id'] ?>" name="password" placeholder="Password" autocomplete="current-password" required>
                                <button class="btn btn-sm btn-outline-danger text-nowrap" type="submit">Roll back</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$history): ?><tr><td colspan="6" class="text-muted small">No updates yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<script src="<?= e(asset('js/updater.js')) ?>" defer></script>
