<?php

declare(strict_types=1);

namespace Waimart\Tests\Integration;

use Waimart\Core\Crypto;
use Waimart\Core\Settings;
use Waimart\Services\OrderWorkflow;
use Waimart\Services\PayoutService;
use Waimart\Services\SellerLedgerService;
use Waimart\Services\SellerOrderWorkflow;

/**
 * Phase 8 races with real processes (separate DB connections): many payout requests for one seller at the same
 * instant, staff recording the same payment twice, and two release workers sweeping together.
 */
final class PayoutConcurrencyTest extends DbTestCase
{
    use PlacesOrders;

    private int $seller;
    private int $owner;
    private int $staff;
    /** @var array<string,?string> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFulfilment();
        foreach (['payout_hold_days', 'payout_min_amount'] as $k) {
            $this->saved[$k] = Settings::get($k);
        }
        Settings::set('payout_hold_days', '0');
        Settings::set('payout_min_amount', '500');
        $this->seller = $this->db->insert('sellers', ['name' => 'Race Shop', 'slug' => 'race-shop']);
        $this->owner = $this->db->insert('users', ['email' => 'race@example.com', 'password_hash' => 'x', 'first_name' => 'R', 'last_name' => 'S']);
        $this->db->insert('seller_users', ['seller_id' => $this->seller, 'user_id' => $this->owner, 'role' => 'owner']);
        $this->db->insert('seller_payout_methods', ['seller_id' => $this->seller, 'method' => 'mpesa', 'details_enc' => Crypto::encrypt('{"phone":"254700000001"}'), 'masked' => 'M-Pesa ••• 001']);
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

    /** @param list<list<string>> $argSets @return list<array<string,mixed>> */
    private function spawn(array $argSets, float $delay = 2.0): array
    {
        $startAt = sprintf('%.6F', microtime(true) + $delay);
        $procs = [];
        $pipes = [];
        foreach ($argSets as $i => $args) {
            $procs[$i] = proc_open(array_merge([PHP_BINARY, dirname(__DIR__) . '/scripts/race_payout.php'], $args, [$startAt]),
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i], null, array_merge(getenv(), ['WAIMART_ENV' => 'testing']));
        }
        $out = [];
        foreach ($procs as $i => $p) {
            $stdout = stream_get_contents($pipes[$i][1]);
            $stderr = stream_get_contents($pipes[$i][2]);
            proc_close($p);
            $decoded = json_decode(trim((string) $stdout), true);
            $this->assertIsArray($decoded, "worker $i produced no JSON. stdout: $stdout stderr: $stderr");
            $out[$i] = $decoded;
        }
        return $out;
    }

    /** Delivered marketplace parts worth $n × 1,800 net, still pending. */
    private function deliveredParts(int $n): void
    {
        $seller = ['type' => 'seller', 'id' => $this->owner, 'seller_id' => $this->seller];
        $staff = ['type' => 'staff', 'id' => $this->staff];
        for ($i = 0; $i < $n; $i++) {
            $v = $this->makeVariant(5, '2000.00', null, 'Default', $this->seller)['variant_id'];
            $o = $this->placeOrder([[$v, 1]], 'mpesa_till');
            $this->db->exec("UPDATE orders SET payment_status = 'verified' WHERE id = ?", [$o['id']]);
            (new OrderWorkflow($this->db))->transition((int) $o['id'], 'confirmed', $staff, 'Paid');
            $so = (int) $this->db->value('SELECT id FROM seller_orders WHERE order_id = ?', [$o['id']]);
            $wf = new SellerOrderWorkflow($this->db);
            foreach ([['accepted', $seller], ['processing', $seller], ['packed', $seller], ['handed_over', $staff], ['dispatched', $staff], ['delivered', $staff]] as [$to, $actor]) {
                $wf->transition($so, $to, $actor);
            }
        }
    }

    public function testSimultaneousPayoutRequestsNeverOverdraw(): void
    {
        $this->deliveredParts(1);
        (new SellerLedgerService($this->db))->releaseEligible();
        // 8 requests of 1,000 against 1,800 available: at most one can fit, and exactly one must succeed.
        $results = $this->spawn(array_fill(0, 8, ['request', (string) $this->seller, (string) $this->owner, '1000']));
        $ok = array_filter($results, fn ($r) => $r['ok']);
        $this->assertCount(1, $ok, 'exactly one request wins: ' . json_encode($results));
        foreach (array_filter($results, fn ($r) => !$r['ok']) as $r) {
            $this->assertStringContainsString('ValidationException', (string) $r['error'], 'losers are refused cleanly, not crashed');
        }
        $b = (new SellerLedgerService($this->db))->balances($this->seller);
        $this->assertSame(['available' => '800.00', 'reserved' => '1000.00'], array_intersect_key($b, ['available' => 1, 'reserved' => 1]));
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM seller_payouts'));
        $this->assertSame(2, (int) $this->db->value("SELECT COUNT(*) FROM seller_ledger_entries WHERE entry_type = 'payout_request'"));
    }

    public function testRecordingTheSamePaymentTwiceAtOnceIsSafe(): void
    {
        $this->deliveredParts(1);
        (new SellerLedgerService($this->db))->releaseEligible();
        $svc = new PayoutService($this->db);
        $p = $svc->request($this->seller, $this->owner, '1800');
        $svc->approve((int) $p['id'], $this->staff);
        $results = $this->spawn(array_fill(0, 4, ['paid', (string) $p['id'], (string) $this->staff, 'RACE12345', '1800']));
        $this->assertCount(1, array_filter($results, fn ($r) => $r['ok']), json_encode($results));
        $this->assertSame(['pending' => '0.00', 'available' => '0.00', 'reserved' => '0.00', 'paid' => '1800.00'], (new SellerLedgerService($this->db))->balances($this->seller));
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM seller_payout_history WHERE to_status = 'paid'"));
    }

    public function testReleaseWorkersRacingPostOnce(): void
    {
        $this->deliveredParts(3);
        $results = $this->spawn(array_fill(0, 4, ['release']));
        foreach ($results as $r) {
            $this->assertTrue($r['ok'], (string) $r['error']);
        }
        $this->assertSame(3, array_sum(array_column($results, 'result')), 'each part released by exactly one worker');
        $this->assertSame(6, (int) $this->db->value("SELECT COUNT(*) FROM seller_ledger_entries WHERE entry_type = 'release'"));
        $b = (new SellerLedgerService($this->db))->balances($this->seller);
        $this->assertSame(['pending' => '0.00', 'available' => '5400.00'], array_intersect_key($b, ['pending' => 1, 'available' => 1]));
    }
}
