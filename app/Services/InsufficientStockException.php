<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\ValidationException;

/** Raised inside the checkout transaction when locked stock cannot cover the order (C2 step 7). */
final class InsufficientStockException extends ValidationException
{
    /** @param list<array{variant_id:int,name:string,requested:int,available:int}> $shortages */
    public function __construct(public readonly array $shortages)
    {
        $parts = array_map(
            fn ($s) => $s['available'] > 0
                ? sprintf('"%s" (only %d left)', $s['name'], $s['available'])
                : sprintf('"%s" (sold out)', $s['name']),
            $shortages
        );
        $msg = 'Sorry, stock is no longer available for ' . implode(', ', $parts) . '. Please update your cart.';
        parent::__construct(['_stock' => $msg], $msg);
    }
}
