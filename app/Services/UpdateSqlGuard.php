<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\Migrator;

/**
 * Decides whether a migration may run while the website is live, during a web update (SiteUpdater).
 *
 * Only additive changes are allowed, so existing data is never touched and the running code keeps working:
 * new tables, new columns that are optional or have a default, named indexes and constraints, triggers, views and
 * new reference rows (INSERT). Everything else is refused before anything is changed: UPDATE, DELETE, DROP,
 * TRUNCATE, RENAME, REPLACE, column changes (MODIFY / CHANGE / ALTER COLUMN), new primary keys, copying data
 * (CREATE TABLE … SELECT), and required columns without a default (old code inserting rows would fail).
 * Such migrations are run by the owner during a quiet period with php bin/migrate.php instead.
 *
 * Each allowed statement lists the objects it creates, so the updater can record how to remove exactly those
 * objects if the update is rolled back.
 */
final class UpdateSqlGuard
{
    private const ID = '`?([A-Za-z0-9_$]+)`?';

    /**
     * @return list<array{sql:string,ok:bool,kind:string,reason:?string,summary:string,objects:list<array{type:string,table:?string,name:string}>}>
     */
    public static function analyse(string $sqlText): array
    {
        $out = [];
        foreach (Migrator::splitStatements($sqlText) as $sql) {
            $out[] = self::statement($sql);
        }
        return $out;
    }

    /** @return array{sql:string,ok:bool,kind:string,reason:?string,summary:string,objects:list<array{type:string,table:?string,name:string}>} */
    public static function statement(string $sql): array
    {
        $s = trim((string) preg_replace('/\s+/', ' ', $sql));
        $ok = fn (string $kind, string $summary, array $objects = []) => ['sql' => $sql, 'ok' => true, 'kind' => $kind, 'reason' => null, 'summary' => $summary, 'objects' => $objects];
        $no = fn (string $reason) => ['sql' => $sql, 'ok' => false, 'kind' => 'refused', 'reason' => $reason, 'summary' => mb_substr($s, 0, 120), 'objects' => []];
        $id = self::ID;

        if (preg_match('/^CREATE TABLE [^(]*\bSELECT\b/i', $s) || (preg_match('/^CREATE TABLE\b/i', $s) && preg_match('/\)\s*(AS\s+)?SELECT\b/i', $s))) {
            return $no('Creating a table from a query copies data; create it empty instead.');
        }
        if (preg_match("/^CREATE TABLE (IF NOT EXISTS )?$id\s*(\(|LIKE\b)/i", $s, $m)) {
            return $ok('create_table', "New table {$m[2]}", [['type' => 'table', 'table' => $m[2], 'name' => $m[2]]]);
        }
        if (preg_match("/^CREATE (UNIQUE |FULLTEXT |SPATIAL )?INDEX (IF NOT EXISTS )?$id ON $id/i", $s, $m)) {
            return $ok('create_index', "New index {$m[3]} on {$m[4]}", [['type' => 'index', 'table' => $m[4], 'name' => $m[3]]]);
        }
        if (preg_match("/^CREATE (DEFINER ?= ?\S+ )?TRIGGER (IF NOT EXISTS )?$id/i", $s, $m)) {
            return $ok('create_trigger', "New trigger {$m[3]}", [['type' => 'trigger', 'table' => null, 'name' => $m[3]]]);
        }
        if (preg_match("/^CREATE (ALGORITHM ?= ?\w+ )?(DEFINER ?= ?\S+ )?(SQL SECURITY \w+ )?VIEW (IF NOT EXISTS )?$id/i", $s, $m)) {
            return $ok('create_view', "New view {$m[5]}", [['type' => 'view', 'table' => null, 'name' => $m[5]]]);
        }
        if (preg_match("/^INSERT (IGNORE )?INTO $id/i", $s, $m)) {
            return $ok('insert', "New rows in {$m[2]}" . ($m[1] !== '' ? ' (existing rows kept)' : ''));
        }
        if (preg_match('/^SET (SESSION )?(FOREIGN_KEY_CHECKS|UNIQUE_CHECKS|NAMES|SQL_MODE|@\w+)\b/i', $s)) {
            return $ok('set', 'Session setting');
        }
        if (preg_match("/^ALTER TABLE $id (.+)$/is", $s, $m)) {
            return self::alter($sql, $m[1], $m[2]);
        }
        $verb = strtoupper((string) strtok($s, ' '));
        return $no(match ($verb) {
            'UPDATE' => 'Changes existing rows (UPDATE).',
            'DELETE' => 'Deletes existing rows (DELETE).',
            'DROP' => 'Removes a table, column or other object (DROP).',
            'TRUNCATE' => 'Empties a table (TRUNCATE).',
            'RENAME' => 'Renames a table (RENAME).',
            'REPLACE' => 'Overwrites existing rows (REPLACE).',
            'CREATE' => 'Only new tables, indexes, triggers and views can be created.',
            default => "This kind of statement ($verb) is not allowed in a live update.",
        });
    }

    /** ALTER TABLE: every clause must add something (or be an online-DDL option). */
    private static function alter(string $sql, string $table, string $rest): array
    {
        $id = self::ID;
        $objects = [];
        $summaries = [];
        foreach (self::clauses($rest) as $clause) {
            $c = trim($clause);
            if (preg_match('/^(ALGORITHM|LOCK)\s*=\s*\w+$/i', $c)) {
                continue;
            }
            if (preg_match('/^ADD (PRIMARY KEY|PERIOD)\b/i', $c)) {
                return self::refuse($sql, "Adding a primary key to the existing table $table is not allowed in a live update.");
            }
            if (preg_match("/^ADD CONSTRAINT $id (FOREIGN KEY|UNIQUE|CHECK)\b/i", $c, $m)) {
                $type = strtoupper($m[2]) === 'FOREIGN KEY' ? 'foreign_key' : (strtoupper($m[2]) === 'CHECK' ? 'check' : 'index');
                $objects[] = ['type' => $type, 'table' => $table, 'name' => $m[1]];
                $summaries[] = ($type === 'foreign_key' ? 'link' : ($type === 'check' ? 'rule' : 'unique index')) . " {$m[1]}";
                continue;
            }
            if (preg_match('/^ADD (FOREIGN KEY|CONSTRAINT|CHECK)\b/i', $c)) {
                return self::refuse($sql, "Give every new constraint on $table a name (ADD CONSTRAINT name …), so it can be removed again on rollback.");
            }
            if (preg_match("/^ADD (UNIQUE( INDEX| KEY)?|FULLTEXT( INDEX| KEY)?|SPATIAL( INDEX| KEY)?|INDEX|KEY) (IF NOT EXISTS )?$id\s*\(/i", $c, $m)) {
                $objects[] = ['type' => 'index', 'table' => $table, 'name' => $m[6]];
                $summaries[] = "index {$m[6]}";
                continue;
            }
            if (preg_match('/^ADD (UNIQUE|FULLTEXT|SPATIAL|INDEX|KEY)\b/i', $c)) {
                return self::refuse($sql, "Give every new index on $table a name (ADD INDEX name (…)), so it can be removed again on rollback.");
            }
            if (preg_match('/^ADD \(/i', $c)) {
                return self::refuse($sql, "Add columns to $table one by one (ADD COLUMN name …).");
            }
            if (preg_match("/^ADD (COLUMN )?(IF NOT EXISTS )?$id (.+)$/is", $c, $m)) {
                $def = $m[4];
                if (preg_match('/\bAUTO_INCREMENT\b/i', $def)) {
                    return self::refuse($sql, "Adding an AUTO_INCREMENT column to the existing table $table is not allowed in a live update.");
                }
                $generated = (bool) preg_match('/\b(GENERATED ALWAYS|AS)\s*\(/i', $def);
                if (!$generated && preg_match('/\bNOT NULL\b/i', $def) && !preg_match('/\bDEFAULT\b/i', $def)) {
                    return self::refuse($sql, "The new column $table.{$m[3]} is required (NOT NULL) but has no DEFAULT: the running website would fail to save rows. Make it NULL or give it a DEFAULT.");
                }
                $objects[] = ['type' => 'column', 'table' => $table, 'name' => $m[3]];
                $summaries[] = "column {$m[3]}";
                continue;
            }
            $verb = strtoupper((string) strtok($c, ' '));
            return self::refuse($sql, match ($verb) {
                'DROP' => "Removes something from $table (DROP).",
                'MODIFY', 'CHANGE', 'ALTER' => "Changes an existing column of $table ($verb).",
                'RENAME' => "Renames $table or one of its columns (RENAME).",
                default => "Changes the existing table $table in a way a live update does not allow ($verb).",
            });
        }
        if (!$objects) {
            return self::refuse($sql, "Nothing to add to $table.");
        }
        return ['sql' => $sql, 'ok' => true, 'kind' => 'alter_add', 'reason' => null, 'summary' => "Add to $table: " . implode(', ', $summaries), 'objects' => $objects];
    }

    private static function refuse(string $sql, string $reason): array
    {
        return ['sql' => $sql, 'ok' => false, 'kind' => 'refused', 'reason' => $reason, 'summary' => mb_substr(trim((string) preg_replace('/\s+/', ' ', $sql)), 0, 120), 'objects' => []];
    }

    /** Splits an ALTER TABLE body on commas that are not inside brackets or quotes. @return list<string> */
    private static function clauses(string $body): array
    {
        $out = [];
        $buf = '';
        $depth = 0;
        $quote = null;
        $len = strlen($body);
        for ($i = 0; $i < $len; $i++) {
            $ch = $body[$i];
            if ($quote !== null) {
                $buf .= $ch;
                if ($ch === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $quote = $ch;
            } elseif ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
            } elseif ($ch === ',' && $depth === 0) {
                $out[] = $buf;
                $buf = '';
                continue;
            }
            $buf .= $ch;
        }
        if (trim($buf) !== '') {
            $out[] = $buf;
        }
        return $out;
    }

    /** The statements that remove one object again (used on rollback). @param array{type:string,table:?string,name:string} $o @return list<string> */
    public static function undo(array $o): array
    {
        $q = fn (string $n) => '`' . str_replace('`', '', $n) . '`';
        return match ($o['type']) {
            'table' => ['DROP TABLE IF EXISTS ' . $q($o['name'])],
            'column' => ['ALTER TABLE ' . $q((string) $o['table']) . ' DROP COLUMN IF EXISTS ' . $q($o['name'])],
            'index' => ['ALTER TABLE ' . $q((string) $o['table']) . ' DROP INDEX IF EXISTS ' . $q($o['name'])],
            'foreign_key' => ['ALTER TABLE ' . $q((string) $o['table']) . ' DROP FOREIGN KEY IF EXISTS ' . $q($o['name']),
                              'ALTER TABLE ' . $q((string) $o['table']) . ' DROP INDEX IF EXISTS ' . $q($o['name'])],
            'check' => ['ALTER TABLE ' . $q((string) $o['table']) . ' DROP CONSTRAINT IF EXISTS ' . $q($o['name'])],
            'trigger' => ['DROP TRIGGER IF EXISTS ' . $q($o['name'])],
            'view' => ['DROP VIEW IF EXISTS ' . $q($o['name'])],
            default => [],
        };
    }
}
