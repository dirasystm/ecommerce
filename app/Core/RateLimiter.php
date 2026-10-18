<?php

declare(strict_types=1);

namespace Waimart\Core;

/**
 * Fixed-window rate limiter backed by the rate_limits table.
 * The counter update is a single atomic upsert, so it is safe under concurrency.
 */
final class RateLimiter
{
    public function __construct(private Database $db)
    {
    }

    /** Records a hit and returns true while the caller is within $max hits per window. */
    public function attempt(string $key, int $max, int $windowSeconds): bool
    {
        $k = hash('sha256', $key);
        $this->db->exec(
            'INSERT INTO rate_limits (rl_key, hits, window_start) VALUES (?, 1, NOW())
             ON DUPLICATE KEY UPDATE
               hits = IF(window_start < NOW() - INTERVAL ? SECOND, 1, hits + 1),
               window_start = IF(window_start < NOW() - INTERVAL ? SECOND, NOW(), window_start)',
            [$k, $windowSeconds, $windowSeconds]
        );
        $hits = (int) $this->db->value('SELECT hits FROM rate_limits WHERE rl_key = ?', [$k]);
        return $hits <= $max;
    }

    /** True if the key has already exhausted its allowance (does not record a hit). */
    public function tooMany(string $key, int $max, int $windowSeconds): bool
    {
        $row = $this->db->one(
            'SELECT hits FROM rate_limits WHERE rl_key = ? AND window_start >= NOW() - INTERVAL ? SECOND',
            [hash('sha256', $key), $windowSeconds]
        );
        return $row !== null && (int) $row['hits'] >= $max;
    }

    public function clear(string $key): void
    {
        $this->db->exec('DELETE FROM rate_limits WHERE rl_key = ?', [hash('sha256', $key)]);
    }

    /** Throws 429 when the limit is exceeded. */
    public function enforce(string $key, int $max, int $windowSeconds): void
    {
        if (!$this->attempt($key, $max, $windowSeconds)) {
            throw HttpException::tooMany();
        }
    }
}
