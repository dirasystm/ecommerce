<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\Money;
use Waimart\Core\Settings;
use Waimart\Core\Validator;

/**
 * Customer-facing wording for order states (F). Messages never say a payment succeeded
 * because a code was submitted, and never say a pickup order is ready before staff mark it.
 */
final class OrderPresenter
{
    public const STATUS_LABELS = [
        'awaiting_payment' => 'Awaiting payment',
        'awaiting_verification' => 'Payment submitted — awaiting verification',
        'pending_confirmation' => 'Placed — awaiting confirmation',
        'confirmed' => 'Confirmed',
        'processing' => 'Processing',
        'packed' => 'Packed',
        'dispatched' => 'Dispatched',
        'out_for_delivery' => 'Out for delivery',
        'delivered' => 'Delivered',
        'ready_for_collection' => 'Ready for collection',
        'collected' => 'Collected',
        'cancelled' => 'Cancelled',
        'expired' => 'Reservation expired',
        'requires_resolution' => 'Under review by our team',
        'return_requested' => 'Return requested',
        'returned' => 'Returned',
    ];

    public const PAYMENT_LABELS = [
        'unpaid' => 'Not yet paid',
        'awaiting_verification' => 'Submitted — awaiting verification',
        'verified' => 'Payment verified',
        'rejected' => 'Payment not verified',
        'cod_pending' => 'Pay on delivery / collection',
        'cod_collected' => 'Paid (cash)',
        'refund_due' => 'Refund due',
        'refunded' => 'Refunded',
    ];

    public static function status(string $s): string
    {
        return self::STATUS_LABELS[$s] ?? ucfirst(str_replace('_', ' ', $s));
    }

    /** Customer-facing labels for a seller's part of an order. */
    public const SELLER_ORDER_LABELS = [
        'pending_acceptance' => 'Waiting for confirmation', 'accepted' => 'Confirmed', 'rejected' => 'Not available from this seller',
        'processing' => 'Being prepared', 'packed' => 'Packed', 'handed_over' => 'With {store} for delivery',
        'dispatched' => 'Dispatched', 'out_for_delivery' => 'Out for delivery', 'delivered' => 'Delivered',
        'ready_for_collection' => 'Ready for collection', 'collected' => 'Collected', 'cancelled' => 'Cancelled',
    ];

    public static function sellerOrderStatus(string $s): string
    {
        return brand_text(self::SELLER_ORDER_LABELS[$s] ?? ucfirst(str_replace('_', ' ', $s)));
    }

    public static function payment(string $s): string
    {
        return self::PAYMENT_LABELS[$s] ?? ucfirst(str_replace('_', ' ', $s));
    }

    public static function badgeClass(string $s): string
    {
        return match ($s) {
            'delivered', 'collected', 'confirmed' => 'badge-soft-success',
            'cancelled', 'expired', 'returned' => 'badge-soft-danger',
            'requires_resolution', 'awaiting_payment', 'awaiting_verification', 'pending_confirmation' => 'badge-soft-warning',
            default => 'badge-soft-muted',
        };
    }

    /** What the customer should do / expect next. */
    public static function nextStepText(array $order, ?string $expiresAt): string
    {
        $deadline = $expiresAt ? date('j M Y, g:i a', strtotime($expiresAt)) : null;
        return match ($order['status']) {
            'awaiting_payment' => 'Please pay ' . Money::format((string) $order['grand_total']) . ' by M-Pesa'
                . ($deadline ? " before $deadline" : '') . ' and submit your M-Pesa transaction code. '
                . 'Your items are reserved until then. Your order is confirmed only after we verify the payment.',
            'awaiting_verification' => 'We have received your M-Pesa transaction code and are verifying it. This does not yet confirm your payment.',
            'pending_confirmation' => 'Our team will confirm your cash-on-delivery order shortly'
                . ($deadline ? " (items reserved until $deadline)" : '') . '. You will pay when you receive your order.',
            'confirmed', 'processing', 'packed' => $order['fulfilment_method'] === 'pickup'
                ? 'Your order is being prepared. We will notify you when it is ready for collection — please wait for that message before going to the station.'
                : 'Your order is being prepared for delivery.',
            'dispatched', 'out_for_delivery' => 'Your order is on its way.',
            'ready_for_collection' => 'Your order is ready for collection. Bring your collection code and ID to the pickup station.',
            'delivered' => 'Your order has been delivered. Thank you for shopping with ' . brand_name() . '!',
            'collected' => 'Your order has been collected. Thank you for shopping with ' . brand_name() . '!',
            'cancelled' => 'This order has been cancelled.',
            'expired' => 'The reservation for this order expired before it was confirmed, so the items were released.',
            'requires_resolution' => 'Our team is reviewing this order and will contact you about the next steps.',
            default => 'We will keep you updated.',
        };
    }

    /** Payment instructions for M-Pesa orders. @return list<array{label:string,value:string}> */
    public static function mpesaInstructions(array $order): array
    {
        return match ($order['payment_method']) {
            'mpesa_till' => [
                ['label' => 'Lipa na M-Pesa', 'value' => 'Buy Goods and Services'],
                ['label' => 'Till number', 'value' => (string) Settings::get('mpesa_till_number', '')],
                ['label' => 'Amount', 'value' => Money::format((string) $order['grand_total'])],
            ],
            'mpesa_paybill' => [
                ['label' => 'Lipa na M-Pesa', 'value' => 'Pay Bill'],
                ['label' => 'Business number', 'value' => (string) Settings::get('mpesa_paybill_number', '')],
                ['label' => 'Account number', 'value' => (string) $order['order_number']],
                ['label' => 'Amount', 'value' => Money::format((string) $order['grand_total'])],
            ],
            'mpesa_phone' => [
                ['label' => 'M-Pesa', 'value' => 'Send Money'],
                ['label' => 'Phone number', 'value' => (string) Settings::get('mpesa_phone_number', '')
                    . (($n = Settings::get('mpesa_phone_name', '')) ? " ($n)" : '')],
                ['label' => 'Amount', 'value' => Money::format((string) $order['grand_total'])],
            ],
            default => [],
        };
    }

    /** 254712345678 => 07•• ••• 678 (guest tracking view). */
    public static function maskPhone(string $phone): string
    {
        $d = Validator::displayPhone($phone);
        return preg_replace('/^(\d{2})\d{2} \d{3} (\d{3})$/', '$1•• ••• $2', $d) ?? '•••';
    }

    public static function firstName(string $name): string
    {
        return explode(' ', trim($name))[0] ?? '';
    }
}
