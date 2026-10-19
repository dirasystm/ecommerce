<?php

declare(strict_types=1);

/**
 * Extends fulfilment to the whole of Kenya, for testing and commissioning:
 *   - a pickup station in the main town of every county that has no open station yet (KenyaTowns);
 *   - a "Rest of Kenya" delivery zone covering every county that no zone covers as a whole yet.
 * Safe to run again: it only adds what is missing. Nothing existing is changed.
 *
 * The new stations' street addresses are placeholders ("exact location to be confirmed"). Replace them with the
 * real addresses and hours in Admin → Fulfilment → Pickup points before going live (or switch unused ones off).
 * Fee, delivery days and cash-on-delivery for the new zone are starting values: adjust them in Delivery zones.
 *
 * Usage: php bin/seed-kenya-coverage.php [--dry-run] [--allow-production]
 */

require dirname(__DIR__) . '/bootstrap.php';

use Waimart\Core\App;
use Waimart\Services\AuditLogger;
use Waimart\Services\KenyaTowns;

$dry = in_array('--dry-run', $argv, true);
if (config('app.env') === 'production' && !in_array('--allow-production', $argv, true)) {
    fwrite(STDERR, "This adds pickup stations with placeholder addresses. On a live shop, add real stations in the admin instead\n"
        . "(or run with --allow-production and edit every new station before customers can see it).\n");
    exit(1);
}

$db = App::db();
$counties = $db->all('SELECT id, name FROM counties ORDER BY name');
$withStation = array_flip(array_map('intval', array_column($db->all('SELECT DISTINCT county_id FROM pickup_points WHERE is_active = 1'), 'county_id')));
$wholeCounty = array_flip(array_map('intval', array_column($db->all(
    "SELECT DISTINCT l.county_id FROM delivery_zone_locations l JOIN delivery_zones z ON z.id = l.zone_id WHERE z.is_active = 1 AND l.town = ''"
), 'county_id')));

$stations = [];
$zoneCounties = [];
foreach ($counties as $c) {
    $id = (int) $c['id'];
    $towns = KenyaTowns::forCounty((string) $c['name']);
    if (!isset($withStation[$id]) && $towns) {
        $stations[] = [$id, (string) $c['name'], $towns[0]];
    }
    if (!isset($wholeCounty[$id])) {
        $zoneCounties[] = [$id, (string) $c['name']];
    }
}

$code = function (string $county, string $town) use ($db): string {
    $part = fn (string $s) => strtoupper(substr((string) preg_replace('/[^A-Za-z]/', '', $s), 0, 3));
    for ($n = 1; ; $n++) {
        $candidate = sprintf('%s-%s-%02d', $part($county), $part($town), $n);
        if (!$db->value('SELECT 1 FROM pickup_points WHERE code = ?', [$candidate])) {
            return $candidate;
        }
    }
};

echo ($dry ? '[dry run] ' : '') . count($stations) . " pickup station(s) to add; " . count($zoneCounties) . " county(ies) to add to the Rest of Kenya delivery zone.\n";
if ($dry) {
    foreach ($stations as [, $county, $town]) {
        echo "  station: $town ($county)\n";
    }
    echo '  zone: ' . implode(', ', array_column($zoneCounties, 1)) . "\n";
    exit(0);
}

$db->transaction(function () use ($db, $stations, $zoneCounties, $code) {
    foreach ($stations as [$countyId, $county, $town]) {
        $id = $db->insert('pickup_points', [
            'code' => $code($county, $town),
            'name' => brand_name() . ' Pickup — ' . $town,
            'county_id' => $countyId,
            'town' => $town,
            'address' => "$town town centre (exact location to be confirmed)",
            'opening_hours' => 'Mon–Sat 8:00–18:00',
            'instructions' => 'Wait for our ready-for-collection message before you come. Bring your collection code and ID.',
            'pickup_fee' => '0.00',
            'cod_allowed' => 1,
            'is_active' => 1,
        ]);
        AuditLogger::log('pickup_point.created', 'pickup_point', $id, null, ['town' => $town, 'county' => $county, 'source' => 'bin/seed-kenya-coverage.php'], null);
    }
    if ($zoneCounties) {
        $zone = $db->value("SELECT id FROM delivery_zones WHERE name = 'Rest of Kenya'");
        if (!$zone) {
            $zone = $db->insert('delivery_zones', [
                'name' => 'Rest of Kenya', 'description' => 'Every county not covered by another zone',
                'fee' => '599.00', 'free_delivery_threshold' => null, 'min_days' => 3, 'max_days' => 7,
                'cod_allowed' => 1, 'is_active' => 1, 'sort_order' => 100,
            ]);
        }
        foreach ($zoneCounties as [$countyId]) {
            $db->insert('delivery_zone_locations', ['zone_id' => (int) $zone, 'county_id' => $countyId, 'town' => '']);
        }
        AuditLogger::log('delivery_zone.updated', 'delivery_zone', (int) $zone, null,
            ['added_counties' => array_column($zoneCounties, 1), 'source' => 'bin/seed-kenya-coverage.php'], null);
    }
});
echo count($stations) . " pickup station(s) added; Rest of Kenya zone covers " . count($zoneCounties) . " more county(ies).\n";
echo "Next: replace the placeholder addresses in Admin → Fulfilment → Pickup points, and check the Rest of Kenya fee in Delivery zones.\n";
