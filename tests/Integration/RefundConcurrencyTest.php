<?php

declare(strict_types=1);

namespace Waimart\Tests\Integration;

use Waimart\Core\Money;
use Waimart\Services\DisputeService;
use Waimart\Services\MarketplaceReportService;
use Waimart\Services\OrderWorkflow;
use Waimart\Services\ReturnService;
use Waimart\Services\SellerOrderWorkflow;

/**
 * Phase 10: a return refund and a dispute refund decided at the same instant on the same seller part must never
 * refund more than the customer paid for it (real processes, separate connections; repeated rounds).
 */
final class RefundConcurrencyTest extends DbTestCase
{
    use PlacesOrders;

    private int $seller;
    private int $owner;
    private int $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFulfilment();
        $this->seller = $this->db->insert('sellers', ['name' => 'Race Crafts', 'slug' => 'race-crafts']);
        $this->owner = $this->db->insert('users', ['email' => 'racecrafts@example.com', 'password_hash' => 'x', 'first_name' => 'R', 'last_name' => 'C']);
        $this->db->insert('seller_users', ['seller_id' => $this->seller, 'user_id' => $this->owner, 'role' => 'owner']);
        $this->staff = $this->staffUser();
    }

    protected function tearDown(): void
    {
        $this->tearDownFulfilment();
        parent::tearDown();
    }

    /** @return array{0:int,1:int} [return id ready to complete, open dispute id] on one delivered 2,000 part */
    private function contested(): array
    {
        $v = $this->makeVariant(5, '2000.00', null, 'Default', $this->seller)['variant_id'];
        $o = $this->placeOrder([[$v, 1]], 'mpesa_till');
        $this->db->exec("UPDATE orders SET payment_status = 'verified' WHERE id = ?", [$o['id']]);
        (new OrderWorkflow($this->db))->transition((int) $o['id'], 'confirmed', ['type' => 'staff', 'id' => $this->staff], 'Paid');
        $so = (int) $this->db->value('SELECT id FROM seller_orders WHERE order_id = ?', [$o['id']]);
        $wf = new SellerOrderWorkflow($this->db);
        foreach (['accepted', 'processing', 'packed'] as $to) {
            $wf->transition($so, $to, ['type' => 'seller', 'id' => $this->owner, 'seller_id' => $this->seller]);
        }
        foreach (['handed_over', 'dispatched', 'delivered'] as $to) {
            $wf->transition($so, $to, ['type' => 'staff', 'id' => $this->staff]);
        }
        $rs = new ReturnService($this->db);
        [$r] = $rs->request((int) $o['id'], [(int) $this->db->value('SELECT id FROM order_items WHERE order_id = ?', [$o['id']]) => 1], 'damaged', null, null);
        $rs->approve((int) $r['id'], $this->staff, null);
        $rs->receive((int) $r['id'], $this->staff);
        $rs->inspect((int) $r['id'], (int) $this->db->value('SELECT id FROM return_items WHERE return_id = ?', [$r['id']]), 'restock', null, $this->staff);
        $d = (new DisputeService($this->db))->open($so, 'staff', $this->staff, 'damaged', 'Customer also raised a case about the same item.');
        return [(int) $r['id'], (int) $d['id']];
    }

    public function testReturnAndDisputeRefundsRacingNeverExceedWhatWasPaid(): void
    {
        $rounds = 5;
        $wins = ['return' => 0, 'dispute' => 0];
        for ($i = 0; $i < $rounds; $i++) {
            [$ret, $dis] = $this->contested();
            $so = (int) $this->db->value('SELECT seller_order_id FROM return_requests WHERE id = ?', [$ret]);
            $startAt = sprintf('%.6F', microtime(true) + 1.5);
            $procs = $pipes = [];
            // Each asks for 1,500 of the 2,000 paid: both together would refund 3,000.
            foreach ([['return', $ret], ['dispute', $dis]] as $k => [$mode, $id]) {
                $procs[$k] = proc_open([PHP_BINARY, dirname(__DIR__) . '/scripts/race_refund.php', $mode, (string) $id, (string) $this->staff, '1500', $startAt],
                    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$k], null, array_merge(getenv(), ['WAIMART_ENV' => 'testing']));
            }
            $out = [];
            foreach ($procs as $k => $p) {
                $stdout = stream_get_contents($pipes[$k][1]);
                $stderr = stream_get_contents($pipes[$k][2]);
                proc_close($p);
                $out[$k] = json_decode(trim((string) $stdout), true);
                $this->assertIsArray($out[$k], "worker $k: $stdout $stderr");
            }
            $this->assertCount(1, array_filter($out, fn ($r) => $r['ok']), 'round ' . ($i + 1) . ': exactly one refund decision fits: ' . json_encode($out));
            $wins[$out[0]['ok'] ? 'return' : 'dispute']++;
            $refunded = Money::normalize((string) $this->db->value("SELECT COALESCE(SUM(amount), 0) FROM refunds WHERE seller_order_id = ? AND status <> 'cancelled'", [$so]));
            $this->assertSame('1500.00', $refunded, 'never more than the 2,000 paid');
        }
        $bad = array_filter((new MarketplaceReportService($this->db))->reconcile(), fn ($c) => !$c['ok']);
        $this->assertSame([], array_values($bad));
    }
}
