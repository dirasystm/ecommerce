<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Backup;
use Waimart\Core\Database;
use Waimart\Core\Migrator;
use Waimart\Core\ValidationException;

/**
 * Website updates → Database updates: database changes that a website update did not run by itself (because they
 * change existing data), run when the admin chooses. Each run backs the database up first, applies every waiting
 * migration in order, and is recorded in the audit log. bin/migrate.php does the same from the command line.
 */
final class DatabaseUpdates
{
    private Database $db;

    public function __construct(?Database $db = null, private ?string $dir = null)
    {
        $this->db = $db ?? App::db();
        $this->dir ??= (new SiteUpdater($this->db))->root() . '/database/migrations';   // the website's own (in tests, the updater's sandbox)
    }

    /**
     * Migrations on disk that have not been applied, in order, with what each statement does.
     * @return list<array{file:string,changes_data:bool,statements:list<array{summary:string,ok:bool,reason:?string}>}>
     */
    public function pending(): array
    {
        $applied = $this->appliedNames();
        $out = [];
        foreach ((new Migrator($this->db, (string) $this->dir))->files() as $file) {
            $name = basename($file);
            if (Migrator::isApplied($name, $applied)) {
                continue;
            }
            $statements = array_map(fn ($s) => ['summary' => $s['summary'], 'ok' => $s['ok'], 'reason' => $s['reason']],
                UpdateSqlGuard::analyse((string) file_get_contents($file)));
            $out[] = ['file' => $name, 'changes_data' => in_array(false, array_column($statements, 'ok'), true), 'statements' => $statements];
        }
        return $out;
    }

    public function pendingCount(): int
    {
        $applied = $this->appliedNames();
        return count(array_filter((new Migrator($this->db, (string) $this->dir))->files(), fn ($f) => !Migrator::isApplied(basename($f), $applied)));
    }

    /**
     * Backs up the database, then applies every waiting migration in order. Stops at the first error (the ones before it
     * stay applied, and are recorded); the backup is kept either way.
     * @return array{applied:list<string>,backup:string}
     */
    public function run(int $actorId, string $password): array
    {
        $hash = (string) $this->db->value('SELECT password_hash FROM users WHERE id = ?', [$actorId]);
        if ($password === '' || !password_verify($password, $hash)) {
            throw new ValidationException(['password' => 'Your password is incorrect.']);
        }
        if ((new SiteUpdater($this->db))->active() !== null) {
            throw ValidationException::withMessage('A website update is in progress. Finish or cancel it first.');
        }
        $waiting = array_column($this->pending(), 'file');
        if (!$waiting) {
            throw ValidationException::withMessage('The database is already up to date.');
        }
        $lock = (string) $this->db->value("SELECT GET_LOCK('wm_db_updates', 0)");
        if ($lock !== '1') {
            throw ValidationException::withMessage('Database updates are already running. Please wait a moment and refresh.');
        }
        @set_time_limit(600);
        try {
            $database = (string) $this->db->value('SELECT DATABASE()');
            $backup = Backup::dir() . '/before-database-update-' . date('Ymd-His') . '.sql';
            Backup::dump($database, $backup);
            AuditLogger::log('system.db_update_started', 'database', null, null, ['migrations' => $waiting, 'backup' => basename($backup)], $actorId);
            try {
                $done = (new Migrator($this->db, (string) $this->dir))->migrate();
            } catch (\Throwable $e) {
                $done = array_values(array_diff($waiting, array_column($this->pending(), 'file')));
                AuditLogger::log('system.db_update_failed', 'database', null, null,
                    ['applied' => $done, 'error' => mb_substr($e->getMessage(), 0, 500), 'backup' => basename($backup)], $actorId);
                throw new \RuntimeException(($done ? 'Applied ' . implode(', ', $done) . ', then stopped. ' : 'Nothing was applied. ')
                    . 'Error: ' . mb_substr($e->getMessage(), 0, 300) . ' — the backup taken just before is storage/backups/' . basename($backup) . '.', 0, $e);
            }
            AuditLogger::log('system.db_update_completed', 'database', null, null, ['applied' => $done, 'backup' => basename($backup)], $actorId);
            return ['applied' => $done, 'backup' => basename($backup)];
        } finally {
            $this->db->value("SELECT RELEASE_LOCK('wm_db_updates')");
        }
    }

    /** @return list<string> */
    private function appliedNames(): array
    {
        try {
            return array_column($this->db->all('SELECT filename FROM schema_migrations'), 'filename');
        } catch (\PDOException) {
            return [];
        }
    }
}
