<?php

declare(strict_types=1);

namespace Waimart\Tests\Integration;

use Waimart\Core\Crypto;
use Waimart\Core\Money;
use Waimart\Core\Settings;
use Waimart\Core\ValidationException;
use Waimart\Services\CodService;
use Waimart\Services\CollectionService;
use Waimart\Services\DisputeService;
use Waimart\Services\MarketplaceReportService;
use Waimart\Services\OrderWorkflow;
use Waimart\Services\PayoutService;
use Waimart\Services\RefundService;
use Waimart\Services\ReturnService;
use Waimart\Services\SellerLedgerService;
use Waimart\Services\SellerOrderService;
use Waimart\Services\SellerOrderWorkflow;
use Waimart\Services\Sellers;

/**
 * Phase 10 financial verification: many orders across several sellers go through random paths (declines, deliveries,
 * COD pickups, partial returns, cases with refunds, cancelled refunds, releases, payouts). Afterwards every invariant
 * must hold. Fixed seeds make any failure reproducible: the seed is in the failure message.
 */
final class MarketplaceMoneySimulationTest extends DbTestCase
{
    use PlacesOrders;

    /** @var list<array{id:int,owner:int}> */
    private array $sellers = [];
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
        $this->staff = $this->staffUser();
        foreach (['Amani', 'Baraka', 'Chui'] as $i => $name) {
            $s = $this->db->insert('sellers', ['name' => "$name Store", 'slug' => strtolower($name) . '-store']);
            $u = $this->db->insert('users', ['email' => "sim$i@example.com", 'password_hash' => 'x', 'first_name' => $name, 'last_name' => 'Owner']);
            $this->db->insert('seller_users', ['seller_id' => $s, 'user_id' => $u, 'role' => 'owner']);
            $this->db->insert('seller_payout_methods', ['seller_id' => $s, 'method' => 'mpesa', 'details_enc' => Crypto::encrypt('{"phone":"254700000000"}'), 'masked' => 'M-Pesa ••• 000']);
            $this->sellers[] = ['id' => $s, 'owner' => $u];
        }
        // Commission variety: 10% default, one seller at 7.5%.
        $this->db->insert('commission_rules', ['scope' => 'seller', 'seller_id' => $this->sellers[1]['id'], 'rate' => '7.50']);
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            Settings::set($k, (string) $v);
        }
        $this->tearDownFulfilment();
        parent::tearDown();
    }

    /** Three fixed seeds; more with WAIMART_SIM_SEEDS="1-50" (a range) or "5,9,12" for a deeper sweep. @return list<array{int}> */
    public static function seeds(): array
    {
        $extra = (string) getenv('WAIMART_SIM_SEEDS');
        if (preg_match('/^(\d+)-(\d+)$/', $extra, $m)) {
            return array_map(fn ($n) => [$n], range((int) $m[1], (int) $m[2]));
        }
        return $extra !== '' ? array_map(fn ($n) => [(int) $n], explode(',', $extra)) : [[20261018], [7], [424242]];
    }

    /** @dataProvider seeds */
    public function testRandomMarketplaceActivityKeepsEveryMoneyInvariant(int $seed): void
    {
        mt_srand($seed);
        $staff = ['type' => 'staff', 'id' => $this->staff];
        $variants = [];
        foreach ($this->sellers as $s) {
            foreach (['999.99', '1500.00', '2350.50'] as $price) {
                $variants[$s['id']][] = $this->makeVariant(500, $price, null, 'Default', $s['id'])['variant_id'];
            }
        }
        $platform = $this->makeVariant(500, '799.00')['variant_id'];
        $log = [];

        for ($n = 0; $n < 24; $n++) {
            // --- an order with 1–3 sellers (sometimes an Official Store item)
            $lines = [];
            foreach ($this->sellers as $s) {
                if (mt_rand(0, 2) > 0) {
                    $lines[] = [$variants[$s['id']][mt_rand(0, 2)], mt_rand(1, 2)];
                }
            }
            if (!$lines || mt_rand(0, 3) === 0) {
                $lines[] = [$platform, 1];
            }
            $cod = mt_rand(0, 3) === 0;
            $o = $this->placeOrder($lines, $cod ? 'cod' : 'mpesa_till', $cod ? 'pickup' : 'delivery');
            $oid = (int) $o['id'];
            if (!$cod) {
                $this->db->exec("UPDATE orders SET payment_status = 'verified' WHERE id = ?", [$oid]);
            }
            (new OrderWorkflow($this->db))->transition($oid, 'confirmed', $staff, 'Sim');
            $wf = new SellerOrderWorkflow($this->db);
            $parts = $this->db->all('SELECT so.id, so.seller_id, s.is_platform FROM seller_orders so JOIN sellers s ON s.id = so.seller_id WHERE so.order_id = ? ORDER BY so.id', [$oid]);

            // --- Official-Store-only orders keep the order-level flow
            if (!array_filter($parts, fn ($p) => !(int) $p['is_platform'])) {
                $ow = new OrderWorkflow($this->db);
                if ($cod) {
                    $ow->transition($oid, 'processing', $staff);
                    (new CollectionService($this->db))->markReady($oid, $this->staff);
                } else {
                    foreach (['processing', 'packed', 'dispatched', 'delivered'] as $to) {
                        $ow->transition($oid, $to, $staff);
                    }
                }
                $parts = [];
            }
            // --- each part: sometimes a case early, sometimes declined, otherwise supplied
            foreach ($parts as $p) {
                $so = (int) $p['id'];
                if ((int) $p['is_platform']) {
                    foreach ($cod ? ['processing', 'packed', 'ready_for_collection'] : ['processing', 'packed', 'dispatched', 'delivered'] as $to) {
                        $wf->transition($so, $to, $staff);
                    }
                    continue;
                }
                $seller = ['type' => 'seller', 'id' => $this->ownerOf((int) $p['seller_id']), 'seller_id' => (int) $p['seller_id']];
                $wf->transition($so, 'accepted', $seller);
                if (!$cod && mt_rand(0, 5) === 0) {   // a goodwill refund on a case while still in progress
                    $this->tryResolve($so, 'refund_partial', (string) mt_rand(50, 600), $log);
                }
                if (mt_rand(0, 6) === 0) {
                    $wf->transition($so, 'rejected', $seller, ['reason' => 'Simulated decline']);
                    $log[] = "decline $so";
                    continue;
                }
                foreach (['processing', 'packed'] as $to) {
                    $wf->transition($so, $to, $seller);
                }
                $wf->transition($so, 'handed_over', $staff);
                foreach ($cod ? ['ready_for_collection'] : ['dispatched', 'delivered'] as $to) {
                    $wf->transition($so, $to, $staff);
                }
            }
            if ($cod) {
                $order = (array) $this->db->one('SELECT * FROM orders WHERE id = ?', [$oid]);
                if (in_array($order['status'], ['ready_for_collection', 'processing', 'packed', 'confirmed'], true) && CollectionService::codeFor($order)) {
                    (new CollectionService($this->db))->verifyAndCollect($oid, (string) CollectionService::codeFor($order), $this->staff, true);
                    $order = (array) $this->db->one('SELECT * FROM orders WHERE id = ?', [$oid]);
                    if (Money::cmp(SellerOrderService::amountDue($order), '0') > 0 && $order['status'] === 'collected') {
                        (new CodService($this->db))->record($oid, SellerOrderService::amountDue($order), $this->staff, null);
                    }
                }
            }

            // --- after-sales on this order
            $order = (array) $this->db->one('SELECT * FROM orders WHERE id = ?', [$oid]);
            $rs = new ReturnService($this->db);
            $items = $rs->returnableItems($order);
            if ($items && mt_rand(0, 2) === 0) {
                $pick = $items[mt_rand(0, count($items) - 1)];
                [$ret] = $rs->request($oid, [$pick['id'] => 1], 'damaged', null, null);
                $rs->approve((int) $ret['id'], $this->staff, null);
                $rs->receive((int) $ret['id'], $this->staff);
                foreach ($this->db->all('SELECT id FROM return_items WHERE return_id = ?', [$ret['id']]) as $ri) {
                    $rs->inspect((int) $ret['id'], (int) $ri['id'], mt_rand(0, 1) ? 'restock' : 'write_off', null, $this->staff);
                }
                $max = $rs->maxRefund((int) $ret['id']);
                $amount = mt_rand(0, 3) === 0 ? '0' : Money::round(bcmul($max, (string) (mt_rand(30, 100) / 100), 4));
                $rs->complete((int) $ret['id'], $this->staff, $amount, Money::isZero($amount) ? 'Sim: no refund' : null);
                $log[] = "return {$ret['id']} refund $amount of max $max";
            }
            foreach ($this->db->all("SELECT so.id FROM seller_orders so JOIN sellers s ON s.id = so.seller_id WHERE so.order_id = ? AND s.is_platform = 0 AND so.status IN ('delivered','collected')", [$oid]) as $p) {
                if (mt_rand(0, 4) === 0) {
                    $this->tryResolve((int) $p['id'], mt_rand(0, 1) ? 'refund_full' : 'refund_partial', (string) mt_rand(100, 3000), $log);
                }
            }
            // Sometimes a refund owed is cancelled instead of paid; otherwise paid.
            foreach ($this->db->all("SELECT id FROM refunds WHERE order_id = ? AND status = 'due'", [$oid]) as $rf) {
                mt_rand(0, 4) === 0
                    ? (new RefundService($this->db))->cancel((int) $rf['id'], $this->staff, 'Sim: replacement sent instead')
                    : (new RefundService($this->db))->markPaid((int) $rf['id'], $this->staff, 'mpesa', 'SIM' . $rf['id'] . 'X' . $n, null);
            }

            // --- money out
            (new SellerLedgerService($this->db))->releaseEligible();
            foreach ($this->sellers as $s) {
                $avail = (new SellerLedgerService($this->db))->balances($s['id'])['available'];
                if (Money::cmp($avail, '500') >= 0 && mt_rand(0, 2) === 0) {
                    $ps = new PayoutService($this->db);
                    try {
                        $p = $ps->request($s['id'], $s['owner'], Money::round(bcmul($avail, (string) (mt_rand(50, 100) / 100), 4)));
                    } catch (ValidationException) {
                        continue;   // e.g. one already open, or the share fell below the minimum
                    }
                    match (mt_rand(0, 3)) {
                        0 => $ps->reject((int) $p['id'], $this->staff, 'Sim rejection'),
                        1 => null,   // left open (reserved)
                        default => [$ps->approve((int) $p['id'], $this->staff), $ps->markPaid((int) $p['id'], $this->staff, 'mpesa', 'PO' . $p['id'] . 'S' . $seed, (string) $p['amount'])],
                    };
                }
            }
        }

        $this->assertInvariants($seed, $log);
    }

    private function ownerOf(int $sellerId): int
    {
        foreach ($this->sellers as $s) {
            if ($s['id'] === $sellerId) {
                return $s['owner'];
            }
        }
        throw new \LogicException('unknown seller');
    }

    /** Opens a case on a part and resolves it with a refund; refusals (cap, unpaid, already open) are part of the simulation. */
    private function tryResolve(int $so, string $resolution, string $amount, array &$log): void
    {
        $svc = new DisputeService($this->db);
        try {
            $d = $svc->open($so, 'staff', $this->staff, 'damaged', 'Simulated case about this part.');
            $svc->resolve((int) $d['id'], $this->staff, $resolution, 'Simulated decision', $amount);
            $log[] = "case on $so: $resolution $amount";
        } catch (ValidationException $e) {
            $log[] = "case on $so refused: " . $e->getMessage();
            if (isset($d)) {
                $svc->resolve((int) $d['id'], $this->staff, 'no_action', 'Simulated: closed without refund');
            }
        }
    }

    private function assertInvariants(int $seed, array $log): void
    {
        $ctx = "seed $seed — " . implode(' | ', array_slice($log, -12));
        // 1. Reconciliation.
        $bad = array_values(array_filter((new MarketplaceReportService($this->db))->reconcile(), fn ($c) => !$c['ok']));
        $this->assertSame([], $bad, "reconciliation: $ctx");

        // 2. Never refund more than was paid — per part and per order.
        $over = $this->db->all(
            "SELECT so.id, so.items_subtotal - so.discount_share AS paid, SUM(r.amount) AS refunded FROM seller_orders so JOIN refunds r ON r.seller_order_id = so.id AND r.status <> 'cancelled'
              GROUP BY so.id HAVING refunded > paid"
        );
        $this->assertSame([], $over, "part over-refunded: $ctx");
        $overOrder = $this->db->all(
            "SELECT o.id, o.grand_total, SUM(r.amount) AS refunded FROM orders o JOIN refunds r ON r.order_id = o.id AND r.status <> 'cancelled' GROUP BY o.id HAVING refunded > o.grand_total"
        );
        $this->assertSame([], $overOrder, "order over-refunded: $ctx");

        // 3. Per seller: balances add up to the ledger and to the summary; reserved/paid never negative; pending never negative.
        $ledger = new SellerLedgerService($this->db);
        foreach ($this->sellers as $s) {
            $b = $ledger->balances($s['id']);
            $sum = Money::normalize((string) $this->db->value('SELECT COALESCE(SUM(amount), 0) FROM seller_ledger_entries WHERE seller_id = ?', [$s['id']]));
            $this->assertSame($sum, Money::add($b['pending'], $b['available'], $b['reserved'], $b['paid']), "balances: $ctx");
            $this->assertSame($sum, $ledger->summary($s['id'])['net_earnings'], "summary: $ctx");
            foreach (['pending', 'reserved', 'paid'] as $k) {
                $this->assertGreaterThanOrEqual(0, Money::cmp($b[$k], '0'), "$k negative for seller {$s['id']}: $ctx");
            }
            $this->assertSame(
                Money::normalize((string) $this->db->value("SELECT COALESCE(SUM(amount), 0) FROM seller_payouts WHERE seller_id = ? AND status IN ('requested','approved')", [$s['id']])),
                $b['reserved'], "reserved = open payouts: $ctx"
            );
        }

        // 4. Every declined/cancelled part nets to zero; the Official Store never appears in the seller ledger.
        $this->assertSame([], $this->db->all(
            "SELECT so.id, SUM(e.amount) AS net FROM seller_orders so JOIN seller_ledger_entries e ON e.seller_order_id = so.id
              WHERE so.status IN ('rejected','cancelled') GROUP BY so.id HAVING net <> 0"
        ), "withdrawn part not at zero: $ctx");
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM seller_ledger_entries WHERE seller_id = ?', [Sellers::platformId($this->db)]));

        // 5. Each part's ledger net is never more than its seller net, and never below zero while still pending.
        $this->assertSame([], $this->db->all(
            "SELECT so.id, so.seller_net, SUM(e.amount) AS net FROM seller_orders so JOIN seller_ledger_entries e ON e.seller_order_id = so.id
              WHERE e.entry_type NOT IN ('release') GROUP BY so.id, so.seller_net HAVING net > so.seller_net OR (net < 0)"
        ), "part earnings out of range: $ctx");
        $this->assertInventoryConsistent();
    }
}
