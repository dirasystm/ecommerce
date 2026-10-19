<?php

declare(strict_types=1);

namespace Waimart\Tests\Integration;

use Waimart\Core\Settings;
use Waimart\Core\ValidationException;
use Waimart\Services\AccountService;
use Waimart\Services\CartService;
use Waimart\Services\CheckoutService;
use Waimart\Services\DeliveryService;

final class CheckoutPrepareTest extends DbTestCase
{
    private int $nairobi = 47;
    private int $machakos;
    private int $mandera;
    private int $zoneCity;
    private int $pointOpen;
    private int $pointClosed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->machakos = (int) $this->db->value("SELECT id FROM counties WHERE name = 'Machakos'");
        $this->mandera = (int) $this->db->value("SELECT id FROM counties WHERE name = 'Mandera'");
        $this->zoneCity = $this->db->insert('delivery_zones', ['name' => 'City', 'fee' => '200', 'free_delivery_threshold' => '5000', 'min_days' => 1, 'max_days' => 2, 'cod_allowed' => 1]);
        $metro = $this->db->insert('delivery_zones', ['name' => 'Metro', 'fee' => '300', 'min_days' => 1, 'max_days' => 3, 'cod_allowed' => 1]);
        $far = $this->db->insert('delivery_zones', ['name' => 'Far', 'fee' => '550', 'min_days' => 3, 'max_days' => 6, 'cod_allowed' => 0]);
        $this->db->insert('delivery_zone_locations', ['zone_id' => $this->zoneCity, 'county_id' => $this->nairobi, 'town' => '']);
        $this->db->insert('delivery_zone_locations', ['zone_id' => $metro, 'county_id' => $this->machakos, 'town' => 'Syokimau']);
        $this->db->insert('delivery_zone_locations', ['zone_id' => $far, 'county_id' => $this->machakos, 'town' => '']);
        $this->pointOpen = $this->db->insert('pickup_points', ['code' => 'P1', 'name' => 'Open', 'county_id' => $this->nairobi, 'town' => 'CBD', 'address' => 'Moi Ave', 'opening_hours' => '9-5', 'pickup_fee' => '0', 'cod_allowed' => 1, 'max_order_value' => '20000']);
        $this->pointClosed = $this->db->insert('pickup_points', ['code' => 'P2', 'name' => 'Closed', 'county_id' => $this->nairobi, 'town' => 'CBD', 'address' => 'X', 'opening_hours' => '-', 'is_active' => 0]);
        Settings::set('mpesa_till_number', '123456');
    }

    protected function tearDown(): void
    {
        Settings::set('mpesa_till_number', '');
        Settings::set('guest_email_required', '0');
        parent::tearDown();
    }

    private function cartWith(string $price, int $qty = 1): CartService
    {
        $cart = new CartService(null, null, $this->db);
        $cart->add($this->makeVariant(20, $price)['variant_id'], $qty);
        return $cart;
    }

    private function input(array $o = []): array
    {
        return $o + [
            'customer_name' => 'Guest Buyer', 'customer_phone' => '0722000111', 'customer_email' => '',
            'fulfilment_method' => 'delivery', 'county_id' => (string) $this->nairobi, 'town' => 'Kilimani',
            'location' => 'Argwings Kodhek Rd', 'payment_method' => 'mpesa_till',
        ];
    }

    private function expectField(callable $fn, string $field): void
    {
        try {
            $fn();
            $this->fail("Expected validation error on $field");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors, json_encode($e->errors));
        }
    }

    public function testTownRuleBeatsCountyRuleAndUnsupportedIsNull(): void
    {
        $d = new DeliveryService($this->db);
        $this->assertSame('Metro', $d->quoteDelivery($this->machakos, 'syokimau ', '100')['zone_name']);
        $this->assertSame('Far', $d->quoteDelivery($this->machakos, 'Athi River', '100')['zone_name']);
        $this->assertNull($d->quoteDelivery($this->mandera, 'Mandera', '100'));
    }

    public function testDeliveryFeeComputedServerSideIgnoringPostedValues(): void
    {
        $draft = (new CheckoutService($this->db))->prepare($this->cartWith('1000.00', 2), $this->input([
            'delivery_fee' => '0', 'grand_total' => '1', 'subtotal' => '1',          // tampered fields are ignored
        ]), null);
        $this->assertSame('2000.00', $draft['subtotal']);
        $this->assertSame('200.00', $draft['delivery_fee']);
        $this->assertSame('2200.00', $draft['grand_total']);
        $this->assertSame('City', $draft['fulfilment']['zone_name']);
        $this->assertSame('254722000111', $draft['customer']['phone']);
        $this->assertNull($draft['customer']['email'], 'email optional for guests');
    }

    public function testFreeDeliveryThreshold(): void
    {
        $draft = (new CheckoutService($this->db))->prepare($this->cartWith('5000.00'), $this->input(), null);
        $this->assertSame('0.00', $draft['delivery_fee']);
        $this->assertTrue($draft['fulfilment']['is_free']);
    }

    public function testUnsupportedLocationExplainsAlternative(): void
    {
        $this->expectField(fn () => (new CheckoutService($this->db))->prepare($this->cartWith('100'), $this->input(['county_id' => (string) $this->mandera, 'town' => 'Mandera']), null), 'town');
    }

    /** A saved address outside every zone is explained next to the saved addresses (the town fields are hidden then). */
    public function testUnsupportedSavedAddressIsReportedOnTheAddressChoice(): void
    {
        $owner = $this->db->insert('users', ['email' => 'm@x.co', 'password_hash' => 'x', 'first_name' => 'M', 'last_name' => 'W']);
        $addr = (new AccountService($this->db))->saveAddress($owner, null, ['recipient_name' => 'M', 'phone' => '0712345679', 'county_id' => (string) $this->mandera, 'town' => 'Mandera', 'location' => 'Main St']);
        $user = $this->db->one('SELECT * FROM users WHERE id = ?', [$owner]);
        try {
            (new CheckoutService($this->db))->prepare($this->cartWith('100'), $this->input(['address_id' => (string) $addr, 'customer_email' => 'm@x.co']), $user);
            $this->fail('expected a validation error');
        } catch (ValidationException $e) {
            $this->assertSame(['address_id'], array_keys($e->errors));
            $this->assertStringContainsString('do not deliver to Mandera, Mandera yet', $e->errors['address_id']);
        }
    }

    public function testCodRejectedWhereNotAllowed(): void
    {
        $this->expectField(fn () => (new CheckoutService($this->db))->prepare($this->cartWith('100'), $this->input([
            'county_id' => (string) $this->machakos, 'town' => 'Athi River', 'payment_method' => 'cod',
        ]), null), 'payment_method');
    }

    public function testUnconfiguredMpesaMethodRejected(): void
    {
        $this->expectField(fn () => (new CheckoutService($this->db))->prepare($this->cartWith('100'), $this->input(['payment_method' => 'mpesa_paybill']), null), 'payment_method');
    }

    public function testPickupRules(): void
    {
        $svc = new CheckoutService($this->db);
        $draft = $svc->prepare($this->cartWith('100'), $this->input(['fulfilment_method' => 'pickup', 'pickup_point_id' => (string) $this->pointOpen]), null);
        $this->assertSame('Moi Ave', $draft['fulfilment']['pickup_address'], 'pickup snapshot captured');
        $this->assertSame('0.00', $draft['delivery_fee']);

        $this->expectField(fn () => $svc->prepare($this->cartWith('100'), $this->input(['fulfilment_method' => 'pickup', 'pickup_point_id' => (string) $this->pointClosed]), null), 'pickup_point_id');
        $this->expectField(fn () => $svc->prepare($this->cartWith('25000'), $this->input(['fulfilment_method' => 'pickup', 'pickup_point_id' => (string) $this->pointOpen]), null), 'pickup_point_id');
    }

    public function testSavedAddressMustBelongToCustomer(): void
    {
        $owner = $this->db->insert('users', ['email' => 'o@x.co', 'password_hash' => 'x', 'first_name' => 'O', 'last_name' => 'W']);
        $other = $this->db->insert('users', ['email' => 'p@x.co', 'password_hash' => 'x', 'first_name' => 'P', 'last_name' => 'W']);
        $addr = (new AccountService($this->db))->saveAddress($owner, null, ['recipient_name' => 'O', 'phone' => '0712345678', 'county_id' => '47', 'town' => 'Karen', 'location' => 'Karen Rd']);
        $otherUser = $this->db->one('SELECT * FROM users WHERE id = ?', [$other]);
        $this->expectField(fn () => (new CheckoutService($this->db))->prepare($this->cartWith('100'), $this->input(['address_id' => (string) $addr, 'customer_email' => 'p@x.co']), $otherUser), 'address_id');

        $ownerUser = $this->db->one('SELECT * FROM users WHERE id = ?', [$owner]);
        $draft = (new CheckoutService($this->db))->prepare($this->cartWith('100'), $this->input(['address_id' => (string) $addr, 'customer_email' => 'o@x.co']), $ownerUser);
        $this->assertSame('Karen Rd', $draft['fulfilment']['location']);
    }

    public function testGuestEmailRequiredWhenConfigured(): void
    {
        Settings::set('guest_email_required', '1');
        $this->expectField(fn () => (new CheckoutService($this->db))->prepare($this->cartWith('100'), $this->input(), null), 'customer_email');
    }

    public function testCartChangesBlockCheckout(): void
    {
        $cart = $this->cartWith('100', 3);
        $this->db->exec('UPDATE inventory SET reserved = 19');
        $this->expectField(fn () => (new CheckoutService($this->db))->prepare($cart, $this->input(), null), '_cart');
    }
}
