<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Auth;
use Waimart\Core\Crypto;
use Waimart\Core\Session;

/**
 * Who may see an order (D1, D2): the signed-in owner, or a visitor holding proof of access —
 * the private tracking token, or a successful order-number + phone lookup — remembered in
 * the session for a limited time. Order numbers alone never grant access.
 */
final class OrderAccess
{
    private const SESSION_KEY = 'order_access';
    private const GRANT_TTL = 86400;    // 24 hours

    public static function isOwner(array $order): bool
    {
        return $order['user_id'] !== null && (int) $order['user_id'] === Auth::id();
    }

    /**
     * Records proof of access. Levels: 'placed' (this session placed the order), 'token' (private
     * link), 'lookup' (order number + phone — weaker, because phone numbers are semi-public).
     */
    public static function grant(string $orderNumber, string $level = 'lookup'): void
    {
        $grants = (array) Session::get(self::SESSION_KEY, []);
        $existing = self::grantLevel($orderNumber);
        // Never downgrade a stronger grant already held in this session.
        if ($existing === 'placed' || ($existing === 'token' && $level === 'lookup')) {
            $level = $existing;
        }
        $grants[$orderNumber] = ['t' => time(), 'l' => $level];
        uasort($grants, fn ($a, $b) => ($b['t'] ?? 0) <=> ($a['t'] ?? 0));
        Session::set(self::SESSION_KEY, array_slice($grants, 0, 20, true));    // keep the session small
    }

    public static function grantLevel(string $orderNumber): ?string
    {
        $g = ((array) Session::get(self::SESSION_KEY, []))[$orderNumber] ?? null;
        if (!is_array($g) || ($g['t'] ?? 0) <= time() - self::GRANT_TTL) {
            return null;
        }
        return (string) ($g['l'] ?? 'lookup');
    }

    public static function hasGrant(string $orderNumber): bool
    {
        return self::grantLevel($orderNumber) !== null;
    }

    public static function canView(array $order): bool
    {
        return self::isOwner($order) || self::hasGrant((string) $order['order_number']);
    }

    /** B4: the collection code is shown only to the owner, the placing session or a private-link holder. */
    public static function canSeeCollectionCode(array $order): bool
    {
        return self::isOwner($order) || in_array(self::grantLevel((string) $order['order_number']), ['placed', 'token'], true);
    }

    public static function tokenValid(int $orderId, string $token): bool
    {
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
            return false;
        }
        return (bool) App::db()->value(
            'SELECT 1 FROM order_access_tokens WHERE order_id = ? AND token_hash = ? AND revoked_at IS NULL
                AND (expires_at IS NULL OR expires_at > NOW())',
            [$orderId, Crypto::hash($token)]
        );
    }
}
