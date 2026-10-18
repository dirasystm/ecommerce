<?php

declare(strict_types=1);

namespace Waimart\Tests\Integration;

use Waimart\Core\Crypto;
use Waimart\Core\HttpException;
use Waimart\Core\Money;
use Waimart\Core\Settings;
use Waimart\Core\ValidationException;
use Waimart\Services\DisputeService;
use Waimart\Services\MarketplaceReportService;
use Waimart\Services\OrderWorkflow;
use Waimart\Services\PayoutService;
use Waimart\Services\RefundService;
use Waimart\Services\ReturnService;
use Waimart\Services\SellerLedgerService;
use Waimart\Services\SellerOrderWorkflow;

/**
 * Phase 9: returns per seller part, refunds and the seller ledger (including refunds after a payout),
 * disputes, notifications and the reconciliation report.
 */
final class MarketplaceAfterSalesTest extends DbTestCase
{
    use PlacesOrders;

    private int $a;
    private int $b;
    private int $ownerA;
    private int $ownerB;
    private int $staff;
    /** @var array<string,?string> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFulfilment();
        foreach (['payout_hold_days', 'payout_min_amount', 'return_window_days'] as $k) {
            $this->saved[$k] = Settings::get($k);
        }
        Settings::set('payout_hold_days', '0');
        Settings::set('payout_min_amount', '500');
        Settings::set('return_window_days', '7');
        [$this->a, $this->ownerA] = $this->seller('Kamba Crafts');
        [$this->b, $this->ownerB] = $this->seller('Pwani Textiles');
        $this->staff = $this->staffUser();
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            Settings::set($k, (string) $v);
        }
        $this->tearDownFulfilment();
        parent::tearDown();
    }

    /** @return array{0:int,1:int} seller id, owner user id */
    private function seller(string $name): array
    {
        $s = $this->db->insert('sellers', ['name' => $name, 'slug' => strtolower(str_replace(' ', '-', $name))]);
        $u = $this->db->insert('users', ['email' => "owner{$s}@example.com", 'password_hash' => 'x', 'first_name' => 'Owner', 'last_name' => (string) $s, 'phone' => '25471100' . str_pad((string) $s, 4, '0', STR_PAD_LEFT)]);
        $this->db->insert('seller_users', ['seller_id' => $s, 'user_id' => $u, 'role' => 'owner']);
        $this->db->insert('seller_payout_methods', ['seller_id' => $s, 'method' => 'mpesa', 'details_enc' => Crypto::encrypt('{"phone":"254700000000"}'), 'masked' => 'M-Pesa ••• 000']);
        return [$s, $u];
    }

    /**
     * A paid, confirmed order with one 2,000 item from seller A and one 1,000 item from seller B.
     * @return array{order:int,pa:int,pb:int,ia:int,ib:int}
     */
    private function order(): array
    {
        $va = $this->makeVariant(5, '2000.00', null, 'Default', $this->a)['variant_id'];
        $vb = $this->makeVariant(5, '1000.00', null, 'Default', $this->b)['variant_id'];
        $o = $this->placeOrder([[$va, 1], [$vb, 1]], 'mpesa_till');
        $this->db->exec("UPDATE orders SET payment_status = 'verified' WHERE id = ?", [$o['id']]);
        (new OrderWorkflow($this->db))->transition((int) $o['id'], 'confirmed', ['type' => 'staff', 'id' => $this->staff], 'Paid');
        $part = fn (int $s) => (int) $this->db->value('SELECT id FROM seller_orders WHERE order_id = ? AND seller_id = ?', [$o['id'], $s]);
        $item = fn (int $v) => (int) $this->db->value('SELECT id FROM order_items WHERE order_id = ? AND variant_id = ?', [$o['id'], $v]);
        return ['order' => (int) $o['id'], 'pa' => $part($this->a), 'pb' => $part($this->b), 'ia' => $item($va), 'ib' => $item($vb)];
    }

    private function deliver(int $so, int $sellerId, int $owner): void
    {
        $wf = new SellerOrderWorkflow($this->db);
        $s = ['type' => 'seller', 'id' => $owner, 'seller_id' => $sellerId];
        $t = ['type' => 'staff', 'id' => $this->staff];
        foreach ([['accepted', $s], ['processing', $s], ['packed', $s], ['handed_over', $t], ['dispatched', $t], ['delivered', $t]] as [$to, $actor]) {
            $wf->transition($so, $to, $actor);
        }
    }

    private function orderStatus(int $id): string
    {
        return (string) $this->db->value('SELECT status FROM orders WHERE id = ?', [$id]);
    }

    /** Takes a return through approve → receive → inspect (restock) → complete. */
    private function completeReturn(int $returnId, string $refund): void
    {
        $svc = new ReturnService($this->db);
        $svc->approve($returnId, $this->staff, null);
        $svc->receive($returnId, $this->staff);
        foreach ($this->db->all('SELECT id FROM return_items WHERE return_id = ?', [$returnId]) as $ri) {
            $svc->inspect($returnId, (int) $ri['id'], 'restock', null, $this->staff);
        }
        $svc->complete($returnId, $this->staff, $refund, Money::isZero($refund) ? 'No fault found' : null);
    }

    private function assertReconciled(): void
    {
        $bad = array_filter((new MarketplaceReportService($this->db))->reconcile(), fn ($c) => !$c['ok']);
        $this->assertSame([], array_values($bad), 'every reconciliation check matches');
    }

    private function bal(int $seller): array
    {
        return (new SellerLedgerService($this->db))->balances($seller);
    }

    // ------------------------------------------------------------------ returns per part

    public function testReturnsAreSplitPerSellerAndHandledIndependently(): void
    {
        $x = $this->order();
        $this->deliver($x['pa'], $this->a, $this->ownerA);
        $svc = new ReturnService($this->db);
        $order = fn () => (array) $this->db->one('SELECT * FROM orders WHERE id = ?', [$x['order']]);

        // Only the delivered part is returnable; the order is not "return requested" while B is still on its way.
        $this->assertSame([$x['ia']], array_map('intval', array_column($svc->returnableItems($order()), 'id')));
        $this->deliver($x['pb'], $this->b, $this->ownerB);
        $this->assertSame('delivered', $this->orderStatus($x['order']), 'both parts delivered: the order rolls up to delivered');

        $rets = $svc->request($x['order'], [$x['ia'] => 1, $x['ib'] => 1], 'damaged', 'Both broken', null);
        $this->assertCount(2, $rets, 'one return per seller part');
        $this->assertEqualsCanonicalizing([$x['pa'], $x['pb']], array_map('intval', array_column($rets, 'seller_order_id')));
        $this->assertSame('return_requested', $this->orderStatus($x['order']));
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM notifications WHERE template = 'seller_return_requested' AND channel = 'email' AND user_id = ?", [$this->ownerA]));
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM notifications WHERE template = 'seller_return_requested' AND channel = 'email' AND user_id = ?", [$this->ownerB]));
        [$ra, $rb] = $rets[0]['seller_order_id'] == $x['pa'] ? $rets : array_reverse($rets);

        // One open return per part, enforced by the database too.
        $this->assertSame([], $svc->returnableItems($order()));
        $this->assertDbRejects(fn () => $this->db->insert('return_requests', ['return_number' => 'RT-DUP00001', 'order_id' => $x['order'], 'seller_order_id' => $x['pa'], 'reason' => 'other']), 'uq_rr_open_so');

        $svc->reject((int) $rb['id'], $this->staff, 'Item used beyond inspection');
        $this->assertSame('return_requested', $this->orderStatus($x['order']), "A's return is still open");
        $this->completeReturn((int) $ra['id'], '2000');
        $this->assertSame('delivered', $this->orderStatus($x['order']), 'B was not returned, so the order is delivered again');
        $this->assertSame((int) $x['pa'], (int) $this->db->value('SELECT seller_order_id FROM refunds WHERE return_id = ?', [$ra['id']]));
        $this->assertSame('0.00', $this->bal($this->a)['pending'], "A's 1,800 pending reversed by the 2,000 refund (+200 commission back)");
        $this->assertSame('900.00', $this->bal($this->b)['pending'], 'B untouched');
        $this->assertReconciled();
    }

    public function testRefundIsCappedAtWhatWasPaidForThePart(): void
    {
        $x = $this->order();
        $this->deliver($x['pa'], $this->a, $this->ownerA);
        // A platform-funded discount of 200 on A's part: the customer paid 1,800 for a 2,000 item.
        $this->db->exec('UPDATE seller_orders SET discount_share = 200.00 WHERE id = ?', [$x['pa']]);
        [$r] = (new ReturnService($this->db))->request($x['order'], [$x['ia'] => 1], 'defective', null, null);
        $this->assertSame('1800.00', (new ReturnService($this->db))->maxRefund((int) $r['id']));
        try {
            $this->completeReturn((int) $r['id'], '2000');
            $this->fail('cannot refund more than was paid');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('refund_amount', $e->errors);
        }
        (new ReturnService($this->db))->complete((int) $r['id'], $this->staff, '1800', null);
        // The seller gives back the whole 2,000 sale (Waimart funded the discount), Waimart returns its 200 commission.
        $this->assertSame(['-2000.00', '200.00'], array_column($this->db->all("SELECT amount FROM seller_ledger_entries WHERE posting_key LIKE 'refund:%' ORDER BY id"), 'amount'));
        $this->assertSame('0.00', $this->bal($this->a)['pending']);
        $this->assertReconciled();
    }

    // ------------------------------------------------------------------ refunds and the ledger

    public function testRefundAfterPayoutLeavesTheSellerOwingAndBlocksPayoutsUntilRecovered(): void
    {
        $x = $this->order();
        $this->deliver($x['pa'], $this->a, $this->ownerA);
        (new SellerLedgerService($this->db))->releaseEligible();
        $p = (new PayoutService($this->db))->request($this->a, $this->ownerA, '1800');
        (new PayoutService($this->db))->approve((int) $p['id'], $this->staff);
        (new PayoutService($this->db))->markPaid((int) $p['id'], $this->staff, 'mpesa', 'PAYA1B2C3', '1800');

        // The customer returns A's item for a 1,000 refund (half of the 2,000 paid for it).
        [$r] = (new ReturnService($this->db))->request($x['order'], [$x['ia'] => 1], 'not_as_described', null, null);
        $this->completeReturn((int) $r['id'], '1000');

        $this->assertSame(['pending' => '0.00', 'available' => '-900.00', 'reserved' => '0.00', 'paid' => '1800.00'], $this->bal($this->a),
            'half the sale (1,000) is taken back and half the commission (100) returned: the seller owes 900');
        $liab = (new MarketplaceReportService($this->db))->liability();
        $this->assertSame(['900.00', 1], [$liab['owed_by_sellers'], $liab['sellers_owing']]);
        try {
            (new PayoutService($this->db))->request($this->a, $this->ownerA, '500');
            $this->fail('no payouts while the seller owes Waimart');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount', $e->errors);
        }

        // The next sale is used to recover it.
        $y = $this->order();
        $this->deliver($y['pa'], $this->a, $this->ownerA);
        (new SellerLedgerService($this->db))->releaseEligible();
        $this->assertSame('900.00', $this->bal($this->a)['available'], '1,800 new earnings − 900 owed');
        $this->assertReconciled();
    }

    public function testCancelledRefundGivesTheDeductionBack(): void
    {
        $x = $this->order();
        $this->deliver($x['pa'], $this->a, $this->ownerA);
        [$r] = (new ReturnService($this->db))->request($x['order'], [$x['ia'] => 1], 'damaged', null, null);
        $this->completeReturn((int) $r['id'], '1000');
        $this->assertSame('900.00', $this->bal($this->a)['pending'], '1,800 − (1,000 sale − 100 commission)');
        $refund = (int) $this->db->value('SELECT id FROM refunds WHERE return_id = ?', [$r['id']]);
        (new RefundService($this->db))->cancel($refund, $this->staff, 'Customer accepted a replacement instead');
        $this->assertSame('1800.00', $this->bal($this->a)['pending']);
        (new SellerLedgerService($this->db))->cancelRefund($refund, $this->staff);
        $this->assertSame('1800.00', $this->bal($this->a)['pending'], 'idempotent');
        $this->assertSame('1800.00', (new SellerLedgerService($this->db))->summary($this->a)['net_earnings']);
        $this->assertReconciled();
    }

    public function testRefundsOnOfficialStoreItemsNeverTouchTheSellerLedger(): void
    {
        $ov = $this->makeVariant(5, '1500.00')['variant_id'];
        $o = $this->placeOrder([[$ov, 1]], 'mpesa_till');
        $this->db->exec("UPDATE orders SET payment_status = 'verified' WHERE id = ?", [$o['id']]);
        $wf = new OrderWorkflow($this->db);
        foreach (['confirmed', 'processing', 'packed', 'dispatched', 'delivered'] as $to) {
            $wf->transition((int) $o['id'], $to, ['type' => 'staff', 'id' => $this->staff]);
        }
        [$r] = (new ReturnService($this->db))->request((int) $o['id'], [(int) $this->db->value('SELECT id FROM order_items WHERE order_id = ?', [$o['id']]) => 1], 'damaged', null, null);
        $this->assertNotNull($r['seller_order_id'], 'Official Store returns are per part too');
        $this->completeReturn((int) $r['id'], '1500');
        $this->assertSame('returned', $this->orderStatus((int) $o['id']));
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM seller_ledger_entries'));
    }

    // ------------------------------------------------------------------ disputes

    public function testDisputeLifecycleHoldsEarningsAndRefundsThroughTheLedger(): void
    {
        $x = $this->order();
        $this->deliver($x['pa'], $this->a, $this->ownerA);
        $svc = new DisputeService($this->db);
        $order = (array) $this->db->one('SELECT * FROM orders WHERE id = ?', [$x['order']]);
        $this->assertSame([$x['pa']], array_map('intval', array_column($svc->disputableParts($order), 'id')), "B's part is not accepted yet, so there is nothing to dispute");
        (new SellerOrderWorkflow($this->db))->transition($x['pb'], 'accepted', ['type' => 'seller', 'id' => $this->ownerB, 'seller_id' => $this->b]);
        $order = (array) $this->db->one('SELECT * FROM orders WHERE id = ?', [$x['order']]);
        $this->assertEqualsCanonicalizing([$x['pa'], $x['pb']], array_map('intval', array_column($svc->disputableParts($order), 'id')), 'in-progress parts can have cases too');

        try {
            $svc->open($x['pa'], 'customer', null, 'damaged', 'short');
            $this->fail('details required');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('details', $e->errors);
        }
        $d = $svc->open($x['pa'], 'customer', null, 'damaged', 'The carving arrived cracked in two places.');
        $this->assertSame(0, (new SellerLedgerService($this->db))->releaseEligible(), 'an open case holds the earnings');
        try {
            $svc->open($x['pa'], 'staff', $this->staff, 'other', 'A second case on the same part');
            $this->fail('one open case per part');
        } catch (ValidationException) {
        }

        // Isolation: seller B cannot load A's case.
        try {
            $svc->findForSeller($this->b, (int) $d['id']);
            $this->fail('another seller gets 404');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->status);
        }
        $this->assertSame('Test', $svc->findForSeller($this->a, (int) $d['id'])['customer_name'], 'seller sees the first name only');

        $svc->setStatus((int) $d['id'], $this->staff, 'awaiting_seller');
        $svc->message((int) $d['id'], 'staff', $this->staff, 'Checking with the courier first.', true);
        $svc->message((int) $d['id'], 'seller', $this->ownerA, 'It was packed in bubble wrap; photos attached to our records.');
        $this->assertSame('open', $svc->find((int) $d['id'])['status'], "the seller's reply puts it back with Waimart");
        $this->assertCount(1, $svc->messages((int) $d['id'], false), 'internal notes are never shown to the customer or the seller');
        $this->assertCount(2, $svc->messages((int) $d['id'], true));
        $this->assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM notifications WHERE body LIKE '%courier first%'"), 'internal notes are not sent');

        try {
            $svc->resolve((int) $d['id'], $this->staff, 'refund_partial', 'Half refund for the damage', '2000.01');
            $this->fail('capped at what was paid');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('refund_amount', $e->errors);
        }
        $svc->resolve((int) $d['id'], $this->staff, 'refund_partial', 'Half refund for the damage', '1000');
        $rf = (array) $this->db->one('SELECT * FROM refunds WHERE dispute_id = ?', [$d['id']]);
        $this->assertSame(['1000.00', (string) $x['pa'], 'due'], [$rf['amount'], (string) $rf['seller_order_id'], $rf['status']]);
        $this->assertSame('refund_due', $this->db->value('SELECT payment_status FROM orders WHERE id = ?', [$x['order']]));
        $this->assertSame('900.00', $this->bal($this->a)['pending'], '1,800 − (1,000 − 100)');
        $this->assertSame(1, (new SellerLedgerService($this->db))->releaseEligible(), 'resolved: earnings released');
        $this->assertSame('900.00', $this->bal($this->a)['available']);

        try {
            $svc->message((int) $d['id'], 'customer', null, 'Thanks');
            $this->fail('closed cases take no messages');
        } catch (ValidationException) {
        }
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'dispute.resolved'"));
        $this->assertReconciled();
    }

    public function testDisputeRulesAndWithdrawal(): void
    {
        $x = $this->order();
        $svc = new DisputeService($this->db);
        $platform = $this->makeVariant(5, '500.00')['variant_id'];
        $o = $this->placeOrder([[$platform, 1]], 'cod', 'pickup');
        $officialPart = (int) $this->db->value('SELECT id FROM seller_orders WHERE order_id = ?', [$o['id']]);
        try {
            $svc->open($officialPart, 'staff', $this->staff, 'damaged', 'Official Store items go to customer care.');
            $this->fail('Official Store parts are not seller disputes');
        } catch (ValidationException) {
        }

        // Unpaid (COD not collected): a refund resolution is refused, other resolutions are fine.
        $v = $this->makeVariant(5, '700.00', null, 'Default', $this->a)['variant_id'];
        $cod = $this->placeOrder([[$v, 1]], 'cod', 'pickup');
        (new OrderWorkflow($this->db))->transition((int) $cod['id'], 'confirmed', ['type' => 'staff', 'id' => $this->staff], 'COD');
        $codPart = (int) $this->db->value('SELECT id FROM seller_orders WHERE order_id = ?', [$cod['id']]);
        $d = $svc->open($codPart, 'staff', $this->staff, 'not_received', 'Customer says the seller never packed it.');
        try {
            $svc->resolve((int) $d['id'], $this->staff, 'refund_full', 'Refund the customer');
            $this->fail('nothing paid');
        } catch (ValidationException) {
        }
        $svc->resolve((int) $d['id'], $this->staff, 'no_action', 'Seller packed it the next day.');

        // Withdrawal by the customer who raised it, through their own order only.
        (new SellerOrderWorkflow($this->db))->transition($x['pb'], 'accepted', ['type' => 'seller', 'id' => $this->ownerB, 'seller_id' => $this->b]);
        $c = $svc->open($x['pb'], 'customer', null, 'not_received', 'Still waiting for my fabric order.');
        try {
            $svc->withdraw((int) $c['id'], (int) $cod['id']);
            $this->fail('not through another order');
        } catch (HttpException) {
        }
        $svc->withdraw((int) $c['id'], $x['order']);
        $this->assertSame('closed', $svc->find((int) $c['id'])['status']);
        $this->assertNotEmpty($svc->disputableParts((array) $this->db->one('SELECT * FROM orders WHERE id = ?', [$x['order']])), 'a new case can be opened later');

        // Outside the window after delivery, customers can no longer open one.
        $this->deliver($x['pa'], $this->a, $this->ownerA);
        $this->db->exec('UPDATE seller_orders SET delivered_at = NOW() - INTERVAL 8 DAY WHERE id = ?', [$x['pa']]);
        try {
            $svc->open($x['pa'], 'customer', null, 'damaged', 'Found a crack after a week and a day.');
            $this->fail('window passed');
        } catch (ValidationException) {
        }
    }

    // ------------------------------------------------------------------ notifications, reports

    public function testSellerNotificationsFollowPreferencesExceptImportantOnes(): void
    {
        $this->db->exec('UPDATE users SET notify_email = 0, notify_sms = 1 WHERE id = ?', [$this->ownerA]);
        $x = $this->order();
        (new \Waimart\Services\SellerOrderService($this->db))->notifyConfirmed($x['order']);   // as OrderNotifier does on confirmation (operational)
        $this->assertSame([['seller_order_confirmed', 'sms']], array_map(fn ($r) => [$r['template'], $r['channel']], $this->db->all(
            'SELECT template, channel FROM notifications WHERE user_id = ?', [$this->ownerA])), 'email off, SMS on: SMS only');
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM notifications WHERE template = 'seller_order_confirmed' AND channel = 'email' AND user_id = ?", [$this->ownerB]));

        $this->deliver($x['pa'], $this->a, $this->ownerA);
        (new SellerLedgerService($this->db))->releaseEligible();
        $p = (new PayoutService($this->db))->request($this->a, $this->ownerA, '1000');
        (new PayoutService($this->db))->reject((int) $p['id'], $this->staff, 'Details need checking');
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM notifications WHERE template = 'payout_rejected' AND channel = 'email' AND user_id = ?", [$this->ownerA]),
            'money messages are always emailed');
    }

    public function testReportsMatchTheLedgerAndReconciliationCatchesAStrayEntry(): void
    {
        $x = $this->order();
        $this->deliver($x['pa'], $this->a, $this->ownerA);
        $this->deliver($x['pb'], $this->b, $this->ownerB);
        [$r] = (new ReturnService($this->db))->request($x['order'], [$x['ib'] => 1], 'changed_mind', null, null);
        $this->completeReturn((int) $r['id'], '1000');
        (new SellerLedgerService($this->db))->releaseEligible();

        $rep = new MarketplaceReportService($this->db);
        [$from, $to] = [date('Y-m-d 00:00:00'), date('Y-m-d 23:59:59')];
        $rows = array_column($rep->sellerPerformance($from, $to), null, 'seller_id');
        foreach ([$this->a, $this->b] as $s) {
            $this->assertSame((new SellerLedgerService($this->db))->summary($s)['net_earnings'], $rows[$s]['net'], 'report net = ledger net');
            $total = Money::normalize((string) $this->db->value('SELECT SUM(amount) FROM seller_ledger_entries WHERE seller_id = ?', [$s]));
            $this->assertSame($total, $rows[$s]['net'], 'report net = sum of ledger rows');
        }
        $this->assertSame(['2000.00', '200.00', '1800.00'], [$rows[$this->a]['sales'], $rows[$this->a]['commission'], $rows[$this->a]['net']]);
        $this->assertSame(['1000.00', '0.00', '0.00'], [$rows[$this->b]['sales'], $rows[$this->b]['commission'], $rows[$this->b]['net']], 'commission returned with the full refund');
        $sr = $rep->sellerReport($this->b, $from, $to);
        $this->assertSame([1, 1, '0.00', 1], [$sr['parts'], $sr['completed'], $sr['net'], $sr['returns']]);
        $this->assertSame([], $sr['top'], 'the returned unit is not a sale');
        $this->assertReconciled();

        // A posting with no order behind it is detected.
        (new SellerLedgerService($this->db))->post($this->a, 'sale', 'pending', '50.00', 'stray:test');
        $failed = array_column(array_filter($rep->reconcile(), fn ($c) => !$c['ok']), 'check');
        $this->assertSame(['Sales recorded = value of paid marketplace parts'], array_values($failed));
    }
}
