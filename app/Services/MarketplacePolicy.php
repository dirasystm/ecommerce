<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\Money;
use Waimart\Core\Settings;

/**
 * Marketplace business rules chosen by the client (docs/MARKETPLACE_PLAN.md §7), all stored as settings:
 *  - cash on delivery (`marketplace_cod_policy`): 'pickup_and_platform' (default) offers COD for pickup orders, or for
 *    delivery orders made up only of Waimart Official Store items — so Waimart always holds the money before paying
 *    sellers; 'all' leaves COD to the delivery zone / pickup point alone.
 *  - delivery fee (`marketplace_delivery_fee_policy`): 'per_order' (default) charges the zone/pickup fee once per order,
 *    kept by the platform; 'per_seller' charges it once per seller group (free-delivery threshold checked per group).
 * Used by checkout display, the live quote and order placement, so all three agree.
 */
final class MarketplacePolicy
{
    public static function codPolicy(): string
    {
        return Settings::get('marketplace_cod_policy', 'pickup_and_platform') === 'all' ? 'all' : 'pickup_and_platform';
    }

    public static function feePolicy(): string
    {
        return Settings::get('marketplace_delivery_fee_policy', 'per_order') === 'per_seller' ? 'per_seller' : 'per_order';
    }

    /**
     * Whether COD may be offered for these cart lines and fulfilment method (on top of the zone/pickup point rule).
     * @param list<array<string,mixed>> $lines cart lines with seller_is_platform
     * @return array{allowed:bool,reason:?string}
     */
    public static function cod(array $lines, string $fulfilmentMethod): array
    {
        if (self::codPolicy() === 'all' || $fulfilmentMethod === 'pickup') {
            return ['allowed' => true, 'reason' => null];
        }
        foreach ($lines as $l) {
            if (!(bool) ($l['seller_is_platform'] ?? true)) {
                return ['allowed' => false, 'reason' => 'Cash on delivery is available for pickup orders, or for home delivery of ' . brand_name() . ' Official Store items only. Please pay by M-Pesa or choose a pickup station.'];
            }
        }
        return ['allowed' => true, 'reason' => null];
    }

    /**
     * Cart lines grouped by seller, in first-seen order.
     * @param list<array<string,mixed>> $lines
     * @return array<int,array{seller_id:int,seller_name:string,subtotal:string,lines:list<array<string,mixed>>}>
     */
    public static function groups(array $lines): array
    {
        $g = [];
        foreach ($lines as $l) {
            $sid = (int) $l['seller_id'];
            $g[$sid] ??= ['seller_id' => $sid, 'seller_name' => (string) ($l['seller_name'] ?? ''), 'subtotal' => '0.00', 'lines' => []];
            $g[$sid]['subtotal'] = Money::add($g[$sid]['subtotal'], (string) $l['line_total']);
            $g[$sid]['lines'][] = $l;
        }
        return $g;
    }

    /**
     * Applies the fee policy to a zone/pickup quote.
     * @param callable(string):?array $quote fee quote for a merchandise subtotal: returns ['fee'=>, 'is_free'=>] or null
     * @param list<array<string,mixed>> $lines
     * @return array{fee:string,is_free:bool,shipments:int}|null
     */
    public static function fee(callable $quote, array $lines, string $subtotal): ?array
    {
        if (self::feePolicy() === 'per_order') {
            $q = $quote($subtotal);
            return $q === null ? null : ['fee' => (string) $q['fee'], 'is_free' => (bool) $q['is_free'], 'shipments' => 1];
        }
        $fee = '0.00';
        $groups = self::groups($lines);
        foreach ($groups as $g) {
            $q = $quote($g['subtotal']);
            if ($q === null) {
                return null;
            }
            $fee = Money::add($fee, (string) $q['fee']);
        }
        return ['fee' => $fee, 'is_free' => Money::isZero($fee), 'shipments' => count($groups)];
    }
}
