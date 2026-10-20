<?php

declare(strict_types=1);

namespace Waimart\Services;

/**
 * Undoes a website update from its journal. Deliberately self-contained (no other classes of the website, only
 * PHP and PDO), so it still works if the update itself broke the site: it is used by the updater (automatic and
 * manual rollback), by public/update-rescue.php (the browser's emergency rollback) and by bin/update-rescue.php.
 *
 * It restores replaced files from the update's backup copies, removes files and folders the update added, removes
 * the database objects the update created (in reverse order) and forgets the migrations it recorded. Every step is
 * safe to repeat. Existing data is never touched: the update only ever added to the database.
 */
final class UpdateRescue
{
    /** @return array{ok:bool,files_restored:int,files_removed:int,db_statements:int,errors:list<string>} */
    public static function rollback(string $journalFile, ?\PDO $pdo = null, ?int $actorId = null): array
    {
        $journal = json_decode((string) @file_get_contents($journalFile), true);
        if (!is_array($journal) || !isset($journal['root'])) {
            return ['ok' => false, 'files_restored' => 0, 'files_removed' => 0, 'db_statements' => 0, 'errors' => ['The update journal could not be read.']];
        }
        $root = rtrim((string) $journal['root'], '/\\');
        $errors = [];
        $restored = 0;
        $removed = 0;

        // 1. Files: put back every replaced file, remove every added file, clear any temporary copies.
        foreach ($journal['applied']['replaced'] ?? [] as $r) {
            $target = $root . '/' . $r['path'];
            if (!is_file((string) $r['backup'])) {
                $errors[] = "Backup copy missing for {$r['path']}";
                continue;
            }
            $tmp = $target . '.bmrescue';
            if (@copy((string) $r['backup'], $tmp) && self::replace($tmp, $target)) {
                $restored++;
            } else {
                @unlink($tmp);
                $errors[] = "Could not restore {$r['path']}";
            }
        }
        foreach ($journal['applied']['added'] ?? [] as $rel) {
            $target = $root . '/' . $rel;
            if (is_file($target) && !@unlink($target)) {
                $errors[] = "Could not remove $rel";
                continue;
            }
            $removed++;
            self::invalidate($target);
        }
        foreach ($journal['staged'] ?? [] as $s) {
            @unlink($root . '/' . $s['temp']);
        }
        $dirs = $journal['created_dirs'] ?? [];
        usort($dirs, fn ($a, $b) => substr_count($b, '/') <=> substr_count($a, '/'));   // deepest first
        foreach ($dirs as $d) {
            @rmdir($root . '/' . $d);   // only if empty
        }

        // 2. Database: remove what the update created, newest first, and forget its migrations.
        $statements = 0;
        $sql = array_reverse($journal['db']['rollback_sql'] ?? []);
        $migrations = $journal['db']['recorded'] ?? [];
        if ($sql || $migrations || isset($journal['db_id'])) {
            try {
                $pdo ??= self::connect(self::appRoot());
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
                foreach ($sql as $stmt) {
                    try {
                        $pdo->exec($stmt);
                        $statements++;
                    } catch (\PDOException $e) {
                        $errors[] = 'Database: ' . $e->getMessage();
                    }
                }
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
                foreach ($migrations as $m) {
                    $pdo->prepare('DELETE FROM schema_migrations WHERE filename = ?')->execute([$m]);
                }
                if (isset($journal['db_id'])) {
                    $pdo->prepare("UPDATE system_updates SET status = 'rolled_back', rolled_back_at = NOW(), rolled_back_by = ?, error = COALESCE(error, ?) WHERE id = ?")
                        ->execute([$actorId, $journal['failure'] ?? null, $journal['db_id']]);
                }
            } catch (\Throwable $e) {
                $errors[] = 'Database: ' . $e->getMessage();
            }
        }

        $journal['status'] = 'rolled_back';
        $journal['rolled_back_at'] = date('c');
        $journal['rollback_errors'] = $errors;
        $journal['progress'] = ['percent' => 100, 'message' => $errors ? 'Rolled back with warnings' : 'Rolled back: the website is as it was before the update'];
        @file_put_contents($journalFile, json_encode($journal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        return ['ok' => $errors === [], 'files_restored' => $restored, 'files_removed' => $removed, 'db_statements' => $statements, 'errors' => $errors];
    }

    /** Moves $tmp over $target in one step (retrying briefly if another process holds the file), then refreshes the opcode cache. */
    public static function replace(string $tmp, string $target): bool
    {
        for ($i = 0; $i < 10; $i++) {
            if (@rename($tmp, $target)) {
                self::invalidate($target);
                return true;
            }
            usleep(50000);
        }
        return false;
    }

    public static function invalidate(string $file): void
    {
        if (function_exists('opcache_invalidate') && str_ends_with($file, '.php')) {
            @opcache_invalidate($file, true);
        }
        clearstatcache(true, $file);
    }

    /** The website's own folder (where its configuration lives), whatever folder the update targeted. */
    public static function appRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /** Records an emergency rollback in the (append-only) audit log, without the rest of the website. @param array<string,mixed> $result */
    public static function audit(string $uuid, array $result, string $how): void
    {
        try {
            self::connect(self::appRoot())->prepare(
                'INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, before_json, after_json, ip_address, user_agent) VALUES (NULL, ?, ?, ?, NULL, ?, ?, ?)'
            )->execute(['system.update_rolled_back', 'system_update', $uuid, json_encode($result + ['emergency' => $how]),
                mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'cli'), 0, 45), mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'cli'), 0, 255)]);
        } catch (\Throwable) {
            // the rollback itself matters more than its log entry
        }
    }

    /** A database connection from the site's own configuration files, read without the rest of the website. */
    public static function connect(string $root): \PDO
    {
        $cfg = require $root . '/config/config.php';
        if (is_file($root . '/config/config.local.php')) {
            $cfg = array_replace_recursive($cfg, require $root . '/config/config.local.php');
        }
        $db = $cfg['db'];
        $name = getenv('WAIMART_ENV') === 'testing' ? ($db['test_database'] ?? 'waimart_test') : $db['database'];
        return new \PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], (int) ($db['port'] ?? 3306), $name),
            (string) $db['username'], (string) $db['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }
}
