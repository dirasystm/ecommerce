<?php

declare(strict_types=1);

namespace Waimart\Tests\Integration;

use Waimart\Core\Crypto;
use Waimart\Services\CartService;

/**
 * Real concurrency: separate PHP processes (separate DB connections) race to check out.
 * C9 #1 — many customers, one last unit: exactly one wins, nothing is oversold.
 * C2    — the same submission sent many times at once: exactly one order and one reservation.
 */
final class ConcurrencyTest extends DbTestCase
{
    private const WORKERS = 8;

    protected function setUp(): void
    {
        parent::setUp();
        $zone = $this->db->insert('delivery_zones', ['name' => 'City', 'fee' => '200', 'min_days' => 1, 'max_days' => 2, 'cod_allowed' => 1]);
        $this->db->insert('delivery_zone_locations', ['zone_id' => $zone, 'county_id' => 47, 'town' => '']);
    }

    /**
     * @param list<array{token:string,key:string,phone:string}> $jobs
     * @return list<array{ok:bool,created:bool,order:?string,error:?string}>
     */
    private function race(array $jobs): array
    {
        $script = dirname(__DIR__) . '/scripts/race_place_order.php';
        $startAt = sprintf('%.6F', microtime(true) + 1.5);     // time for all processes to boot
        $procs = [];
        foreach ($jobs as $i => $job) {
            $cmd = [PHP_BINARY, $script, $job['token'], $job['key'], $job['phone'], $startAt];
            $procs[$i] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i], null, array_merge(getenv(), ['WAIMART_ENV' => 'testing']));
        }
        $results = [];
        foreach ($procs as $i => $p) {
            $out = stream_get_contents($pipes[$i][1]);
            $err = stream_get_contents($pipes[$i][2]);
            proc_close($p);
            $decoded = json_decode(trim((string) $out), true);
            $this->assertIsArray($decoded, "worker $i produced no JSON. stdout: $out stderr: $err");
            $results[] = $decoded;
        }
        return $results;
    }

    private function guestCartWith(int $variantId): string
    {
        $cart = new CartService(null, null, $this->db);
        $cart->add($variantId, 1);
        return (string) $cart->guestToken();
    }

    /** C9 #1: two (here eight) customers attempt to buy the last unit simultaneously. */
    public function testScenario1ManyCustomersOneLastUnit(): void
    {
        $v = $this->makeVariant(1)['variant_id'];
        $jobs = [];
        for ($i = 0; $i < self::WORKERS; $i++) {
            $jobs[] = ['token' => $this->guestCartWith($v), 'key' => Crypto::token(32), 'phone' => sprintf('07120000%02d', $i)];
        }
        $results = $this->race($jobs);

        $winners = array_filter($results, fn ($r) => $r['ok']);
        $this->assertCount(1, $winners, 'exactly one customer gets the last unit: ' . json_encode($results));
        foreach (array_filter($results, fn ($r) => !$r['ok']) as $loser) {
            $this->assertMatchesRegularExpression('/ValidationException|InsufficientStockException/', (string) $loser['error'], 'losers get a stock message, not a crash');
        }
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM orders'));
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM stock_reservations WHERE status = 'active'"));
        $this->assertSame(['on_hand' => 1, 'reserved' => 1], array_map('intval', $this->db->one('SELECT on_hand, reserved FROM inventory WHERE variant_id = ?', [$v])));
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM stock_movements WHERE movement_type = 'reserve'"));
    }

    /** C9 #4 under real concurrency: several expiry workers start at the same instant. */
    public function testConcurrentExpiryWorkersReleaseEachReservationOnce(): void
    {
        $orders = [];
        $variants = [];
        for ($i = 0; $i < 5; $i++) {
            $v = $this->makeVariant(3)['variant_id'];
            $variants[] = $v;
            $o = (new \Waimart\Services\OrderService($this->db))->place(
                (function () use ($v) { $c = new CartService(null, null, $this->db); $c->add($v, 2); return $c; })(),
                ['customer_name' => 'X', 'customer_phone' => sprintf('07130000%02d', $i), 'fulfilment_method' => 'delivery', 'county_id' => '47',
                 'town' => 'Kilimani', 'location' => 'Ngong Road', 'payment_method' => 'cod'],
                null, Crypto::token(32), '127.0.0.1'
            )['order'];
            $orders[] = (int) $o['id'];
        }
        $this->db->exec("UPDATE stock_reservations SET expires_at = NOW() - INTERVAL 1 MINUTE");

        $script = dirname(__DIR__) . '/scripts/run_expiry.php';
        $startAt = sprintf('%.6F', microtime(true) + 1.5);
        $procs = [];
        $pipes = [];
        for ($i = 0; $i < 4; $i++) {
            $procs[$i] = proc_open([PHP_BINARY, $script, $startAt], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i], null, array_merge(getenv(), ['WAIMART_ENV' => 'testing']));
        }
        $totalExpired = 0;
        foreach ($procs as $i => $p) {
            $out = json_decode(trim((string) stream_get_contents($pipes[$i][1])), true);
            stream_get_contents($pipes[$i][2]);
            proc_close($p);
            $this->assertIsArray($out);
            $totalExpired += $out['orders_expired'];
        }
        $this->assertSame(5, $totalExpired, 'each order expired by exactly one worker');
        $this->assertSame(5, (int) $this->db->value("SELECT COUNT(*) FROM stock_movements WHERE movement_type = 'expire'"));
        foreach ($variants as $v) {
            $this->assertSame(0, (int) $this->db->value('SELECT reserved FROM inventory WHERE variant_id = ?', [$v]));
        }
        $this->assertSame(5, (int) $this->db->value("SELECT COUNT(*) FROM orders WHERE status = 'expired'"));
    }

    /** Same checkout submitted many times at once (double-click / retry storm). */
    public function testSameSubmissionInParallelCreatesOneOrder(): void
    {
        $v = $this->makeVariant(10)['variant_id'];
        $token = $this->guestCartWith($v);
        $key = Crypto::token(32);
        $results = $this->race(array_fill(0, 6, ['token' => $token, 'key' => $key, 'phone' => '0712345678']));

        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM orders'), json_encode($results));
        $numbers = array_unique(array_filter(array_column($results, 'order')));
        $this->assertCount(1, $numbers, 'every successful response points at the same order');
        $this->assertSame(1, count(array_filter($results, fn ($r) => $r['created'])), 'only one request actually created it');
        $this->assertSame(1, (int) $this->db->value('SELECT reserved FROM inventory WHERE variant_id = ?', [$v]));
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM stock_reservations'));
    }
}
