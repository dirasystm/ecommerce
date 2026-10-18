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

    /** Consistent dump (single transaction) of $database to $file. Throws on failure. */
    public static function dump(string $database, string $file): void
    {
        self::assertIdentifier($database);
        [$code, $out] = self::run((string) config('paths.mysqldump'), [
            '--single-transaction', '--quick', '--routines', '--triggers', '--default-character-set=utf8mb4',
            '--result-file=' . $file, $database,
        ]);
        if ($code !== 0 || !is_file($file) || filesize($file) === 0) {
            @unlink($file);
            throw new \RuntimeException("Dump of `$database` failed (exit $code): $out");
        }
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
