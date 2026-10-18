<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Database;
use Waimart\Core\Money;
use Waimart\Core\Settings;
use Waimart\Core\ValidationException;
use Waimart\Core\Validator;

/**
 * Builds a fully validated, server-priced checkout draft (C2 steps 1–3).
 * Nothing posted by the browser is trusted for money: prices, delivery fees and totals are
 * recalculated here. Phase 6 calls prepare() inside the order transaction, then reserves stock.
 */
final class CheckoutService
{
    private Database $db;

    public function __construct(?Database $db = null, private ?DeliveryService $delivery = null)
    {
        $this->db = $db ?? App::db();
        $this->delivery ??= new DeliveryService($this->db);
    }

    /**
     * @param array<string,mixed> $in posted checkout form
     * @param array<string,mixed>|null $user signed-in customer
     * @return array<string,mixed> draft order
     */
    public function prepare(CartService $cart, array $in, ?array $user): array
    {
        $summary = $cart->summary();
        if ($summary['is_empty']) {
            throw ValidationException::withMessage('Your cart is empty.');
        }
        if ($summary['has_issues']) {
            throw new ValidationException(['_cart' => 'Some items in your cart changed (price, stock or availability). Please review your cart before checking out.']);
        }

        $customer = $this->validateCustomer($in, $user);
        $method = (string) ($in['fulfilment_method'] ?? '');
        $fulfilment = match ($method) {
            'delivery' => $this->validateDelivery($in, $user, $summary['subtotal']),
            'pickup' => $this->validatePickup($in, $summary['subtotal']),
            default => throw new ValidationException(['fulfilment_method' => 'Choose home delivery or a pickup station.']),
        };

        $payment = (string) ($in['payment_method'] ?? '');
        $options = PaymentOptions::forFulfilment($fulfilment['cod_allowed']);
        if (!isset($options[$payment])) {
            throw new ValidationException(['payment_method' => 'Choose a payment method.']);
        }
        if (!$options[$payment]['available']) {
            throw new ValidationException(['payment_method' => (string) $options[$payment]['reason']]);
        }

        Validator::validate($in, ['notes' => 'nullable|max:500', 'coupon_code' => 'nullable|max:40'], ['notes' => 'Order notes']);

        // Coupons discount merchandise only (never the delivery fee, never below zero).
        // Inside the order transaction the coupon row is locked so usage limits hold under concurrency.
        $discount = '0.00';
        $coupon = null;
        $code = trim((string) ($in['coupon_code'] ?? ''));
        if ($code !== '') {
            $eval = (new CouponService($this->db))->evaluate(
                $code, $summary['subtotal'], $user !== null ? (int) $user['id'] : null, $customer['phone'], $this->db->inTransaction()
            );
            $discount = $eval['discount'];
            $coupon = $eval['coupon'];
        }
        $total = Money::sub(Money::add($summary['subtotal'], $fulfilment['fee']), $discount);

        return [
            'customer' => $customer,
            'user_id' => $user !== null ? (int) $user['id'] : null,
            'fulfilment' => $fulfilment,
            'payment_method' => $payment,
            'lines' => $summary['lines'],
            'item_count' => $summary['item_count'],
            'subtotal' => $summary['subtotal'],
            'delivery_fee' => $fulfilment['fee'],
            'discount_total' => $discount,
            'coupon' => $coupon,
            'grand_total' => $total,
            'notes' => trim((string) ($in['notes'] ?? '')) ?: null,
        ];
    }

    /** @return array{name:string,phone:string,email:?string} */
    private function validateCustomer(array $in, ?array $user): array
    {
        $emailRequired = $user !== null || Settings::bool('guest_email_required', false);
        Validator::validate($in, [
            'customer_name' => 'required|max:160',
            'customer_phone' => 'required|phone',
            'customer_email' => ($emailRequired ? 'required' : 'nullable') . '|email',
        ], ['customer_name' => 'Full name', 'customer_phone' => 'Phone number', 'customer_email' => 'Email']);
        return [
            'name' => trim((string) $in['customer_name']),
            'phone' => (string) Validator::normalizePhone((string) $in['customer_phone']),
            'email' => ($e = strtolower(trim((string) ($in['customer_email'] ?? '')))) !== '' ? $e : null,
        ];
    }

    /** @return array<string,mixed> */
    private function validateDelivery(array $in, ?array $user, string $subtotal): array
    {
        $addressId = (string) ($in['address_id'] ?? '');
        if ($user !== null && $addressId !== '' && $addressId !== 'new') {
            // Saved address — must belong to this customer.
            $addr = (new AccountService($this->db))->address((int) $user['id'], (int) $addressId);
            if ($addr === null) {
                throw new ValidationException(['address_id' => 'Choose one of your saved addresses.']);
            }
            $addr = ['county_id' => (int) $addr['county_id'], 'town' => $addr['town'], 'location' => $addr['location'], 'instructions' => $addr['instructions']];
        } else {
            $addr = AccountService::validateAddress([
                'recipient_name' => $in['customer_name'] ?? '',
                'phone' => $in['customer_phone'] ?? '',
                'county_id' => $in['county_id'] ?? '',
                'town' => $in['town'] ?? '',
                'location' => $in['location'] ?? '',
                'instructions' => $in['instructions'] ?? '',
            ], $this->db);
        }
        $quote = $this->delivery->quoteDelivery($addr['county_id'], $addr['town'], $subtotal);
        $county = (string) $this->db->value('SELECT name FROM counties WHERE id = ?', [$addr['county_id']]);
        if ($quote === null) {
            throw new ValidationException(['town' => sprintf(
                'Sorry, we do not deliver to %s, %s yet. Please choose a pickup station or a different address.', $addr['town'], $county
            )]);
        }
        return [
            'method' => 'delivery',
            'county_id' => $addr['county_id'],
            'county_name' => $county,
            'town' => $addr['town'],
            'location' => $addr['location'],
            'instructions' => $addr['instructions'],
            'zone_id' => $quote['zone_id'],
            'zone_name' => $quote['zone_name'],
            'eta_min' => $quote['eta_min'],
            'eta_max' => $quote['eta_max'],
            'fee' => $quote['fee'],
            'is_free' => $quote['is_free'],
            'cod_allowed' => $quote['cod_allowed'],
        ];
    }

    /** @return array<string,mixed> */
    private function validatePickup(array $in, string $subtotal): array
    {
        $id = (string) ($in['pickup_point_id'] ?? '');
        $quote = ctype_digit($id) ? $this->delivery->quotePickup((int) $id, $subtotal) : null;
        if ($quote === null) {
            throw new ValidationException(['pickup_point_id' => 'Choose an available pickup station.']);
        }
        if ($quote['error'] !== null) {
            throw new ValidationException(['pickup_point_id' => $quote['error']]);
        }
        $p = $quote['point'];
        return [
            'method' => 'pickup',
            'pickup_point_id' => (int) $p['id'],
            'pickup_code' => $p['code'],
            'pickup_name' => $p['name'],
            'pickup_county' => $p['county_name'],
            'pickup_town' => $p['town'],
            'pickup_address' => $p['address'],
            'pickup_map_url' => $p['map_url'],
            'pickup_phone' => $p['phone'],
            'pickup_hours' => $p['opening_hours'],
            'pickup_instructions' => $p['instructions'],
            'fee' => $quote['fee'],
            'is_free' => Money::isZero($quote['fee']),
            'cod_allowed' => $quote['cod_allowed'],
        ];
    }
}
