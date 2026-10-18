<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Settings;
use Waimart\Core\Validator;

/**
 * Admin-editable store settings with validation and audit. Only whitelisted keys can be written.
 */
final class SettingsService
{
    /** key => [label, rule, help, group] */
    public const FIELDS = [
        'store_name' => ['Store name', 'required|max:80', null, 'Store'],
        'store_phone' => ['Customer care phone', 'nullable|max:40', 'Shown in the footer and on the Contact page.', 'Store'],
        'store_email' => ['Customer care email', 'nullable|email', null, 'Store'],

        'mpesa_till_number' => ['Till number (Buy Goods)', 'nullable|int|min:5|max:8', 'Leave blank to hide this payment method.', 'M-Pesa'],
        'mpesa_till_name' => ['Till name', 'nullable|max:60', 'As customers will see it on their M-Pesa confirmation.', 'M-Pesa'],
        'mpesa_paybill_number' => ['Paybill business number', 'nullable|int|min:5|max:7', 'Leave blank to hide this payment method.', 'M-Pesa'],
        'mpesa_paybill_account_hint' => ['Paybill account instruction', 'nullable|max:120', null, 'M-Pesa'],
        'mpesa_phone_number' => ['Send Money phone number', 'nullable|phone', 'Leave blank to hide this payment method.', 'M-Pesa'],
        'mpesa_phone_name' => ['Send Money registered name', 'nullable|max:60', null, 'M-Pesa'],
        'max_payment_submissions' => ['Max payment code submissions per order', 'required|int|gte:1|lte:10', null, 'M-Pesa'],

        'reservation_minutes' => ['Stock hold for M-Pesa orders (minutes)', 'required|int|gte:5|lte:10080', 'How long items are held while the customer pays and we verify. Default 30.', 'Orders'],
        'reservation_minutes_cod' => ['Stock hold for COD orders (minutes)', 'required|int|gte:5|lte:10080', 'How long items are held until staff confirm a COD order. Default 240.', 'Orders'],
        'cod_auto_confirm' => ['Auto-confirm cash-on-delivery orders', 'bool', 'If on, COD orders are confirmed immediately and hold stock until fulfilment.', 'Orders'],
        'guest_email_required' => ['Require email for guest checkout', 'bool', null, 'Orders'],
        'collection_hold_days' => ['Pickup hold period (days)', 'required|int|gte:1|lte:60', null, 'Orders'],
        'return_window_days' => ['Return window (days)', 'required|int|gte:0|lte:90', null, 'Orders'],
    ];

    /** @param array<string,mixed> $in */
    public function save(array $in, int $actorId): void
    {
        $rules = [];
        $labels = [];
        foreach (self::FIELDS as $key => [$label, $rule]) {
            $labels[$key] = $label;
            if ($rule !== 'bool') {
                $rules[$key] = $rule;
            }
        }
        // 'min'/'max' on numeric strings are lengths here (Till 5–8 digits, Paybill 5–7 digits).
        Validator::validate($in, $rules, $labels);

        // Seller onboarding documents (structured setting, edited as one choice per document type).
        $docPolicy = isset($in['doc_mode']) && is_array($in['doc_mode']) ? SellerDocumentPolicy::encode($in['doc_mode']) : null;

        $before = [];
        $after = [];
        App::db()->transaction(function () use ($in, &$before, &$after, $docPolicy) {
            if ($docPolicy !== null && Settings::get('seller_required_documents') !== $docPolicy) {
                $before['seller_required_documents'] = Settings::get('seller_required_documents');
                $after['seller_required_documents'] = $docPolicy;
                Settings::set('seller_required_documents', $docPolicy);
            }
            foreach (self::FIELDS as $key => [, $rule]) {
                $value = $rule === 'bool' ? (!empty($in[$key]) ? '1' : '0') : trim((string) ($in[$key] ?? ''));
                if ($key === 'mpesa_phone_number' && $value !== '') {
                    $value = (string) Validator::normalizePhone($value);
                }
                $old = Settings::get($key);
                if ($old !== $value) {
                    $before[$key] = $old;
                    $after[$key] = $value;
                    Settings::set($key, $value);
                }
            }
        });
        if ($after) {
            AuditLogger::log('settings.updated', 'settings', null, $before, $after, $actorId);
        }
        if (($after['mpesa_till_number'] ?? null) !== null || ($after['mpesa_paybill_number'] ?? null) !== null || ($after['mpesa_phone_number'] ?? null) !== null) {
            // Payment destinations changed — worth a distinct, easy-to-find audit entry.
            AuditLogger::log('settings.payment_destination_changed', 'settings', null,
                array_intersect_key($before, array_flip(['mpesa_till_number', 'mpesa_paybill_number', 'mpesa_phone_number'])),
                array_intersect_key($after, array_flip(['mpesa_till_number', 'mpesa_paybill_number', 'mpesa_phone_number'])), $actorId);
        }
    }
}
