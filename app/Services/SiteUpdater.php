<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Backup;
use Waimart\Core\Crypto;
use Waimart\Core\Database;
use Waimart\Core\Migrator;
use Waimart\Core\ValidationException;
use Waimart\Core\ZipReader;

/**
 * Installs a website update from an uploaded .zip of the project, while the shop stays open (System → Website
 * updates; system.update).
 *
 *   Prepare (nothing on the live site changes): upload in chunks → unpack into a private staging folder → compare
 *   every file with the live one (only changed and new files are used; nothing is ever deleted) → syntax-check
 *   changed PHP → check pending migrations with UpdateSqlGuard (additions only) → show a summary.
 *   Install (after the super admin confirms with their password): back up the database (only if it changes) and
 *   every file about to be replaced → write the new files beside the live ones under temporary names → apply the
 *   database additions, then swap all files in one quick pass (each swap is an atomic rename) → check the site
 *   (the browser loads the home page and health check; the server loads every changed class) → finish.
 *   Any failure after the first change rolls everything back automatically (UpdateRescue); the latest update can
 *   also be rolled back by hand.
 *
 * Work is done in short steps (≈2 seconds each), so long updates never hit a time limit and the browser can show
 * what is happening and how far along it is. Each update has a journal (storage/updates/<uuid>/journal.json) that
 * records every change, and that is all the rollback needs.
 */
final class SiteUpdater
{
    /** Never read from a package or written by an update. */
    public const PROTECTED = ['.git/', 'storage/', 'public/uploads/', 'config/config.local.php', 'config/config.live.php', 'node_modules/', 'tests/Browser/node_modules/',
                              '.phpunit.cache/', '.env', '.idea/', '.vscode/'];
    /** Operating-system clutter that zip tools add; ignored. */
    private const JUNK = ['__MACOSX/', '.DS_Store', 'Thumbs.db', 'desktop.ini'];
    /** The rollback tools: replaced only once the new version has been checked, so a rollback can always run. */
    public const RESCUE_FILES = ['app/Services/UpdateRescue.php', 'public/update-rescue.php', 'bin/update-rescue.php'];

    public const PREPARE = ['uploaded', 'inspecting', 'extracting', 'comparing', 'planning'];
    public const INSTALL = ['approved', 'backing_up_db', 'backing_up_files', 'staging', 'applying', 'verifying', 'finalizing'];
    public const FINAL = ['completed', 'failed', 'rolled_back', 'cancelled', 'nothing'];
    public const MAX_CHUNK = 8 * 1024 * 1024;
    public const MAX_PACKAGE = 300 * 1024 * 1024;
    private const BUDGET = 0.8;   // seconds of work per step: short, so the progress the page shows moves smoothly

    private Database $db;

    public function __construct(?Database $db = null, private ?string $root = null, private ?string $storage = null)
    {
        $this->db = $db ?? App::db();
        // Tests never touch the real files: they update a sandbox project instead.
        $testing = config('app.env') === 'testing';
        $this->root = rtrim(str_replace('\\', '/', $root ?? ($testing ? App::root() . '/storage/updater-sandbox' : App::root())), '/');
        $this->storage = rtrim(str_replace('\\', '/', $storage ?? App::root() . ($testing ? '/storage/updates-test' : '/storage/updates')), '/');
    }

    public function root(): string
    {
        return (string) $this->root;
    }

    // ------------------------------------------------------------------ jobs

    /** The update in progress (not finished), if any. @return array<string,mixed>|null */
    public function active(): ?array
    {
        $uuid = $this->db->value("SELECT uuid FROM system_updates WHERE status NOT IN ('completed','failed','rolled_back','cancelled','nothing') ORDER BY id DESC LIMIT 1");
        return $uuid ? $this->journal((string) $uuid) : null;
    }

    /** @return list<array<string,mixed>> */
    public function history(int $limit = 15): array
    {
        return $this->db->all(
            "SELECT s.*, CONCAT(u.first_name, ' ', u.last_name) AS installer FROM system_updates s LEFT JOIN users u ON u.id = COALESCE(s.installed_by, s.uploaded_by)
              ORDER BY s.id DESC LIMIT " . max(1, min(100, $limit))
        );
    }

    /** @return array<string,mixed> */
    public function journal(string $uuid): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $uuid)) {
            throw new \Waimart\Core\HttpException(404, 'Update not found.');
        }
        $j = json_decode((string) @file_get_contents($this->dir($uuid) . '/journal.json'), true);
        if (!is_array($j)) {
            throw new \Waimart\Core\HttpException(404, 'Update not found.');
        }
        return $j;
    }

    /** Starts an upload. @return array<string,mixed> the new journal */
    public function begin(string $filename, int $size, int $actorId): array
    {
        if ($this->active() !== null) {
            throw ValidationException::withMessage('Another update is in progress. Finish or cancel it first.');
        }
        $name = mb_substr(basename(str_replace('\\', '/', $filename)), 0, 190);
        if (!preg_match('/\.zip$/i', $name)) {
            throw new ValidationException(['package' => 'Choose a .zip file of the project.']);
        }
        if ($size <= 0 || $size > self::MAX_PACKAGE) {
            throw new ValidationException(['package' => 'The package must be smaller than ' . (self::MAX_PACKAGE / 1024 / 1024) . ' MB.']);
        }
        $uuid = bin2hex(random_bytes(16));
        $dir = $this->dir($uuid);
        mkdir($dir . '/staging', 0775, true);
        touch($dir . '/package.zip');
        $id = $this->db->insert('system_updates', ['uuid' => $uuid, 'status' => 'uploading', 'package_name' => $name, 'package_size' => $size, 'uploaded_by' => $actorId]);
        $j = ['uuid' => $uuid, 'db_id' => $id, 'root' => $this->root, 'status' => 'uploading', 'package' => ['name' => $name, 'size' => $size, 'received' => 0, 'chunks' => 0],
              'created_at' => date('c'), 'actor' => $actorId, 'progress' => ['phase' => 'upload', 'percent' => 0, 'message' => 'Uploading the package…'], 'log' => []];
        $this->save($j);
        AuditLogger::log('system.update_uploading', 'system_update', $uuid, null, ['package' => $name, 'size' => $size], $actorId);
        return $j;
    }

    /** Appends one chunk (they must arrive in order). @return array<string,mixed> */
    public function appendChunk(string $uuid, int $index, string $data): array
    {
        $j = $this->journal($uuid);
        if ($j['status'] !== 'uploading') {
            throw ValidationException::withMessage('This update is not accepting uploads.');
        }
        if ($index !== (int) $j['package']['chunks']) {
            throw ValidationException::withMessage('Upload out of order. Please start again.');
        }
        if (strlen($data) === 0 || strlen($data) > self::MAX_CHUNK || $j['package']['received'] + strlen($data) > $j['package']['size']) {
            throw ValidationException::withMessage('Upload too large for the announced package size. Please start again.');
        }
        file_put_contents($this->dir($uuid) . '/package.zip', $data, FILE_APPEND | LOCK_EX);
        $j['package']['received'] += strlen($data);
        $j['package']['chunks']++;
        if ($j['package']['received'] === $j['package']['size']) {
            $j['package']['sha256'] = hash_file('sha256', $this->dir($uuid) . '/package.zip');
            $this->db->exec('UPDATE system_updates SET package_sha256 = ? WHERE id = ?', [$j['package']['sha256'], $j['db_id']]);
            $j = $this->status($j, 'uploaded', 'prepare', 0, 'Upload complete. Checking the package…');
        } else {
            $j['progress'] = ['phase' => 'upload', 'percent' => (int) floor($j['package']['received'] * 100 / $j['package']['size']), 'message' => 'Uploading the package…'];
        }
        $this->save($j);
        return $j;
    }

    /** Super admin confirms the install. @return array{journal:array<string,mixed>,rescue_token:string} */
    public function approve(string $uuid, string $password, int $actorId): array
    {
        $this->checkPassword($actorId, $password);
        $j = $this->journal($uuid);
        if ($j['status'] !== 'ready') {
            throw ValidationException::withMessage('This update is not ready to install.');
        }
        $token = Crypto::token(32);
        $j['rescue_hash'] = hash('sha256', $token);
        $j['installer'] = $actorId;
        $this->db->exec("UPDATE system_updates SET status = 'approved', installed_by = ?, installed_at = NOW() WHERE id = ?", [$actorId, $j['db_id']]);
        $j = $this->status($j, 'approved', 'install', 0, 'Starting the installation…', false);
        $this->save($j);
        AuditLogger::log('system.update_approved', 'system_update', $uuid, null, ['files_changed' => count($j['files']['changed'] ?? []),
            'files_added' => count($j['files']['added'] ?? []), 'migrations' => array_column($j['plan'] ?? [], 'file')], $actorId);
        return ['journal' => $j, 'rescue_token' => $token];
    }

    /** Abandons an update that has not started installing. */
    public function cancel(string $uuid, int $actorId): void
    {
        $j = $this->journal($uuid);
        if (!in_array($j['status'], array_merge(['uploading', 'ready', 'blocked'], self::PREPARE), true)) {
            throw ValidationException::withMessage('Only an update that has not started installing can be cancelled.');
        }
        $j = $this->status($j, 'cancelled', 'done', 100, 'Cancelled. Nothing was changed.');
        $this->save($j);
        $this->removeDir($this->dir($uuid) . '/staging');
        @unlink($this->dir($uuid) . '/package.zip');
        AuditLogger::log('system.update_cancelled', 'system_update', $uuid, null, null, $actorId);
    }

    /** Rolls back the most recent completed update (password confirmed). @return array<string,mixed> */
    public function rollback(string $uuid, string $password, int $actorId): array
    {
        $this->checkPassword($actorId, $password);
        $j = $this->journal($uuid);
        $latest = $this->db->value("SELECT uuid FROM system_updates WHERE status = 'completed' ORDER BY id DESC LIMIT 1");
        if ($j['status'] !== 'completed' || $latest !== $uuid) {
            throw ValidationException::withMessage('Only the most recent completed update can be rolled back.');
        }
        if ($this->active() !== null) {
            throw ValidationException::withMessage('Another update is in progress.');
        }
        $result = UpdateRescue::rollback($this->dir($uuid) . '/journal.json', $this->db->pdo(), $actorId);
        AuditLogger::log('system.update_rolled_back', 'system_update', $uuid, null, $result + ['manual' => true], $actorId);
        return $result;
    }

    /** True if $token is the emergency-rollback token issued when this update was approved. */
    public static function rescueAllowed(array $journal, string $token): bool
    {
        return isset($journal['rescue_hash']) && $token !== '' && hash_equals((string) $journal['rescue_hash'], hash('sha256', $token))
            && in_array($journal['status'], ['staging', 'applying', 'verifying', 'finalizing', 'failed'], true);
    }

    // ------------------------------------------------------------------ steps

    /**
     * Runs the next piece of work for this update and reports progress. Safe to call repeatedly; one at a time.
     * @return array<string,mixed> the journal after the step
     */
    public function step(string $uuid): array
    {
        $lock = fopen($this->dir($uuid) . '/step.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            return $this->journal($uuid) + ['busy' => true];
        }
        try {
            $j = $this->journal($uuid);
            $started = microtime(true);
            // Each stage updates $j in place (by reference), so if it fails half-way the failure handler sees
            // everything it already did (new tables, temporary files) and the rollback can undo exactly that.
            try {
                $j = match ($j['status']) {
                    'uploaded' => $this->inspect($j),
                    'extracting' => $this->extract($j, $started),
                    'comparing' => $this->compare($j, $started),
                    'planning' => $this->plan($j),
                    'approved' => $this->status(array_merge($j, ['cursor' => 0]), empty($j['plan']) ? 'backing_up_files' : 'backing_up_db', 'install', 2,
                        empty($j['plan']) ? 'Backing up the files that will change…' : 'Backing up the database…'),
                    'backing_up_db' => $this->backupDatabase($j),
                    'backing_up_files' => $this->backupFiles($j, $started),
                    'staging' => $this->stage($j, $started),
                    'applying' => $this->apply($j),
                    'verifying' => $this->verify($j),
                    'finalizing' => $this->finalize($j),
                    default => $j,
                };
            } catch (\Throwable $e) {
                $j = $this->fail($j, $e);
            }
            $this->save($j);
            return $j;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Finds the project inside the package and checks it is this website. */
    private function inspect(array &$j): array
    {
        $zip = new ZipReader($this->dir($j['uuid']) . '/package.zip');
        $entries = $zip->entries();
        $names = array_flip(array_column($entries, 'name'));
        $prefix = null;
        foreach ($entries as $e) {
            if (!$e['dir'] && preg_match('#^(.*?)bootstrap\.php$#', $e['name'], $m) && (strlen($m[1]) === 0 || str_ends_with($m[1], '/'))
                && isset($names[$m[1] . 'app/Core/App.php'], $names[$m[1] . 'composer.json']) && ($prefix === null || strlen($m[1]) < strlen($prefix))) {
                $prefix = $m[1];
            }
        }
        if ($prefix === null) {
            throw new \RuntimeException('This package is not a copy of the website: bootstrap.php, app/Core/App.php and composer.json were not found.');
        }
        $theirs = json_decode($zip->read($names[$prefix . 'composer.json']), true)['name'] ?? null;
        $ours = json_decode((string) @file_get_contents($this->root . '/composer.json'), true)['name'] ?? null;
        if ($theirs === null || $theirs !== $ours) {
            throw new \RuntimeException("This package is a different project (\"$theirs\"), not this website (\"$ours\").");
        }
        $files = [];
        $skipped = 0;
        foreach ($entries as $i => $e) {
            if ($e['dir'] || ($prefix !== '' && !str_starts_with($e['name'], $prefix))) {
                continue;
            }
            $rel = substr($e['name'], strlen($prefix));
            if (self::isProtected($rel) || self::isJunk($rel)) {
                $skipped++;
                continue;
            }
            $files[$rel] = [$i, $rel];   // a name listed twice in the archive: the last copy wins, as when unzipping
        }
        $files = array_values($files);
        $j['prefix'] = $prefix;
        $j['entries'] = $files;
        $j['skipped'] = $skipped;
        $j['cursor'] = 0;
        $this->log($j, 'Package checked: ' . count($files) . " files ($skipped protected or system files ignored)");
        return $this->status($j, 'extracting', 'prepare', 5, 'Unpacking files…');
    }

    private function extract(array &$j, float $started): array
    {
        $zip = new ZipReader($this->dir($j['uuid']) . '/package.zip');
        $staging = $this->dir($j['uuid']) . '/staging';
        $n = count($j['entries']);
        while ($j['cursor'] < $n && microtime(true) - $started < self::BUDGET) {
            [$index, $rel] = $j['entries'][$j['cursor']];
            $target = $staging . '/' . $rel;
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0775, true);
            }
            file_put_contents($target, $zip->read($index));
            $j['cursor']++;
        }
        if ($j['cursor'] < $n) {
            return $this->status($j, 'extracting', 'prepare', 5 + (int) (55 * $j['cursor'] / max(1, $n)), "Unpacking files: {$j['cursor']} of $n");
        }
        $j['cursor'] = 0;
        $j['files'] = ['changed' => [], 'added' => [], 'unchanged' => 0];
        $j['lint'] = [];
        return $this->status($j, 'comparing', 'prepare', 60, 'Comparing with the live website…');
    }

    private function compare(array &$j, float $started): array
    {
        $staging = $this->dir($j['uuid']) . '/staging';
        $n = count($j['entries']);
        while ($j['cursor'] < $n && microtime(true) - $started < self::BUDGET) {
            $rel = $j['entries'][$j['cursor']][1];
            $new = $staging . '/' . $rel;
            $live = $this->root . '/' . $rel;
            $j['cursor']++;
            if (is_file($live) && filesize($live) === filesize($new) && hash_file('sha256', $live) === hash_file('sha256', $new)) {
                $j['files']['unchanged']++;
                continue;
            }
            $j['files'][is_file($live) ? 'changed' : 'added'][] = $rel;
            if (str_ends_with($rel, '.php') && ($err = self::lint((string) file_get_contents($new))) !== null) {
                $j['lint'][] = "$rel: $err";
            }
        }
        if ($j['cursor'] < $n) {
            return $this->status($j, 'comparing', 'prepare', 60 + (int) (35 * $j['cursor'] / max(1, $n)), "Comparing files: {$j['cursor']} of $n");
        }
        return $this->status($j, 'planning', 'prepare', 95, 'Checking database changes…');
    }

    /** Pending migrations in the package, checked statement by statement. */
    private function plan(array &$j): array
    {
        $applied = array_flip(array_column($this->db->all('SELECT filename FROM schema_migrations'), 'filename'));
        $plan = [];
        $blocked = [];
        foreach (glob($this->dir($j['uuid']) . '/staging/database/migrations/*.sql') ?: [] as $file) {
            $name = basename($file);
            if (isset($applied[$name])) {
                continue;
            }
            $statements = UpdateSqlGuard::analyse((string) file_get_contents($file));
            foreach ($statements as $s) {
                if (!$s['ok']) {
                    $blocked[] = "$name: {$s['reason']}";
                }
            }
            $plan[] = ['file' => $name, 'statements' => array_map(fn ($s) => ['summary' => $s['summary'], 'ok' => $s['ok'], 'reason' => $s['reason']], $statements)];
        }
        usort($plan, fn ($a, $b) => strcmp($a['file'], $b['file']));
        foreach ($j['lint'] as $l) {
            $blocked[] = "PHP syntax error in $l";
        }
        $j['plan'] = $plan;
        $j['blocked'] = $blocked;
        $changes = count($j['files']['changed']) + count($j['files']['added']);
        $this->db->exec('UPDATE system_updates SET files_changed = ?, files_added = ?, db_changes = ? WHERE id = ?',
            [count($j['files']['changed']), count($j['files']['added']), count($plan), $j['db_id']]);
        if ($blocked) {
            $this->db->exec("UPDATE system_updates SET status = 'blocked', error = ? WHERE id = ?", [mb_substr(implode(' | ', $blocked), 0, 1000), $j['db_id']]);
            $this->log($j, 'Blocked: ' . implode(' | ', $blocked));
            return $this->status($j, 'blocked', 'prepare', 100, 'This update cannot be installed while the website is live. See the reasons below.', false);
        }
        if ($changes === 0 && !$plan) {
            return $this->status($j, 'nothing', 'done', 100, 'The website already has everything in this package. Nothing to install.');
        }
        return $this->status($j, 'ready', 'prepare', 100, 'Ready to install.');
    }

    private function backupDatabase(array &$j): array
    {
        $file = $this->dir($j['uuid']) . '/database-before.sql';
        Backup::dump((string) $this->db->value('SELECT DATABASE()'), $file);
        $j['backup_db'] = $file;
        $this->log($j, 'Database backed up (' . number_format((int) filesize($file)) . ' bytes)');
        $j['cursor'] = 0;
        return $this->status($j, 'backing_up_files', 'install', 20, 'Backing up the files that will change…');
    }

    private function backupFiles(array &$j, float $started): array
    {
        $changed = $j['files']['changed'];
        $n = count($changed);
        $j['cursor'] ??= 0;
        while ($j['cursor'] < $n && microtime(true) - $started < self::BUDGET) {
            $rel = $changed[$j['cursor']];
            $dest = $this->dir($j['uuid']) . '/backup/' . $rel;
            if (!is_dir(dirname($dest))) {
                mkdir(dirname($dest), 0775, true);
            }
            if (!copy($this->root . '/' . $rel, $dest)) {
                throw new \RuntimeException("Could not back up $rel");
            }
            $j['cursor']++;
        }
        if ($j['cursor'] < $n) {
            return $this->status($j, 'backing_up_files', 'install', 20 + (int) (15 * $j['cursor'] / max(1, $n)), "Backing up files: {$j['cursor']} of $n");
        }
        $j['cursor'] = 0;
        $j['staged'] = [];
        $j['created_dirs'] = [];
        return $this->status($j, 'staging', 'install', 35, 'Preparing the new files beside the live ones…');
    }

    /** Writes each new file next to its live counterpart under a temporary name (the live site is untouched). */
    private function stage(array &$j, float $started): array
    {
        $todo = array_values(array_filter(array_merge($j['files']['changed'], $j['files']['added']), fn ($rel) => !in_array($rel, self::RESCUE_FILES, true)));
        $n = count($todo);
        $suffix = '.bmupd-' . substr($j['uuid'], 0, 8);
        while ($j['cursor'] < $n && microtime(true) - $started < self::BUDGET) {
            $rel = $todo[$j['cursor']];
            $target = $this->root . '/' . $rel;
            $this->ensureDir(dirname($rel), $j);
            if (!copy($this->dir($j['uuid']) . '/staging/' . $rel, $target . $suffix)) {
                throw new \RuntimeException("Could not prepare $rel");
            }
            $j['staged'][] = ['path' => $rel, 'temp' => $rel . $suffix];
            $j['cursor']++;
        }
        if ($j['cursor'] < $n) {
            return $this->status($j, 'staging', 'install', 35 + (int) (35 * $j['cursor'] / max(1, $n)), "Preparing new files: {$j['cursor']} of $n");
        }
        $j['applied'] = ['replaced' => [], 'added' => []];
        $j['db'] = ['rollback_sql' => [], 'recorded' => []];
        return $this->status($j, 'applying', 'install', 70, 'Applying database additions and switching files…');
    }

    /** The moment of change: database additions, then every file swapped in one quick pass. */
    private function apply(array &$j): array
    {
        // Nothing changes unless every file about to be replaced has its backup copy: a rollback must always be possible.
        foreach ($j['files']['changed'] as $rel) {
            $copy = $this->dir($j['uuid']) . '/backup/' . $rel;
            if (!is_file($copy) || hash_file('sha256', $copy) !== hash_file('sha256', $this->root . '/' . $rel)) {
                throw new \RuntimeException("The backup of $rel is missing or out of date, so the update was stopped before changing anything.");
            }
        }
        $staging = $this->dir($j['uuid']) . '/staging/database/migrations';
        foreach ($j['plan'] as $m) {
            $this->save($j);   // the journal on disk always knows what has been done so far
            foreach (UpdateSqlGuard::analyse((string) file_get_contents($staging . '/' . $m['file'])) as $s) {
                if (!$s['ok']) {
                    throw new \RuntimeException("{$m['file']}: {$s['reason']}");
                }
                $new = array_values(array_filter($s['objects'], fn ($o) => !$this->exists($o)));
                $this->db->pdo()->exec($s['sql']);
                foreach ($new as $o) {
                    array_push($j['db']['rollback_sql'], ...UpdateSqlGuard::undo($o));
                }
                $this->save($j);
            }
            $this->db->exec('INSERT INTO schema_migrations (filename) VALUES (?)', [$m['file']]);
            $j['db']['recorded'][] = $m['file'];
        }
        $this->save($j);
        $changed = array_flip($j['files']['changed']);
        foreach ($j['staged'] as $s) {
            $target = $this->root . '/' . $s['path'];
            // Recorded before the swap, so a rollback also covers a swap interrupted half-way.
            if (isset($changed[$s['path']])) {
                $j['applied']['replaced'][] = ['path' => $s['path'], 'backup' => $this->dir($j['uuid']) . '/backup/' . $s['path']];
            } else {
                $j['applied']['added'][] = $s['path'];
            }
        }
        $this->save($j);
        foreach ($j['staged'] as $s) {
            if (!UpdateRescue::replace($this->root . '/' . $s['temp'], $this->root . '/' . $s['path'])) {
                throw new \RuntimeException("Could not switch {$s['path']} (the file is locked).");
            }
        }
        $j['staged'] = [];
        $j['applied_at'] = date('c');
        $this->log($j, count($j['applied']['replaced']) . ' files replaced, ' . count($j['applied']['added']) . ' added, ' . count($j['db']['recorded']) . ' database changes applied');
        return $this->status($j, 'verifying', 'install', 85, 'Checking the updated website…');
    }

    /** Runs in a new request, i.e. with the new code: every changed class must load, every database addition must exist. */
    private function verify(array &$j): array
    {
        // (Only for the real website: the class loader reads App::root(), so a sandbox relies on the syntax check.)
        $live = str_replace('\\', '/', (string) realpath(App::root())) === str_replace('\\', '/', (string) realpath((string) $this->root));
        foreach ($live ? array_merge(array_column($j['applied']['replaced'], 'path'), $j['applied']['added']) : [] as $rel) {
            if (preg_match('#^app/((?!Views/)[A-Za-z0-9_/]+)\.php$#', $rel, $m) && $rel !== 'app/helpers.php') {
                $class = 'Waimart\\' . str_replace('/', '\\', $m[1]);
                if (!class_exists($class) && !interface_exists($class) && !trait_exists($class) && !enum_exists($class)) {
                    throw new \RuntimeException("The new code does not load: $class was not found in $rel.");
                }
            }
        }
        $pending = array_diff(array_column($j['plan'], 'file'), array_column($this->db->all('SELECT filename FROM schema_migrations'), 'filename'));
        if ($pending) {
            throw new \RuntimeException('Database changes missing: ' . implode(', ', $pending));
        }
        return $this->status($j, 'finalizing', 'install', 95, 'Finishing…');
    }

    private function finalize(array &$j): array
    {
        // The rollback tools themselves are switched last, now that the new version works.
        foreach (array_merge($j['files']['changed'], $j['files']['added']) as $rel) {
            if (!in_array($rel, self::RESCUE_FILES, true)) {
                continue;
            }
            $this->ensureDir(dirname($rel), $j);
            $tmp = $this->root . '/' . $rel . '.bmupd';
            copy($this->dir($j['uuid']) . '/staging/' . $rel, $tmp);
            if (in_array($rel, $j['files']['changed'], true)) {
                $j['applied']['replaced'][] = ['path' => $rel, 'backup' => $this->dir($j['uuid']) . '/backup/' . $rel];
            } else {
                $j['applied']['added'][] = $rel;
            }
            UpdateRescue::replace($tmp, $this->root . '/' . $rel);
        }
        $this->removeDir($this->dir($j['uuid']) . '/staging');
        @unlink($this->dir($j['uuid']) . '/package.zip');
        $j['completed_at'] = date('c');
        $this->db->exec("UPDATE system_updates SET status = 'completed', completed_at = NOW(), error = NULL WHERE id = ?", [$j['db_id']]);
        AuditLogger::log('system.update_completed', 'system_update', $j['uuid'], null,
            ['files_replaced' => count($j['applied']['replaced']), 'files_added' => count($j['applied']['added']), 'migrations' => $j['db']['recorded']], $j['installer'] ?? null);
        $this->prune();
        return $this->status($j, 'completed', 'done', 100, 'Update installed. The website is running the new version.');
    }

    /** Before anything changed: just stop. After: roll everything back. */
    private function fail(array $j, \Throwable $e): array
    {
        $message = mb_substr($e->getMessage(), 0, 900);
        $j['failure'] = $message;
        $this->log($j, 'Failed: ' . $message);
        $changedLive = in_array($j['status'], ['staging', 'applying', 'verifying', 'finalizing'], true);
        if (!$changedLive) {
            $this->db->exec("UPDATE system_updates SET status = 'failed', error = ? WHERE id = ?", [$message, $j['db_id']]);
            return $this->status($j, 'failed', 'done', 100, 'The update could not be prepared: ' . $message . ' Nothing on the website was changed.');
        }
        $this->save($j);
        $result = UpdateRescue::rollback($this->dir($j['uuid']) . '/journal.json', $this->db->pdo());
        $j = $this->journal($j['uuid']);
        $j['rollback'] = $result;
        AuditLogger::log('system.update_rolled_back', 'system_update', $j['uuid'], null, $result + ['automatic' => true, 'reason' => $message], $j['installer'] ?? null);
        $this->db->exec("UPDATE system_updates SET status = 'rolled_back', error = ? WHERE id = ?", [$message, $j['db_id']]);
        return $this->status($j, 'rolled_back', 'done', 100, 'The update failed and was rolled back automatically: ' . $message);
    }

    // ------------------------------------------------------------------ helpers

    public static function isProtected(string $rel): bool
    {
        foreach (self::PROTECTED as $p) {
            if ($rel === rtrim($p, '/') || str_starts_with($rel, $p)) {
                return true;
            }
        }
        return false;
    }

    private static function isJunk(string $rel): bool
    {
        foreach (self::JUNK as $p) {
            if (str_starts_with($rel, $p) || str_contains($rel, '/' . $p) || basename($rel) === $p) {
                return true;
            }
        }
        return str_contains($rel, '.bmupd') || str_ends_with($rel, '.bmrescue');
    }

    /** null when the PHP parses, else the error. */
    public static function lint(string $code): ?string
    {
        try {
            token_get_all($code, TOKEN_PARSE);
            return null;
        } catch (\ParseError $e) {
            return $e->getMessage() . ' (line ' . $e->getLine() . ')';
        }
    }

    /** Whether a database object exists already (then the update did not create it and must not remove it). */
    private function exists(array $o): bool
    {
        return (bool) match ($o['type']) {
            'table' => $this->db->value('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$o['name']]),
            'view' => $this->db->value('SELECT 1 FROM information_schema.views WHERE table_schema = DATABASE() AND table_name = ?', [$o['name']]),
            'column' => $this->db->value('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$o['table'], $o['name']]),
            'index' => $this->db->value('SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?', [$o['table'], $o['name']]),
            'foreign_key', 'check' => $this->db->value('SELECT 1 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = ? AND constraint_name = ?', [$o['table'], $o['name']]),
            'trigger' => $this->db->value('SELECT 1 FROM information_schema.triggers WHERE trigger_schema = DATABASE() AND trigger_name = ?', [$o['name']]),
            default => false,
        };
    }

    private function ensureDir(string $relDir, array &$j): void
    {
        $relDir = trim(str_replace('\\', '/', $relDir), '/.');
        if ($relDir === '' || is_dir($this->root . '/' . $relDir)) {
            return;
        }
        $path = '';
        foreach (explode('/', $relDir) as $seg) {
            $path = ltrim($path . '/' . $seg, '/');
            if (!is_dir($this->root . '/' . $path)) {
                mkdir($this->root . '/' . $path, 0775);
                $j['created_dirs'][] = $path;
            }
        }
    }

    private function checkPassword(int $userId, string $password): void
    {
        $hash = (string) $this->db->value('SELECT password_hash FROM users WHERE id = ?', [$userId]);
        if ($password === '' || !password_verify($password, $hash)) {
            throw new ValidationException(['password' => 'Your password is incorrect.']);
        }
    }

    /** @return array<string,mixed> */
    private function status(array $j, string $status, string $phase, int $percent, string $message, bool $syncDb = true): array
    {
        $j['status'] = $status;
        $j['progress'] = ['phase' => $phase, 'percent' => max(0, min(100, $percent)), 'message' => $message];
        if ($syncDb && isset($j['db_id']) && !in_array($status, ['blocked'], true)) {
            $this->db->exec('UPDATE system_updates SET status = ? WHERE id = ?', [$status, $j['db_id']]);
        }
        return $j;
    }

    private function log(array &$j, string $message): void
    {
        $j['log'][] = ['at' => date('H:i:s'), 'message' => $message];
    }

    private function save(array $j): void
    {
        $file = $this->dir($j['uuid']) . '/journal.json';
        $tmp = $file . '.tmp';
        file_put_contents($tmp, json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
        UpdateRescue::replace($tmp, $file);
    }

    public function dir(string $uuid): string
    {
        return $this->storage . '/' . $uuid;
    }

    /** Keeps the backups of the three most recent finished updates (for rollback); removes older working folders. */
    private function prune(): void
    {
        $keep = array_column($this->db->all('SELECT uuid FROM system_updates ORDER BY id DESC LIMIT 3'), 'uuid');
        foreach (glob($this->storage . '/*', GLOB_ONLYDIR) ?: [] as $d) {
            if (!in_array(basename($d), $keep, true)) {
                $this->removeDir($d);
            }
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
