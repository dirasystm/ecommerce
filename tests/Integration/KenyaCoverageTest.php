<?php

declare(strict_types=1);

namespace Waimart\Tests\Integration;

use Waimart\Core\ValidationException;
use Waimart\Services\CartService;
use Waimart\Services\CheckoutService;
use Waimart\Services\DeliveryService;
use Waimart\Services\KenyaTowns;

/**
 * Nationwide fulfilment: bin/seed-kenya-coverage.php (a station per county, a Rest of Kenya zone), town suggestions,
 * and the "we do not deliver there" message pointing to the county's pickup stations.
 */
final class KenyaCoverageTest extends DbTestCase
{
    private function runScript(string ...$args): string
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/bin/seed-kenya-coverage.php') . ' ' . implode(' ', array_map('escapeshellarg', $args));
        $env = getenv();
        $env['WAIMART_ENV'] = 'testing';
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        proc_close($p);
        return $out;
    }

    private function input(array $o = []): array
    {
        return $o + ['customer_name' => 'Wanjiru', 'customer_phone' => '0722000111', 'customer_email' => '', 'fulfilment_method' => 'delivery',
                     'county_id' => '31', 'town' => 'Nanyuki', 'location' => 'Kenyatta Avenue', 'payment_method' => 'cod'];
    }

    public function testEveryCountyIsListedWithTowns(): void
    {
        $names = array_column($this->db->all('SELECT name FROM counties'), 'name');
        sort($names);
        $listed = array_keys(KenyaTowns::BY_COUNTY);
        sort($listed);
        $this->assertSame($names, $listed, 'town lists use the exact county names');
        $towns = (new DeliveryService($this->db))->towns(31);
        $this->assertContains('Nanyuki', $towns);
        $this->assertContains('Nyahururu', $towns);
    }

    public function testCoverageScriptReachesTheWholeCountryOnce(): void
    {
        $cart = new CartService(null, null, $this->db);
        $cart->add($this->makeVariant(10, '1500.00')['variant_id'], 1);
        try {
            (new CheckoutService($this->db))->prepare($cart, $this->input(), null);
            $this->fail('no zone covers Laikipia yet');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Please choose a pickup station or a different address.', $e->errors['town'], 'no station in the county yet');
        }

        $this->assertStringContainsString('[dry run] 47 pickup station(s) to add; 47 county(ies)', $this->runScript('--dry-run'));
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM pickup_points'), 'a dry run changes nothing');
        $this->assertStringContainsString('47 pickup station(s) added', $this->runScript());
        $this->assertSame(47, (int) $this->db->value('SELECT COUNT(DISTINCT county_id) FROM pickup_points WHERE is_active = 1'));
        $this->assertSame(47, (int) $this->db->value("SELECT COUNT(*) FROM delivery_zone_locations l JOIN delivery_zones z ON z.id = l.zone_id WHERE z.name = 'Rest of Kenya' AND l.town = ''"));
        $nanyuki = $this->db->one("SELECT * FROM pickup_points WHERE town = 'Nanyuki'");
        $this->assertSame('Basemall Pickup — Nanyuki', $nanyuki['name']);
        $this->assertStringContainsString('to be confirmed', $nanyuki['address'], 'placeholder addresses say so');
        $this->assertStringContainsString('0 pickup station(s) to add; 0 county(ies)', $this->runScript('--dry-run'), 'running again adds nothing');

        // Home delivery to Nanyuki now works, priced by the Rest of Kenya zone; pickup at the Nanyuki station works too.
        $draft = (new CheckoutService($this->db))->prepare($cart, $this->input(), null);
        $this->assertSame(['599.00', 'Rest of Kenya'], [$draft['delivery_fee'], $draft['fulfilment']['zone_name']]);
        $draft = (new CheckoutService($this->db))->prepare($cart, $this->input(['fulfilment_method' => 'pickup', 'pickup_point_id' => (string) $nanyuki['id']]), null);
        $this->assertSame('Nanyuki', $draft['fulfilment']['pickup_town']);
    }

    public function testUndeliverablePlacePointsToTheCountysStations(): void
    {
        $this->db->insert('pickup_points', ['code' => 'LKP-NYK-01', 'name' => 'Basemall Pickup — Nanyuki', 'county_id' => 31, 'town' => 'Nanyuki',
            'address' => 'Kenyatta Avenue', 'opening_hours' => '8-6']);
        $cart = new CartService(null, null, $this->db);
        $cart->add($this->makeVariant(10, '1500.00')['variant_id'], 1);
        try {
            (new CheckoutService($this->db))->prepare($cart, $this->input(), null);
            $this->fail('no zone covers Laikipia');
        } catch (ValidationException $e) {
            $this->assertSame('Sorry, we do not deliver to Nanyuki, Laikipia yet. You can collect your order from our pickup station in this county (Basemall Pickup — Nanyuki): choose “Pickup station” above.', $e->errors['town']);
        }
    }
}
