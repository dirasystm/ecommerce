<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Auth;

/**
 * The seller the signed-in user acts for, resolved ONLY from server-side membership (seller_users).
 * Nothing in a request (URL, form field, cookie) can choose or change the seller.
 */
final class SellerContext
{
    /** @var array<string,mixed>|false|null false = looked up, none */
    private static array|false|null $current = null;

    /** @return array<string,mixed>|null seller row + 'member_role' */
    public static function current(): ?array
    {
        if (self::$current === null) {
            $uid = Auth::id();
            $row = $uid === null ? null : App::db()->one(
                'SELECT s.*, su.role AS member_role FROM seller_users su JOIN sellers s ON s.id = su.seller_id WHERE su.user_id = ?',
                [$uid]
            );
            self::$current = $row ?? false;
        }
        return self::$current ?: null;
    }

    public static function id(): ?int
    {
        $s = self::current();
        return $s ? (int) $s['id'] : null;
    }

    public static function role(): ?string
    {
        return self::current()['member_role'] ?? null;
    }

    public static function isActive(): bool
    {
        return (self::current()['status'] ?? null) === 'active';
    }

    /** Throws 403 unless the current seller user has one of the given roles. */
    public static function requireRole(string ...$roles): void
    {
        if (!in_array(self::role(), $roles, true)) {
            throw \Waimart\Core\HttpException::forbidden('Your role in this store does not allow that.');
        }
    }

    /** Throws 403 when the store may not change anything (suspended or closed). */
    public static function requireActive(): void
    {
        if (!self::isActive()) {
            throw \Waimart\Core\HttpException::forbidden('This store is suspended, so changes are not allowed. Your orders and records remain available to view.');
        }
    }

    public static function reset(): void
    {
        self::$current = null;
    }
}
