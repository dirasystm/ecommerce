<?php

declare(strict_types=1);

namespace Waimart\Core;

/**
 * Applies numbered .sql files from database/migrations exactly once each. A migration is identified by its number
 * (the digits before the first "_"), so a file can be renamed without being applied again.
 */
final class Migrator
{
    public function __construct(private Database $db, private string $dir)
    {
    }

    /** @return list<string> applied filenames */
    public function migrate(?callable $out = null): array
    {
        $this->db->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                filename   VARCHAR(190) NOT NULL PRIMARY KEY,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
             ) ENGINE=InnoDB'
        );
        $applied = array_column($this->db->all('SELECT filename FROM schema_migrations'), 'filename');
        $done = [];
        foreach ($this->files() as $file) {
            $name = basename($file);
            if (self::isApplied($name, $applied)) {
                continue;
            }
            $out && $out("Applying $name ...");
            foreach (self::splitStatements((string) file_get_contents($file)) as $sql) {
                try {
                    $this->db->pdo()->exec($sql);
                } catch (\PDOException $e) {
                    throw new \RuntimeException("Migration $name failed: " . $e->getMessage() . "\nSQL: " . mb_substr($sql, 0, 300), 0, $e);
                }
            }
            $this->db->exec('INSERT INTO schema_migrations (filename) VALUES (?)', [$name]);
            $done[] = $name;
        }
        return $done;
    }

    /** Whether a migration file has been applied: same number as a recorded one (or the same name, if unnumbered). @param list<string> $applied */
    public static function isApplied(string $name, array $applied): bool
    {
        $number = static fn (string $n): string => preg_match('/^(\d+)_/', $n, $m) ? $m[1] : $n;
        $want = $number($name);
        foreach ($applied as $a) {
            if ($number((string) $a) === $want) {
                return true;
            }
        }
        return false;
    }

    /** Drops every table in the current database. Callers must guard against production use. */
    public function dropAll(): void
    {
        $pdo = $this->db->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->db->all("SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE()") as $r) {
            $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '', $r['t']) . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** @return list<string> */
    public function files(): array
    {
        $files = glob(rtrim($this->dir, '/') . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        return $files;
    }

    /**
     * Splits SQL into statements on semicolons outside quotes, removing "--" and "#" comments.
     * @return list<string>
     */
    public static function splitStatements(string $sql): array
    {
        $out = [];
        $buf = '';
        $len = strlen($sql);
        $quote = null;
        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];
            if ($quote !== null) {
                $buf .= $c;
                if ($c === '\\' && $i + 1 < $len) {
                    $buf .= $sql[++$i];
                } elseif ($c === $quote) {
                    if ($i + 1 < $len && $sql[$i + 1] === $quote) {
                        $buf .= $sql[++$i];         // doubled quote escape
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }
            if ($c === "'" || $c === '"' || $c === '`') {
                $quote = $c;
                $buf .= $c;
                continue;
            }
            if (($c === '-' && substr($sql, $i, 3) === '-- ') || $c === '#') {
                $nl = strpos($sql, "\n", $i);
                $i = $nl === false ? $len : $nl - 1;
                continue;
            }
            if ($c === ';') {
                if (trim($buf) !== '') {
                    $out[] = trim($buf);
                }
                $buf = '';
                continue;
            }
            $buf .= $c;
        }
        if (trim($buf) !== '') {
            $out[] = trim($buf);
        }
        return $out;
    }
}
