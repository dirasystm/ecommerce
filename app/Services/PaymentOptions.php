<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\Settings;

/**
 * Which payment methods can be offered (A4). M-Pesa methods appear only when their
 * business numbers are configured; cash on delivery depends on the fulfilment option.
 */
final class PaymentOptions
{
    public const LABELS = [
        'mpesa_till'    => 'M-Pesa Buy Goods (Till)',
        'mpesa_paybill' => 'M-Pesa Paybill',
        'mpesa_phone'   => 'M-Pesa Send Money',
        'cod'           => 'Cash on delivery / on collection',
    ];

    /**
     * @return array<string,array{label:string,available:bool,reason:?string}>
     */
    public static function forFulfilment(?bool $codAllowed, ?string $codReason = null): array
    {
        $out = [];
        $configured = [
            'mpesa_till' => Settings::get('mpesa_till_number', '') !== '',
            'mpesa_paybill' => Settings::get('mpesa_paybill_number', '') !== '',
            'mpesa_phone' => Settings::get('mpesa_phone_number', '') !== '',
        ];
        foreach ($configured as $method => $ok) {
            if ($ok) {
                $out[$method] = ['label' => self::LABELS[$method], 'available' => true, 'reason' => null];
            }
        }
        $out['cod'] = [
            'label' => self::LABELS['cod'],
            'available' => $codAllowed !== false,
            'reason' => $codAllowed === false ? ($codReason ?? 'Cash on delivery is not available for the selected location.') : null,
        ];
        return $out;
    }
}
