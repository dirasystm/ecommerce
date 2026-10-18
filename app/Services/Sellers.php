<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Database;

/** Seller lookups shared across services. */
final class Sellers
{
    private static ?int $platformId = null;

    /** The Waimart Official Store (is_platform = 1, created by migration 010). */
    public static function platformId(?Database $db = null): int
    {
        return self::$platformId ??= (int) (($db ?? App::db())->value('SELECT id FROM sellers WHERE is_platform = 1')
            ?: throw new \RuntimeException('The Waimart Official Store seller is missing (migration 010).'));
    }

    public static function flush(): void
    {
        self::$platformId = null;
    }
}
