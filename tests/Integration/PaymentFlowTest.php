<?php

declare(strict_types=1);

namespace Waimart\Tests\Integration;

use Waimart\Core\Settings;
use Waimart\Core\ValidationException;
use Waimart\Services\CodService;
use Waimart\Services\ExpiryService;
use Waimart\Services\OrderWorkflow;
use Waimart\Services\PaymentOptions;
use Waimart\Services\PaymentService;
use Waimart\Services\ResolutionService;
use Waimart\Services\SettingsService;

/** Manual M-Pesa + COD payments (A4, A6, C5, F, H) and inventory scenario 6 (C9). */
final class PaymentFlowTest extends DbTestCase
{
    use PlacesOrders;

    private PaymentService $pay;
    private int $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFulfilment();
        $this->pay = new PaymentService($this->db);
        $this->staff = $this->staffUser();
    }

    protected function tearDown(): void
    {
        $this->tearDownFulfilment();
        Settings::set('max_payment_submissions', '3');
        parent::tearDown();
    }

    private function code(): string
    {
        return 'S' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 9));
    }

    private function submit(array $o, ?string $code = null, ?string $amount = null): array
    {
        return $this->pay->submit((int) $o['id'], ['transaction_code' => $code ?? $this->code(), 'payer_phone' => '0712345678',
            'amount' => $amount ?? (string) $o['grand_total']], '10.0.0.' . random_int(1, 200));
    }

    private function order(int $id): array
    {
        return (array) $this->db->one('SELECT * FROM orders WHERE id = ?', [$id]);
    }

    public function testSubmissionNeverConfirmsOrExtendsTheReservation(): void
    {
        $v = $this->makeVariant(5)['variant_id'];
        $o = $this->placeOrder([[$v, 1]], 'mpesa_till');
        $expiresBefore = $this->db->value('SELECT expires_at FROM stock_reservations WHERE order_id = ?', [$o['id']]);
        $s = $this->submit($o);

        $after = $this->order((int) $o['id']);
        $this->assertSame('awaiting_verification', $after['status']);
        $this->assertSame('awaiting_verification', $after['payment_status']);
        $this->assertSame('pending', $s['status']);
        $this->assertSame($expiresBefore, $this->db->value('SELECT expires_at FROM stock_reservations WHERE order_id = ?', [$o['id']]), 'C5: no extension');
        $msg = (string) $this->db->value("SELECT body FROM notifications WHERE order_id = ? AND template = 'payment_submitted' AND channel = 'sms'", [$o['id']]);
        $this->assertStringContainsString('does not yet confirm your payment', $msg);
        $this->assertStringNotContainsString('confirmed', strtolower(str_replace('does not yet confirm', '', $msg)));
    }

    public function testCodesAreSingleUseAndSubmissionsLimited(): void
    {
        $v = $this->makeVariant(9)['variant_id'];
        $a = $this->placeOrder([[$v, 1]], 'mpesa_till');
        $b = $this->placeOrder([[$v, 1]], 'mpesa_till');
        $code = $this->code();
        $this->submit($a, $code);
        try {
            $this->submit($b, strtolower($code));
            $this->fail('A code must not be reusable across orders');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('already been submitted', $e->errors['transaction_code']);
        }
        // One pending submission at a time.
        $this->expectException(ValidationException::class);
        $this->submit($a);
    }

    public function testMaxSubmissionsPerOrder(): void
    {
        Settings::set('max_payment_submissions', '2');
        $v = $this->makeVariant(9)['variant_id'];
        $o = $this->placeOrder([[$v, 1]], 'mpesa_till');
        for ($i = 0; $i < 2; $i++) {
            $s = $this->submit($o);
            $this->pay->reject((int) $s['id'], $this->staff, 'not_found', null);
        }
        try {
            $this->submit($o);
            $this->fail('limit');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('limit of 2', $e->getMessage());
        }
    }

    public function testVerifyConfirmsAndHoldsStockAndIsSingleDecision(): void
    {
        Settings::set('mpesa_paybill_number', '400200');        // Paybill must be configured to be offered
        $v = $this->makeVariant(5)['variant_id'];
        $o = $this->placeOrder([[$v, 2]], 'mpesa_paybill');
        Settings::set('mpesa_paybill_number', '');
        $s = $this->submit($o);
        $after = $this->pay->verify((int) $s['id'], $this->staff, (string) $o['grand_total'], 'Statement line 42');
        $this->assertSame(['confirmed', 'verified'], [$after['status'], $after['payment_status']]);
        $this->assertNull($this->db->value('SELECT expires_at FROM stock_reservations WHERE order_id = ?', [$o['id']]), 'held until fulfilment');
        $this->assertSame(2, $this->levels($v)['reserved']);
        $ver = $this->db->one('SELECT * FROM payment_verifications WHERE submission_id = ?', [$s['id']]);
        $this->assertSame(['verified', $o['grand_total'], $this->staff], [$ver['decision'], $ver['amount_confirmed'], (int) $ver['actor_user_id']]);
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'payment.verified' AND entity_id = ?", [$s['id']]));

        foreach ([fn () => $this->pay->verify((int) $s['id'], $this->staff, (string) $o['grand_total'], null),
                  fn () => $this->pay->reject((int) $s['id'], $this->staff, 'other', null)] as $second) {
            try {
                $second();
                $this->fail('second decision must be refused');
            } catch (ValidationException $e) {
                $this->assertStringContainsString('already been verified', $e->getMessage());
            }
        }
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM payment_verifications WHERE submission_id = ?', [$s['id']]));
    }

    public function testUnderpaymentCannotBeVerifiedAndOverpaymentRecordsRefund(): void
    {
        $v = $this->makeVariant(5, '1000.00')['variant_id'];
        $o = $this->placeOrder([[$v, 1]], 'mpesa_till');                 // 1,000 + 200 delivery
        $s = $this->submit($o, null, '1000');
        try {
            $this->pay->verify((int) $s['id'], $this->staff, '1000', null);
            $this->fail('underpayment must not verify');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount_received', $e->errors);
        }
        $this->assertSame('awaiting_verification', $this->order((int) $o['id'])['status']);
        $this->assertSame('pending', $this->db->value('SELECT status FROM payment_submissions WHERE id = ?', [$s['id']]));

        $this->pay->verify((int) $s['id'], $this->staff, '1300', 'Customer sent extra');
        $this->assertSame('100.00', $this->db->value("SELECT amount FROM refunds WHERE order_id = ? AND status = 'due'", [$o['id']]));
    }

    public function testRejectReturnsToAwaitingPaymentAndRecordsMoneyReceived(): void
    {
        $v = $this->makeVariant(5)['variant_id'];
        $o = $this->placeOrder([[$v, 1]], 'mpesa_till');
        $s = $this->submit($o);
        $after = $this->pay->reject((int) $s['id'], $this->staff, 'not_found', 'Not on statement');
        $this->assertSame(['awaiting_payment', 'rejected'], [$after['status'], $after['payment_status']]);
        $this->assertTrue(PaymentService::canSubmit($after), 'customer can try again');
        $this->assertStringContainsString('could not verify', (string) $this->db->value("SELECT body FROM notifications WHERE order_id = ? AND template = 'payment_rejected' AND channel = 'sms'", [$o['id']]));

        $s2 = $this->submit($after);
        $after2 = $this->pay->reject((int) $s2['id'], $this->staff, 'wrong_amount', null, '500');
        $this->assertSame('refund_due', $after2['payment_status']);
        $this->assertSame('500.00', $this->db->value("SELECT amount FROM refunds WHERE order_id = ?", [$o['id']]));
    }

    /** C9 #6: an administrator verifies a payment after the reservation expired. */
    public function testScenario6LatePaymentGoesToResolutionThenReReserve(): void
    {
        $v = $this->makeVariant(3)['variant_id'];
        $o = $this->placeOrder([[$v, 2]], 'mpesa_till');
        $s = $this->submit($o);
        $this->db->exec('UPDATE stock_reservations SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE order_id = ?', [$o['id']]);
        (new ExpiryService($this->db))->run();
        $this->assertSame('expired', $this->order((int) $o['id'])['status']);
        $this->assertSame(0, $this->levels($v)['reserved']);

        $after = $this->pay->verify((int) $s['id'], $this->staff, (string) $o['grand_total'], null);
        $this->assertSame('requires_resolution', $after['status'], 'never silently confirmed');
        $this->assertSame('verified', $after['payment_status']);
        $this->assertSame(1, (int) $after['requires_resolution']);
        $this->assertSame(0, $this->levels($v)['reserved'], 'no stock promised');
        $this->assertSame('payment_after_expiry', $this->db->value("SELECT reason FROM order_resolutions WHERE order_id = ? AND status = 'open'", [$o['id']]));
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM notifications WHERE order_id = ? AND template = 'order_requires_resolution' AND channel = 'sms'", [$o['id']]));

        // Stock is still available → staff re-reserve and confirm.
        $res = (new ResolutionService($this->db))->reReserve((int) $o['id'], $this->staff, 'Stock still on shelf');
        $this->assertSame(['confirmed', 0], [$res['status'], (int) $res['requires_resolution']]);
        $this->assertSame(['on_hand' => 3, 'reserved' => 2], $this->levels($v));
        $this->assertNull($this->db->value("SELECT expires_at FROM stock_reservations WHERE order_id = ? AND status = 'active'", [$o['id']]));
        $this->assertSame(['resolved', 're_reserved'], array_values($this->db->one('SELECT status, outcome FROM order_resolutions WHERE order_id = ?', [$o['id']])));
        $this->assertInventoryConsistent();
    }

    public function testScenario6LatePaymentWhenStockIsGoneIsCancelledWithRefund(): void
    {
        $v = $this->makeVariant(1)['variant_id'];
        $o = $this->placeOrder([[$v, 1]], 'mpesa_till');
        $s = $this->submit($o);
        $this->db->exec('UPDATE stock_reservations SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE order_id = ?', [$o['id']]);
        (new ExpiryService($this->db))->run();
        $this->placeOrder([[$v, 1]], 'cod');                               // someone else buys the released unit
        $this->pay->verify((int) $s['id'], $this->staff, (string) $o['grand_total'], null);

        $svc = new ResolutionService($this->db);
        try {
            $svc->reReserve((int) $o['id'], $this->staff, null);
            $this->fail('cannot re-reserve without stock');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('not enough stock', $e->getMessage());
        }
        $this->assertSame('requires_resolution', $this->order((int) $o['id'])['status']);

        $done = $svc->cancelWithRefund((int) $o['id'], $this->staff, 'Item sold out before payment was verified');
        $this->assertSame(['cancelled', 'refund_due'], [$done['status'], $done['payment_status']]);
        $this->assertSame($o['grand_total'], $this->db->value("SELECT amount FROM refunds WHERE order_id = ? AND status = 'due'", [$o['id']]));
        $this->assertSame('cancelled_refund_due', $this->db->value('SELECT outcome FROM order_resolutions WHERE order_id = ?', [$o['id']]));
        $this->assertSame(1, $this->levels($v)['reserved'], 'the other customer keeps their unit');
        $this->assertInventoryConsistent();
    }

    public function testCodCashRecordedSeparatelyOnceAndExplained(): void
    {
        $v = $this->makeVariant(5)['variant_id'];
        $o = $this->placeOrder([[$v, 1]], 'cod');
        $cod = new CodService($this->db);
        try {
            $cod->record((int) $o['id'], (string) $o['grand_total'], $this->staff, null);
            $this->fail('not before hand-over');
        } catch (ValidationException) {
        }
        $wf = new OrderWorkflow($this->db);
        foreach (['confirmed', 'processing', 'packed', 'dispatched', 'delivered'] as $to) {
            $wf->transition((int) $o['id'], $to, ['type' => 'staff', 'id' => $this->staff]);
        }
        $this->assertSame('cod_pending', $this->order((int) $o['id'])['payment_status'], 'delivered ≠ paid');
        try {
            $cod->record((int) $o['id'], '100', $this->staff, null);
            $this->fail('mismatch needs a note');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('note', $e->errors);
        }
        $after = $cod->record((int) $o['id'], (string) $o['grand_total'], $this->staff, null);
        $this->assertSame(['delivered', 'cod_collected'], [$after['status'], $after['payment_status']]);
        $this->expectException(ValidationException::class);
        $cod->record((int) $o['id'], (string) $o['grand_total'], $this->staff, null);
    }

    public function testSettingsValidationAuditAndPaymentOptions(): void
    {
        // This test saves the full settings form; put every setting back afterwards so later tests see the shipped values.
        $snapshot = array_column($this->db->all('SELECT setting_key, setting_value FROM settings'), 'setting_value', 'setting_key');
        try {
            $this->settingsFormScenario();
        } finally {
            foreach ($snapshot as $k => $v) {
                Settings::set($k, (string) $v);
            }
        }
    }

    private function settingsFormScenario(): void
    {
        Settings::set('mpesa_till_number', '');
        $svc = new SettingsService();
        $valid = ['store_name' => (string) Settings::get('store_name'), 'max_payment_submissions' => '3', 'reservation_minutes' => '45', 'reservation_minutes_cod' => '240',
                  'collection_hold_days' => '7', 'collection_reminder_hours' => '24', 'collection_auto_return' => '1', 'return_window_days' => '7', 'products_per_page' => '24', 'allow_registration' => '1',
                  'product_moderation' => '1', 'payout_min_amount' => '500', 'payout_hold_days' => '7', 'payout_requires_fulfilment' => '1',
                  'tracking_consent_mode' => 'opt_in', 'tracking_retention_days' => '180', 'guest_history_days' => '30', 'recs_weight_view' => '1', 'recs_weight_search' => '2',
                  'recs_weight_cart' => '3', 'recs_weight_wishlist' => '4', 'recs_weight_purchase' => '5', 'recs_half_life_days' => '14', 'marketing_max_per_day' => '1',
                  'marketing_max_per_week' => '3', 'marketing_quiet_start' => '20:00', 'marketing_quiet_end' => '08:00', 'segment_new_days' => '30', 'segment_inactive_days' => '60',
                  'segment_high_value' => '20000', 'segment_frequent_views' => '10', 'attribution_window_days' => '30', 'price_drop_min_percent' => '5', 'cart_abandon_minutes' => '60', 'cart_reminder_link_days' => '7', 'marketing_max_attempts' => '3',
                  'delivery_home_enabled' => '1', 'delivery_pickup_enabled' => '1', 'smtp_port' => '587', 'smtp_encryption' => 'tls'];
        try {
            $svc->save(['mpesa_till_number' => '12ab'] + $valid, $this->staff);
            $this->fail('invalid till');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('mpesa_till_number', $e->errors);
        }
        $this->assertArrayNotHasKey('mpesa_till', PaymentOptions::forFulfilment(true));

        $svc->save(['mpesa_till_number' => '5123456', 'mpesa_phone_number' => '0712 345 678'] + $valid, $this->staff);
        Settings::flush();
        $this->assertSame('5123456', Settings::get('mpesa_till_number'));
        $this->assertSame('254712345678', Settings::get('mpesa_phone_number'));
        $this->assertSame(45, Settings::reservationMinutes('mpesa_till'));
        $this->assertArrayHasKey('mpesa_till', PaymentOptions::forFulfilment(true));
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'settings.payment_destination_changed'"));
        Settings::set('reservation_minutes', '30');
        Settings::set('mpesa_phone_number', '');
    }
}
