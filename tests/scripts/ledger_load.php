<?php

declare(strict_types=1);

/**
 * Ledger load test (marketplace Phase 10 item, run in Basemall Stage 6). Uses the TEST database only.
 * Writes ~100,000 append-only ledger rows across 20 sellers through SellerLedgerService::post(), then times what
 * sellers and finance staff open every day (balances, summary, statement, liability, reconciliation, performance)
 * and checks every balance to the cent.
 * Usage: php tests/scripts/ledger_load.php [rows]   (exit code 1 if a check fails or a query is too slow)
 */

putenv('WAIMART_ENV=testing');
require dirname(__DIR__, 2) . '/bootstrap.php';

use Waimart\Core\App;
use Waimart\Services\MarketplaceReportService;
use Waimart\Services\SellerLedgerService;

$db = App::db();
if (!str_ends_with((string) $db->value('SELECT DATABASE()'), '_test')) {
    fwrite(STDERR, "Refusing to run: not the test database.\n");
    exit(1);
}
$target = max(4000, (int) ($argv[1] ?? 100000));
$sellers = 20;
$cycles = intdiv($target, $sellers * 4);      // each cycle: sale, commission, release out of pending, release into available
$tag = bin2hex(random_bytes(3));
$ledger = new SellerLedgerService($db);
$ids = [];
for ($s = 0; $s < $sellers; $s++) {
    $ids[] = $db->insert('sellers', ['name' => "Load $tag $s", 'slug' => "load-$tag-$s"]);
}

$t0 = microtime(true);
$expected = [];
foreach ($ids as $n => $sid) {
    $db->transaction(function () use ($ledger, $sid, $cycles, $tag, &$expected) {
        $avail = '0.00';
        for ($i = 0; $i < $cycles; $i++) {
            $sale = number_format(100 + ($i * 37) % 9000 + (($i * 13) % 100) / 100, 2, '.', '');
            $commission = bcmul($sale, '-0.10', 2);
            $net = bcadd($sale, $commission, 2);
            $k = "load-$tag-$sid-$i";
            $ledger->post($sid, 'sale', 'pending', $sale, "$k-s");
            $ledger->post($sid, 'commission', 'pending', $commission, "$k-c");
            $ledger->post($sid, 'release', 'pending', bcmul($net, '-1', 2), "$k-ro");
            $ledger->post($sid, 'release', 'available', $net, "$k-ri");
            $avail = bcadd($avail, $net, 2);
        }
        $expected[$sid] = $avail;
    }, 1);
}
$written = $sellers * $cycles * 4;
$writeSecs = microtime(true) - $t0;
printf("Wrote %s ledger rows in %.1f s (%.0f rows/s). Ledger now holds %s rows.\n", number_format($written), $writeSecs, $written / $writeSecs,
    number_format((int) $db->value('SELECT COUNT(*) FROM seller_ledger_entries')));

$time = function (string $label, callable $fn, float $limitMs) use (&$fail): mixed {
    $t = microtime(true);
    $r = $fn();
    $ms = (microtime(true) - $t) * 1000;
    printf("  %-44s %8.1f ms  %s\n", $label, $ms, $ms <= $limitMs ? 'ok' : "TOO SLOW (limit $limitMs ms)");
    if ($ms > $limitMs) {
        $fail = true;
    }
    return $r;
};
$fail = false;
$sid = $ids[7];
echo "Timings (one seller's ledger: " . number_format($cycles * 4) . " rows):\n";
$bal = $time('balances() — Seller Centre dashboard', fn () => $ledger->balances($sid), 250);
$time('summary() — Seller Centre earnings', fn () => $ledger->summary($sid), 250);
$time('entries(200) — Seller Centre statement', fn () => $ledger->entries($sid, 200), 250);
$rep = new MarketplaceReportService($db);
$time('liability() — finance, all sellers', fn () => $rep->liability(), 3000);
$time('reconcile() — finance checks', fn () => $rep->reconcile(), 5000);
$time('sellerPerformance(30 days)', fn () => $rep->sellerPerformance(date('Y-m-d 00:00:00', strtotime('-30 days')), date('Y-m-d 23:59:59')), 5000);

$plan = $db->all('EXPLAIN SELECT bucket, SUM(amount) FROM seller_ledger_entries WHERE seller_id = ? GROUP BY bucket', [$sid]);
printf("Balance query uses index: %s\n", (string) ($plan[0]['key'] ?? 'NONE'));
if (($plan[0]['key'] ?? null) === null) {
    $fail = true;
}

$bad = 0;
foreach ($expected as $s => $avail) {
    $b = $ledger->balances($s);
    if ($b['available'] !== $avail || $b['pending'] !== '0.00' || $b['reserved'] !== '0.00' || $b['paid'] !== '0.00') {
        $bad++;
        echo "  MISMATCH seller $s: " . json_encode($b) . " expected available $avail\n";
    }
}
echo $bad === 0 ? "All $sellers sellers' balances exact to the cent.\n" : "$bad seller(s) wrong.\n";
echo ($fail || $bad) ? "LEDGER LOAD TEST FAILED\n" : "LEDGER LOAD TEST PASSED\n";
exit(($fail || $bad) ? 1 : 0);
