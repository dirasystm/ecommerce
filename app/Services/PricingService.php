<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\Money;

/**
 * Single source of truth for the unit price a customer pays for a variant.
 * Used for display now and for server-side checkout recalculation (Phase 6),
 * so the price shown and the price charged come from the same rule.
 */
final class PricingService
{
    /**
     * SQL fragment: active flash-sale price per variant (alias `fs`). Join with
     *   LEFT JOIN (<this>) fs ON fs.variant_id = v.id
     */
    public const ACTIVE_FLASH_SQL =
        'SELECT fsi.variant_id, MIN(fsi.sale_price) AS sale_price, MIN(f.ends_at) AS sale_ends_at
           FROM flash_sale_items fsi
           JOIN flash_sales f ON f.id = fsi.flash_sale_id
          WHERE f.is_active = 1 AND f.starts_at <= NOW() AND f.ends_at > NOW()
          GROUP BY fsi.variant_id';

    /**
     * @return array{price:string, was:?string, discount_percent:int, on_sale:bool}
     */
    public static function resolve(string $basePrice, ?string $compareAt, ?string $salePrice): array
    {
        $price = Money::normalize($basePrice);
        $was = $compareAt !== null ? Money::normalize($compareAt) : null;
        $onSale = false;

        if ($salePrice !== null && Money::cmp($salePrice, $price) < 0) {
            $was = $was !== null ? Money::max($was, $price) : $price;
            $price = Money::normalize($salePrice);
            $onSale = true;
        }
        if ($was !== null && Money::cmp($was, $price) <= 0) {
            $was = null;
        }
        $discount = 0;
        if ($was !== null && !Money::isZero($was)) {
            // floor so we never overstate a discount
            $discount = (int) floor((float) bcdiv(bcmul(Money::sub($was, $price), '100', 4), $was, 4));
        }
        return ['price' => $price, 'was' => $was, 'discount_percent' => $discount, 'on_sale' => $onSale];
    }
}
