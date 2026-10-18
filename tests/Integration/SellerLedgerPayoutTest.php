<?php

declare(strict_types=1);

namespace Waimart\Tests\Integration;

use Waimart\Core\Crypto;
use Waimart\Core\HttpException;
use Waimart\Core\Money;
use Waimart\Core\Settings;
use Waimart\Core\ValidationException;
use Waimart\Services\CodService;
use Waimart\Services\CollectionService;
use Waimart\Services\CommissionService;
use Waimart\Services\OrderWorkflow;
use Waimart\Services\PayoutService;
use Waimart\Services\SellerLedgerService;
use Waimart\Services\SellerOrderWorkflow;

/** Phase 8: ledger accrual/reversal/release, payouts end to end, commission rule history, append-only enforcement. */
final class SellerLedgerPayoutTest extends DbTestCase
{
    use PlacesOrders;

    private int $seller;
    private int $owner;
    private int $staff;
    private SellerLedgerService $ledger;
    private PayoutService $payouts;
    /** @var array<string,?string> */
    private array $savedSettings = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFulfilment();
        foreach (['payout_hold_days', 'payout_requires_fulfilment', 'payout_min_amount', 'marketplace_commission_provisional'] as $k) {
            $this->savedSettings[$k] = Settings::get($k);
        }
        Settings::set('payout_hold_days', '0');
        Settings::set('payout_requires_fulfilment', '1');
        Settings::set('payout_min_amount', '500');
        $this->seller = $this->db->insert('sellers', ['name' => 'Kamba Crafts', 'slug' => 'kamba-crafts']);
        $this->owner = $this->db->insert('users', ['email' => 'kamba@example.com', 'password_hash' => 'x', 'first_name' => 'K', 'last_name' => 'C']);
        $this->db->insert('seller_users', ['seller_id' => $this->seller, 'user_id' => $this->owner, 'role' => 'owner']);
        $this->addPayoutMethod($this->seller);
        $this->staff = $this->staffUser();
        $this->ledger = new SellerLedgerService($this->db);
        $this->payouts = new PayoutService($this->db);
    }

    protected function tearDown(): void
    {
        foreach ($this->savedSettings as $k => $v) {
            Settings::set($k, (string) $v);
        }
        $this->tearDownFulfilment();
        parent::tearDown();
    }

    private function addPayoutMethod(int $sellerId): void
    {
        $this->db->insert('seller_payout_methods', ['seller_id' => $sellerId, 'method' => 'mpesa',
            'details_enc' => Crypto::encrypt(json_encode(['phone' => '254712345678', 'name' => 'Kamba Crafts'])), 'masked' => 'M-Pesa ••• 678']);
    }

    /** A confirmed order with one marketplace item (2,000) and one Official Store item; returns [order id, market part id, official part id]. */
    private function order(string $payment = 'mpesa_till', string $method = 'delivery', string $price = '2000.00'): array
    {
        $mv = $this->makeVariant(5, $price, null, 'Default', $this->seller)['variant_id'];
        $ov = $this->makeVariant(5, '1000.00')['variant_id'];
        $o = $this->placeOrder([[$mv, 1], [$ov, 1]], $payment, $method);
        if ($payment !== 'cod') {
            $this->db->exec("UPDATE orders SET payment_status = 'verified' WHERE id = ?", [$o['id']]);
        }
        (new OrderWorkflow($this->db))->transition((int) $o['id'], 'confirmed', ['type' => 'staff', 'id' => $this->staff], 'Confirmed');
        $m = (int) $this->db->value('SELECT id FROM seller_orders WHERE order_id = ? AND seller_id = ?', [$o['id'], $this->seller]);
        $p = (int) $this->db->value('SELECT id FROM seller_orders WHERE order_id = ? AND seller_id <> ?', [$o['id'], $this->seller]);
        return [(int) $o['id'], $m, $p];
    }

    private function deliver(int $so): void
    {
        $wf = new SellerOrderWorkflow($this->db);
        $seller = ['type' => 'seller', 'id' => $this->owner, 'seller_id' => $this->seller];
        $staff = ['type' => 'staff', 'id' => $this->staff];
        foreach ([['accepted', $seller], ['processing', $seller], ['packed', $seller], ['handed_over', $staff], ['dispatched', $staff], ['delivered', $staff]] as [$to, $actor]) {
            $wf->transition($so, $to, $actor);
        }
    }

    /** Earnings that are available now: confirmed, paid, delivered, released. */
    private function availableEarnings(string $price = '2000.00'): int
    {
        [, $m] = $this->order('mpesa_till', 'delivery', $price);
        $this->deliver($m);
        $this->ledger->releaseEligible();
        return $m;
    }

    private function assertConserved(): void
    {
        // Moves between buckets net to zero, so the sum of all buckets is always the seller's net earnings.
        $sum = Money::normalize((string) $this->db->value('SELECT COALESCE(SUM(amount), 0) FROM seller_ledger_entries WHERE seller_id = ?', [$this->seller]));
        $this->assertSame($this->ledger->summary($this->seller)['net_earnings'], $sum, 'ledger total = net earnings');
        $b = $this->ledger->balances($this->seller);
        $this->assertSame($sum, Money::add($b['pending'], $b['available'], $b['reserved'], $b['paid']));
        foreach ($b as $bucket => $v) {
            $this->assertGreaterThanOrEqual(0, Money::cmp($v, '0'), "$bucket balance never negative");
        }
    }

    public function testPaidOrderAccruesPendingEarningsExactlyOnce(): void
    {
        [$oid, $m] = $this->order();
        $this->assertSame(['pending' => '1800.00', 'available' => '0.00', 'reserved' => '0.00', 'paid' => '0.00'], $this->ledger->balances($this->seller), '2,000 less 10% commission');
        $this->assertSame('pending', $this->db->value('SELECT earnings_status FROM seller_orders WHERE id = ?', [$m]));
        $this->assertSame(0, $this->ledger->accrueOrder($oid), 'second accrual finds nothing to do');
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM seller_ledger_entries se JOIN sellers s ON s.id = se.seller_id WHERE s.is_platform = 1'), 'the Official Store is not in the seller ledger');
        // Forcing a duplicate posting (e.g. a retry racing the first) is a no-op.
        $this->assertFalse($this->ledger->post($this->seller, 'sale', 'pending', '2000.00', "sale:so:$m"));
        $this->assertSame(['gross_sales' => '2000.00', 'commission' => '200.00', 'reversed' => '0.00', 'net_earnings' => '1800.00'], $this->ledger->summary($this->seller));
        $this->assertConserved();
    }

    public function testUnpaidCodAccruesOnlyWhenTheCashIsRecorded(): void
    {
        [$oid, $m, $p] = $this->order('cod', 'pickup');
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM seller_ledger_entries'), 'no money received yet');
        $wf = new SellerOrderWorkflow($this->db);
        $seller = ['type' => 'seller', 'id' => $this->owner, 'seller_id' => $this->seller];
        $staff = ['type' => 'staff', 'id' => $this->staff];
        foreach (['accepted', 'processing', 'packed'] as $to) {
            $wf->transition($m, $to, $seller);
        }
        foreach (['handed_over', 'ready_for_collection'] as $to) {
            $wf->transition($m, $to, $staff);
        }
        foreach (['processing', 'packed', 'ready_for_collection'] as $to) {
            $wf->transition($p, $to, $staff);
        }
        $order = (array) $this->db->one('SELECT * FROM orders WHERE id = ?', [$oid]);
        (new CollectionService($this->db))->verifyAndCollect($oid, (string) CollectionService::codeFor($order + ['status' => 'ready_for_collection']), $this->staff, true);
        (new CodService($this->db))->record($oid, (string) $order['grand_total'], $this->staff, null);
        $this->assertSame('1800.00', $this->ledger->balances($this->seller)['pending']);
        $this->ledger->releaseEligible();
        $this->assertSame('1800.00', $this->ledger->balances($this->seller)['available'], 'collected + hold 0 → payable');
        $this->assertConserved();
    }

    public function testWithdrawnPartReversesItsEarnings(): void
    {
        [, $m] = $this->order();
        (new SellerOrderWorkflow($this->db))->transition($m, 'rejected', ['type' => 'seller', 'id' => $this->owner, 'seller_id' => $this->seller], ['reason' => 'Damaged in storage']);
        $this->assertSame('0.00', $this->ledger->balances($this->seller)['pending']);
        $this->assertSame('reversed', $this->db->value('SELECT earnings_status FROM seller_orders WHERE id = ?', [$m]));
        $types = array_column($this->db->all('SELECT entry_type FROM seller_ledger_entries ORDER BY id'), 'entry_type');
        $this->assertSame(['sale', 'commission', 'cancellation', 'commission_reversal'], $types, 'reversed by new entries, originals kept');
        $this->ledger->reversePart($m, 'again');
        $this->assertSame(4, (int) $this->db->value('SELECT COUNT(*) FROM seller_ledger_entries'), 'reversal is idempotent');
        $this->assertSame('0.00', $this->ledger->summary($this->seller)['net_earnings']);
        $this->assertConserved();
    }

    public function testReleaseFollowsTheHoldingPolicy(): void
    {
        [$oid, $m] = $this->order();
        $this->assertSame(0, $this->ledger->releaseEligible(), 'not delivered yet');
        Settings::set('payout_hold_days', '7');
        $this->deliver($m);
        $this->assertSame(0, $this->ledger->releaseEligible(), 'within the holding period');
        $this->db->exec('UPDATE seller_orders SET delivered_at = NOW() - INTERVAL 8 DAY WHERE id = ?', [$m]);

        $this->db->insert('return_requests', ['return_number' => 'RT-TEST1', 'order_id' => $oid, 'reason' => 'Faulty', 'seller_order_id' => $m]);
        $this->assertSame(0, $this->ledger->releaseEligible(), 'an open return holds the earnings');
        $this->db->exec("UPDATE return_requests SET status = 'rejected'");

        $this->db->insert('seller_disputes', ['dispute_number' => 'DS-TEST1', 'order_id' => $oid, 'seller_order_id' => $m, 'raised_by_role' => 'customer', 'reason' => 'Not as described']);
        $this->assertSame(0, $this->ledger->releaseEligible(), 'an open dispute holds the earnings');
        $this->db->exec("UPDATE seller_disputes SET status = 'resolved'");

        $this->assertSame(1, $this->ledger->releaseEligible());
        $this->assertSame(0, $this->ledger->releaseEligible(), 'released once');
        $this->assertSame(['pending' => '0.00', 'available' => '1800.00'], array_intersect_key($this->ledger->balances($this->seller), ['pending' => 1, 'available' => 1]));
        $this->assertSame('available', $this->db->value('SELECT earnings_status FROM seller_orders WHERE id = ?', [$m]));
        $this->assertConserved();
    }

    public function testPayoutLifecycleMovesMoneyExactly(): void
    {
        $this->availableEarnings();
        $deny = function (callable $fn, string $field) {
            try {
                $fn();
                $this->fail("expected a validation error on $field");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors);
            }
        };
        $deny(fn () => $this->payouts->request($this->seller, $this->owner, '499.99'), 'amount');
        $deny(fn () => $this->payouts->request($this->seller, $this->owner, '1800.01'), 'amount');
        $deny(fn () => $this->payouts->request($this->seller, $this->owner, '-5'), 'amount');

        $p = $this->payouts->request($this->seller, $this->owner, '1000');
        $this->assertSame(['available' => '800.00', 'reserved' => '1000.00'], array_intersect_key($this->ledger->balances($this->seller), ['available' => 1, 'reserved' => 1]));
        $deny(fn () => $this->payouts->request($this->seller, $this->owner, '500'), '_form');

        // Reject → returns to available; a new request may then use it.
        $deny(fn () => $this->payouts->reject((int) $p['id'], $this->staff, 'no'), 'reason');
        $this->payouts->reject((int) $p['id'], $this->staff, 'Payout details need checking');
        $this->assertSame('1800.00', $this->ledger->balances($this->seller)['available']);
        $deny(fn () => $this->payouts->approve((int) $p['id'], $this->staff), '_form');

        $p = $this->payouts->request($this->seller, $this->owner, '1800.00');
        $deny(fn () => $this->payouts->markPaid((int) $p['id'], $this->staff, 'mpesa', 'QWE123RTY', '1800'), '_form');   // not approved yet
        $this->payouts->approve((int) $p['id'], $this->staff, 'ok');
        $deny(fn () => $this->payouts->markPaid((int) $p['id'], $this->staff, 'mpesa', 'QWE123RTY', '1799.99'), 'paid_amount');
        $deny(fn () => $this->payouts->markPaid((int) $p['id'], $this->staff, 'mpesa', '', '1800'), 'paid_reference');
        $deny(fn () => $this->payouts->markPaid((int) $p['id'], $this->staff, 'cheque', 'QWE123RTY', '1800'), 'paid_method');
        $this->payouts->markPaid((int) $p['id'], $this->staff, 'mpesa', 'qwe123rty', '1800.00');
        $this->assertSame(['pending' => '0.00', 'available' => '0.00', 'reserved' => '0.00', 'paid' => '1800.00'], $this->ledger->balances($this->seller));
        $row = $this->payouts->find((int) $p['id']);
        $this->assertSame(['paid', 'QWE123RTY', '1800.00'], [$row['status'], $row['paid_reference'], $row['paid_amount']]);
        $deny(fn () => $this->payouts->markPaid((int) $p['id'], $this->staff, 'mpesa', 'QWE123RTY', '1800'), '_form');   // never paid twice
        $this->assertSame(['requested', 'approved', 'paid'], array_column($this->payouts->history((int) $p['id']), 'to_status'));
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM notifications WHERE template = 'payout_paid'"), 'owner told once');
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'payout.paid'"));

        // A payment reference cannot be reused for another payout.
        $this->availableEarnings();
        $p2 = $this->payouts->request($this->seller, $this->owner, '1800');
        $this->payouts->approve((int) $p2['id'], $this->staff);
        $deny(fn () => $this->payouts->markPaid((int) $p2['id'], $this->staff, 'mpesa', 'QWE123RTY', '1800'), 'paid_reference');
        $this->assertConserved();
    }

    public function testSellerCancelIsolationAndPreconditions(): void
    {
        $this->availableEarnings();
        $p = $this->payouts->request($this->seller, $this->owner, '600');
        $other = $this->db->insert('sellers', ['name' => 'Other Shop', 'slug' => 'other-shop']);
        try {
            $this->payouts->cancel($other, $this->owner, (int) $p['id']);
            $this->fail('another seller cannot cancel it');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->status);
        }
        $this->payouts->cancel($this->seller, $this->owner, (int) $p['id']);
        $this->assertSame('1800.00', $this->ledger->balances($this->seller)['available']);
        try {
            $this->payouts->cancel($this->seller, $this->owner, (int) $p['id']);
            $this->fail('cannot cancel twice');
        } catch (ValidationException) {
        }
        $this->assertSame(2, (int) $this->db->value("SELECT COUNT(*) FROM seller_ledger_entries WHERE entry_type = 'payout_return'"), 'returned once');

        $this->db->exec("UPDATE sellers SET status = 'suspended' WHERE id = ?", [$this->seller]);
        try {
            $this->payouts->request($this->seller, $this->owner, '600');
            $this->fail('suspended stores cannot request payouts');
        } catch (ValidationException) {
        }
        $this->db->exec("UPDATE sellers SET status = 'active' WHERE id = ?", [$this->seller]);
        $this->db->exec('UPDATE seller_payout_methods SET is_active = 0, deactivated_at = NOW() WHERE seller_id = ?', [$this->seller]);
        try {
            $this->payouts->request($this->seller, $this->owner, '600');
            $this->fail('payout details are required');
        } catch (ValidationException) {
        }
        $this->assertConserved();
    }

    public function testDestinationIsDecryptedOnlyOnRequestAndAudited(): void
    {
        $this->availableEarnings();
        $p = $this->payouts->request($this->seller, $this->owner, '1000');
        $this->assertSame('M-Pesa ••• 678', $p['destination_masked']);
        $this->assertStringNotContainsString('254712345678', json_encode($this->payouts->find((int) $p['id']), JSON_UNESCAPED_UNICODE));
        $this->assertSame('254712345678', $this->payouts->destinationFor((int) $p['id'], $this->staff)['phone']);
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'payout.destination_viewed'"));
    }

    public function testLedgerAndPayoutHistoryAreAppendOnlyInTheDatabase(): void
    {
        $this->availableEarnings();
        $p = $this->payouts->request($this->seller, $this->owner, '1000');
        $this->assertDbRejects(fn () => $this->db->exec('UPDATE seller_ledger_entries SET amount = 99999 WHERE entry_type = \'sale\''), 'append-only');
        $this->assertDbRejects(fn () => $this->db->exec('DELETE FROM seller_ledger_entries'), 'append-only');
        $this->assertDbRejects(fn () => $this->db->exec('UPDATE seller_payout_history SET note = \'x\''), 'append-only');
        $this->assertDbRejects(fn () => $this->db->exec('DELETE FROM seller_payout_history WHERE payout_id = ?', [$p['id']]), 'append-only');
        $this->assertDbRejects(fn () => $this->db->exec("UPDATE seller_payouts SET status = 'paid' WHERE id = ?", [$p['id']]), 'ck_sp_paid');
        $this->assertSame('1000.00', $this->ledger->balances($this->seller)['reserved']);
    }

    public function testCommissionRuleChangesKeepHistoryAndNeverTouchPastOrders(): void
    {
        [, $m] = $this->order();
        $before = $this->db->all('SELECT commission_rate, commission_amount FROM order_items WHERE seller_order_id = ?', [$m]);
        $svc = new CommissionService($this->db);
        $old = (int) $this->db->value("SELECT id FROM commission_rules WHERE scope = 'default' AND is_active = 1");
        Settings::set('marketplace_commission_provisional', '1');
        $new = $svc->setRule('default', null, '12.5', 'Confirmed by board', $this->staff, true);
        $this->assertSame('0', Settings::get('marketplace_commission_provisional'));
        $this->assertSame(0, (int) $this->db->value('SELECT is_active FROM commission_rules WHERE id = ?', [$old]), 'old rule retired, not edited');
        $this->assertSame('10.00', $this->db->value('SELECT rate FROM commission_rules WHERE id = ?', [$old]));
        $this->assertSame($before, $this->db->all('SELECT commission_rate, commission_amount FROM order_items WHERE seller_order_id = ?', [$m]), 'past orders unchanged');
        $this->assertSame('12.50', $svc->rateFor($this->seller, (int) $this->db->value('SELECT id FROM categories LIMIT 1'))['rate']);

        $sr = $svc->setRule('seller', $this->seller, '5', null, $this->staff);
        $this->assertSame('5.00', $svc->rateFor($this->seller, 0)['rate']);
        $svc->retire($sr, $this->staff);
        $this->assertSame('12.50', $svc->rateFor($this->seller, 0)['rate']);
        try {
            $svc->retire($new, $this->staff);
            $this->fail('the default cannot be removed');
        } catch (ValidationException) {
        }
        $platformRule = (int) $this->db->value("SELECT id FROM commission_rules WHERE scope = 'seller' AND seller_id = 1 AND is_active = 1");
        try {
            $svc->retire($platformRule, $this->staff);
            $this->fail('the Official Store 0% rule is fixed');
        } catch (ValidationException) {
        }
        foreach ([['default', null, '101'], ['default', null, 'ten'], ['seller', 999999, '5'], ['bogus', null, '5'], ['seller', 1, '10']] as [$scope, $target, $rate]) {
            try {
                $svc->setRule($scope, $target, $rate, null, $this->staff);
                $this->fail("rejected: $scope/$rate");
            } catch (ValidationException) {
            }
        }
        $this->assertSame(3, (int) $this->db->value("SELECT COUNT(*) FROM audit_logs WHERE action IN ('commission.rule_set','commission.rule_retired')"));

        $report = $svc->report(date('Y-m-d 00:00:00'), date('Y-m-d 23:59:59'));
        $this->assertSame([['Kamba Crafts', '2000.00', '200.00']], array_map(fn ($r) => [$r['seller_name'], Money::normalize((string) $r['gross_sales']), Money::normalize((string) $r['commission'])], $report));
    }
}
