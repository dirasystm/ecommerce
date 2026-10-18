<?php

declare(strict_types=1);

namespace Waimart\Core;

use PDO;
use PDOException;
use PDOStatement;

/**
 * Thin PDO wrapper: prepared statements only, exceptions on error,
 * and a transaction helper that retries on deadlock / lock-wait timeout.
 */
class Database
{
    private PDO $pdo;
    private int $depth = 0;

    /** @param array<string,mixed> $cfg */
    public function __construct(array $cfg)
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $cfg['host'],
            (int) ($cfg['port'] ?? 3306),
            $cfg['database'],
            $cfg['charset'] ?? 'utf8mb4'
        );
        $this->pdo = new PDO($dsn, (string) $cfg['username'], (string) $cfg['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);
        // Strict SQL so bad data errors instead of being silently truncated.
        $this->pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        $this->pdo->exec("SET time_zone = '+03:00'");
        $this->pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** @param array<int|string,mixed> $params */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** @return array<string,mixed>|null */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    public function value(string $sql, array $params = []): mixed
    {
        $v = $this->run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** Executes a write and returns the number of affected rows. */
    public function exec(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    /** @param array<string,mixed> $data */
    public function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(',', array_map(fn ($c) => "`$c`", $cols)),
            implode(',', array_fill(0, count($cols), '?'))
        );
        $this->run($sql, array_values($data));
        return (int) $this->pdo->lastInsertId();
    }

    public function inTransaction(): bool
    {
        return $this->depth > 0;
    }

    /**
     * Runs $fn inside a transaction. Nested calls join the outer transaction.
     * The outermost call retries on deadlock (1213) / lock wait timeout (1205).
     *
     * @template T
     * @param callable(Database):T $fn
     * @return T
     */
    public function transaction(callable $fn, int $retries = 3): mixed
    {
        if ($this->depth > 0) {
            return $fn($this);
        }
        $attempt = 0;
        while (true) {
            $attempt++;
            $this->pdo->beginTransaction();
            $this->depth = 1;
            try {
                $result = $fn($this);
                $this->pdo->commit();
                $this->depth = 0;
                return $result;
            } catch (\Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                $this->depth = 0;
                if ($attempt < $retries && self::isRetryable($e)) {
                    usleep(random_int(20_000, 120_000) * $attempt);
                    continue;
                }
                throw $e;
            }
        }
    }

    public static function isRetryable(\Throwable $e): bool
    {
        if (!$e instanceof PDOException) {
            return false;
        }
        $code = (int) ($e->errorInfo[1] ?? 0);
        return in_array($code, [1213, 1205], true);
    }

    public static function isDuplicateKey(\Throwable $e): bool
    {
        return $e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1062;
    }
}
