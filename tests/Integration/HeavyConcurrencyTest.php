<?php

declare(strict_types=1);

namespace Waimart\Tests\Integration;

use Waimart\Core\Crypto;
use Waimart\Services\CartService;
use Waimart\Services\PaymentService;

/**
 * Phase 12 load-style races with real processes (separate DB connections):
 *  - many buyers with multi-line carts, added in opposite orders, over limited stock (no oversell, no deadlock crash);
 *  - staff verifying M-Pesa payments at the same instant the expiry worker sweeps the same orders.
 */
final class HeavyConcurrencyTest extends DbTestCase
{
    use PlacesOrders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFulfilment();
    }

    protected function tearDown(): void
    {
        $this->tearDownFulfilment();
        parent::tearDown();
    }

    /**
     * @param list<list<string>> $argSets script arguments (start time is appended)
     * @return list<array<string,mixed>>
     */
    private function spawn(string $script, array $argSets, float $delay = 2.0): array
    {
        $startAt = sprintf('%.6F', microtime(true) + $delay);
        $procs = [];
        $pipes = [];
        foreach ($argSets as $i => $args) {
            $procs[$i] = proc_open(array_merge([PHP_BINARY, dirname(__DIR__) . '/scripts/' . $script], $args, [$startAt]),
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i], null, array_merge(getenv(), ['WAIMART_ENV' => 'testing']));
        }
        $out = [];
        foreach ($procs as $i => $p) {
            $stdout = stream_get_contents($pipes[$i][1]);
            $stderr = stream_get_contents($pipes[$i][2]);
            proc_close($p);
            $decoded = json_decode(trim((string) $stdout), true);
            $this->assertIsArray($decoded, "$script worker $i produced no JSON. stdout: $stdout stderr: $stderr");
            $out[$i] = $decoded;
        }
        return $out;
    }

    public function testManyBuyersWithCrossedMultiLineCartsNeverOversell(): void
    {
        $a = $this->makeVariant(7)['variant_id'];
        $b = $this->makeVariant(7)['variant_id'];
        $jobs = [];
        for ($i = 0; $i < 16; $i++) {
            // Half add A then B, half B then A: lock ordering must be by variant id, not cart order.
            $cart = new CartService(null, null, $this->db);
            $lines = $i % 2 ? [[$a, 2], [$b, 1]] : [[$b, 2], [$a, 1]];
            foreach ($lines as [$v, $q]) {
                $cart->add($v, $q);
            }
            $jobs[] = [(string) $cart->guestToken(), Crypto::token(32), sprintf('07140000%02d', $i)];
        }
        $results = $this->spawn('race_place_order.php', $jobs);

        $winners = array_filter($results, fn ($r) => $r['ok']);
        foreach (array_filter($results, fn ($r) => !$r['ok']) as $loser) {
            $this->assertMatchesRegularExpression('/ValidationException|InsufficientStockException/', (string) $loser['error'], 'losers get a stock message, not a crash or deadlock');
        }
        // Each order takes 3 of the 14 units: a 5th can never fit, and after any 3 orders one cart shape always still fits,
        // so exactly 4 buyers win whatever order the database serves them in.
        $this->assertCount(4, $winners, 'available stock is sold, and no more: ' . json_encode($results));
        $this->assertSame(count($winners), (int) $this->db->value('SELECT COUNT(*) FROM orders'));
        foreach ([$a, $b] as $v) {
            $l = $this->levels($v);
            $this->assertSame(7, $l['on_hand']);
            $this->assertLessThanOrEqual(7, $l['reserved'], 'never oversold');
        }
        $this->assertSame(count($winners) * 3, (int) $this->db->value("SELECT SUM(quantity) FROM stock_reservations WHERE status = 'active'"));
        $this->assertInventoryConsistent();
        $this->assertSame(0, (int) $this->db->value(
            'SELECT COUNT(*) FROM orders o WHERE (SELECT COUNT(*) FROM stock_reservations r WHERE r.order_id = o.id) <> 2'
        ), 'no order is left with a partial reservation');
    }

    public function testPaymentVerificationRacingTheExpiryWorker(): void
    {
        $staff = $this->staffUser();
        $pay = new PaymentService($this->db);
        $orders = [];
        $verifyJobs = [];
        for ($i = 0; $i < 8; $i++) {
            $v = $this->makeVariant(2)['variant_id'];
            $o = $this->placeOrder([[$v, 2]], 'mpesa_till');
            $s = $pay->submit((int) $o['id'], ['transaction_code' => 'R' . strtoupper(bin2hex(random_bytes(4))) . $i, 'payer_phone' => '0712345678',
                'amount' => (string) $o['grand_total']], '10.0.1.' . ($i + 1));
            $orders[(int) $o['id']] = $v;
            $verifyJobs[] = [(string) $s['id'], (string) $staff, (string) $o['grand_total']];
        }
        // Every reservation is already past due: the sweep and the verifications start at the same instant.
        $this->db->exec('UPDATE stock_reservations SET expires_at = NOW() - INTERVAL 1 MINUTE');

        // Verifiers start staggered across the sweep, so some land before the worker reaches their order and some after.
        $jobs = [['run_expiry.php', [], 0.0], ['run_expiry.php', [], 0.0]];
        foreach ($verifyJobs as $i => $j) {
            $jobs[] = ['race_verify_payment.php', $j, $i * 0.04];
        }
        $base = microtime(true) + 2.5;
        $procs = [];
        $pipes = [];
        foreach ($jobs as $i => [$script, $args, $offset]) {
            $procs[$i] = proc_open(array_merge([PHP_BINARY, dirname(__DIR__) . '/scripts/' . $script], $args, [sprintf('%.6F', $base + $offset)]),
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i], null, array_merge(getenv(), ['WAIMART_ENV' => 'testing']));
        }
        foreach ($procs as $i => $p) {
            $stdout = stream_get_contents($pipes[$i][1]);
            $stderr = stream_get_contents($pipes[$i][2]);
            proc_close($p);
            $r = json_decode(trim((string) $stdout), true);
            $this->assertIsArray($r, "{$jobs[$i][0]} produced no JSON: $stdout $stderr");
            if ($jobs[$i][0] === 'race_verify_payment.php') {
                $this->assertTrue($r['ok'], 'every verification succeeds one way or the other: ' . json_encode($r));
            } else {
                $this->assertSame(0, $r['errors'], 'expiry worker hit no errors');
            }
        }

        foreach ($orders as $id => $v) {
            $o = (array) $this->db->one('SELECT status, payment_status FROM orders WHERE id = ?', [$id]);
            $this->assertSame('verified', $o['payment_status']);
            $active = (int) $this->db->value("SELECT COUNT(*) FROM stock_reservations WHERE order_id = ? AND status = 'active'", [$id]);
            $expires = (int) $this->db->value("SELECT COUNT(*) FROM stock_movements WHERE order_id = ? AND movement_type = 'expire'", [$id]);
            if ($o['status'] === 'confirmed') {
                // Verification won: stock stays held for fulfilment and is never also released.
                $this->assertSame(1, $active, "order $id confirmed with its reservation");
                $this->assertSame(0, $expires);
                $this->assertSame(2, $this->levels($v)['reserved']);
            } else {
                // Expiry won: payment arrived too late, so staff must resolve it (C5) â€” stock was released exactly once.
                $this->assertSame('requires_resolution', $o['status'], "order $id");
                $this->assertSame(0, $active);
                $this->assertSame(1, $expires, "order $id released exactly once");
                $this->assertSame(0, $this->levels($v)['reserved']);
            }
        }
        $this->assertInventoryConsistent();
    }
}
