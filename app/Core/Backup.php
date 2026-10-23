<?php

declare(strict_types=1);

namespace Waimart\Core;

/**
 * Database dumps and restores through the MySQL client tools, plus copies of the files a
 * database dump does not contain (product images, local secrets). Used by bin/backup.php and bin/restore.php.
 * Credentials go through a temporary defaults file, never on the command line.
 */
final class Backup
{
    public static function dir(): string
    {
        $dir = App::root() . '/storage/backups';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir;
    }

    /**
     * Consistent dump (single transaction) of $database to $file. Uses mysqldump; where the host does not allow it
     * (shared hosting often disables running programs, or has no mysqldump), the built-in PHP dump is used instead.
     * Throws only if both fail.
     */
    public static function dump(string $database, string $file): void
    {
        self::assertIdentifier($database);
        $tool = (string) config('paths.mysqldump');
        $out = 'proc_open is disabled on this server';
        if (function_exists('proc_open') && $tool !== '' && @is_executable($tool)) {
            [$code, $out] = self::run($tool, [
                '--single-transaction', '--quick', '--routines', '--triggers', '--default-character-set=utf8mb4',
                '--result-file=' . $file, $database,
            ]);
            if ($code === 0 && is_file($file) && filesize($file) > 0) {
                return;
            }
            $out = "exit $code: $out";
        } elseif (function_exists('proc_open')) {
            $out = "mysqldump not found at $tool";
        }
        @unlink($file);
        try {
            self::phpDump(App::db(), $database, $file);
        } catch (\Throwable $e) {
            @unlink($file);
            throw new \RuntimeException("Backup of `$database` failed. mysqldump: $out. Built-in backup: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Dump written by PHP alone (tables, rows, views and triggers), loadable with the mysql client or phpMyAdmin.
     * Reads in one transaction, so the copy is consistent, and streams rows to the file to keep memory low.
     */
    public static function phpDump(Database $db, string $database, string $file): void
    {
        self::assertIdentifier($database);
        $pdo = $db->pdo();
        $fh = fopen($file, 'wb');
        if ($fh === false) {
            throw new \RuntimeException("Cannot write $file");
        }
        $w = static function (string $s) use ($fh, $file): void {
            if (fwrite($fh, $s) === false) {
                throw new \RuntimeException("Cannot write $file (disk full?)");
            }
        };
        $quote = static function ($v) use ($pdo): string {
            if ($v === null) {
                return 'NULL';
            }
            $v = (string) $v;
            return mb_check_encoding($v, 'UTF-8') ? $pdo->quote($v) : '0x' . bin2hex($v);
        };
        $strip = static fn (string $sql): string => (string) preg_replace('/\sDEFINER=`[^`]*`@`[^`]*`/', '', $sql);
        $w("-- Built-in backup of `$database`, " . date('Y-m-d H:i:s') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET UNIQUE_CHECKS = 0;\n\n");
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        try {
            $objects = $pdo->query("SHOW FULL TABLES FROM `$database`")->fetchAll(\PDO::FETCH_NUM);
            foreach ($objects as [$table, $type]) {
                if ($type !== 'BASE TABLE') {
                    continue;
                }
                $create = $pdo->query("SHOW CREATE TABLE `$database`.`$table`")->fetch(\PDO::FETCH_NUM)[1];
                $w("DROP TABLE IF EXISTS `$table`;\n$create;\n");
                // Generated columns are computed again on restore (a value written into one is refused in strict mode).
                $cols = array_column($db->all(
                    "SELECT column_name AS c FROM information_schema.columns WHERE table_schema = ? AND table_name = ?
                        AND extra NOT LIKE '%GENERATED%' AND extra NOT LIKE '%VIRTUAL%' AND extra NOT LIKE '%PERSISTENT%' AND extra NOT LIKE '%STORED%'
                      ORDER BY ordinal_position",
                    [$database, $table]
                ), 'c');
                $list = implode(', ', array_map(fn ($c) => '`' . str_replace('`', '``', $c) . '`', $cols));
                $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
                try {
                    $rows = $pdo->query("SELECT $list FROM `$database`.`$table`", \PDO::FETCH_NUM);
                    $batch = [];
                    foreach ($rows as $row) {
                        $batch[] = '(' . implode(',', array_map($quote, $row)) . ')';
                        if (count($batch) === 200) {
                            $w("INSERT INTO `$table` ($list) VALUES\n" . implode(",\n", $batch) . ";\n");
                            $batch = [];
                        }
                    }
                    if ($batch) {
                        $w("INSERT INTO `$table` ($list) VALUES\n" . implode(",\n", $batch) . ";\n");
                    }
                    $rows->closeCursor();
                } finally {
                    $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
                }
                $w("\n");
            }
            foreach ($objects as [$view, $type]) {
                if ($type === 'VIEW') {
                    $create = $pdo->query("SHOW CREATE VIEW `$database`.`$view`")->fetch(\PDO::FETCH_NUM)[1];
                    $w("DROP VIEW IF EXISTS `$view`;\n" . $strip($create) . ";\n\n");
                }
            }
            foreach ($pdo->query("SHOW TRIGGERS FROM `$database`")->fetchAll(\PDO::FETCH_ASSOC) as $t) {
                $create = $pdo->query("SHOW CREATE TRIGGER `$database`.`{$t['Trigger']}`")->fetch(\PDO::FETCH_ASSOC)['SQL Original Statement'];
                $w("DROP TRIGGER IF EXISTS `{$t['Trigger']}`;\nDELIMITER ;;\n" . $strip($create) . ";;\nDELIMITER ;\n\n");
            }
        } finally {
            $pdo->exec('COMMIT');
        }
        $w("SET UNIQUE_CHECKS = 1;\nSET FOREIGN_KEY_CHECKS = 1;\n-- end of backup\n");
        fclose($fh);
    }

    /** Loads a dump into $database. The caller is responsible for emptying it first. Throws on failure. */
    public static function import(string $database, string $file): void
    {
        self::assertIdentifier($database);
        if (!is_file($file) || filesize($file) === 0) {
            throw new \RuntimeException("Backup file not found or empty: $file");
        }
        [$code, $out] = self::run((string) config('paths.mysql'), ['--default-character-set=utf8mb4', $database], $file);
        if ($code !== 0) {
            throw new \RuntimeException("Import into `$database` failed (exit $code): $out");
        }
    }

    /** @return list<string> base tables in $database */
    public static function tables(Database $db, string $database): array
    {
        return array_column($db->all(
            "SELECT table_name AS t FROM information_schema.tables WHERE table_schema = ? AND table_type = 'BASE TABLE' ORDER BY table_name",
            [$database]
        ), 't');
    }

    /** Drops every table in $database (foreign-key checks off for the duration). */
    public static function emptyDatabase(Database $db, string $database): void
    {
        self::assertIdentifier($database);
        $pdo = $db->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach (self::tables($db, $database) as $t) {
                $pdo->exec("DROP TABLE `$database`.`$t`");
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    /** Recursively copies a directory (used for uploads). Returns the number of files copied. */
    public static function copyTree(string $from, string $to): int
    {
        if (!is_dir($from)) {
            return 0;
        }
        if (!is_dir($to)) {
            mkdir($to, 0775, true);
        }
        $n = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $item) {
            $target = $to . '/' . substr(str_replace('\\', '/', $item->getPathname()), strlen(str_replace('\\', '/', $from)) + 1);
            if ($item->isDir()) {
                is_dir($target) || mkdir($target, 0775, true);
            } elseif (copy($item->getPathname(), $target)) {
                $n++;
            }
        }
        return $n;
    }

    public static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    private static function assertIdentifier(string $name): void
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            throw new \InvalidArgumentException("Unsafe database name: $name");
        }
    }

    /**
     * Runs a MySQL client tool with credentials from a temporary defaults file.
     * @param list<string> $args
     * @return array{0:int,1:string} exit code, combined output
     */
    private static function run(string $binary, array $args, ?string $stdinFile = null): array
    {
        $cnf = (string) tempnam(sys_get_temp_dir(), 'wmb');
        file_put_contents($cnf, sprintf(
            "[client]\nuser=%s\npassword=\"%s\"\nhost=%s\nport=%d\n",
            config('db.username'),
            addcslashes((string) config('db.password'), "\"\\"),
            config('db.host'),
            (int) config('db.port')
        ));
        try {
            $spec = [0 => $stdinFile !== null ? ['file', $stdinFile, 'r'] : ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $proc = proc_open(array_merge([$binary, '--defaults-extra-file=' . $cnf], $args), $spec, $pipes);
            if (!is_resource($proc)) {
                return [127, "Cannot start $binary"];
            }
            if ($stdinFile === null) {
                fclose($pipes[0]);
            }
            $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            return [proc_close($proc), trim((string) $out)];
        } finally {
            @unlink($cnf);
        }
    }
}
