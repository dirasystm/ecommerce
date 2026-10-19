<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Database;
use Waimart\Core\Money;
use Waimart\Core\Settings;

/**
 * Fulfilment options and charges, always computed on the server (B1, B2, B3).
 * Zones and pickup points are managed in Admin → Fulfilment; the switches, store-wide free delivery and checkout notes
 * in Delivery settings. This is the read side.
 */
final class DeliveryService
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? App::db();
    }

    /** Whether a fulfilment method is switched on (Fulfilment → Delivery settings). */
    public static function methodEnabled(string $method): bool
    {
        return Settings::bool($method === 'delivery' ? 'delivery_home_enabled' : 'delivery_pickup_enabled', true);
    }

    public function deliveryAvailable(): bool
    {
        return self::methodEnabled('delivery') && (bool) $this->db->value(
            'SELECT 1 FROM delivery_zones z JOIN delivery_zone_locations l ON l.zone_id = z.id WHERE z.is_active = 1 LIMIT 1'
        );
    }

    public function pickupAvailable(): bool
    {
        return self::methodEnabled('pickup') && (bool) $this->db->value('SELECT 1 FROM pickup_points WHERE is_active = 1 LIMIT 1');
    }

    /**
     * Delivery quote for a location. A town-specific rule beats a county-wide rule. Free delivery applies from the
     * zone's own amount, or else from the store-wide amount (Delivery settings), if either is set.
     * Returns null when the location is outside every active zone.
     *
     * @return array{zone_id:int,zone_name:string,fee:string,free_threshold:?string,is_free:bool,eta_min:int,eta_max:int,cod_allowed:bool}|null
     */
    public function quoteDelivery(int $countyId, string $town, string $subtotal): ?array
    {
        $zone = $this->db->one(
            "SELECT z.* FROM delivery_zone_locations l JOIN delivery_zones z ON z.id = l.zone_id
              WHERE z.is_active = 1 AND l.county_id = ? AND (l.town = '' OR LOWER(l.town) = LOWER(?))
              ORDER BY (l.town = '') ASC LIMIT 1",
            [$countyId, trim($town)]
        );
        if ($zone === null) {
            return null;
        }
        $storeWide = trim((string) Settings::get('free_delivery_threshold', ''));
        $threshold = $zone['free_delivery_threshold'] !== null ? (string) $zone['free_delivery_threshold'] : ($storeWide !== '' ? $storeWide : null);
        $isFree = $threshold !== null && Money::cmp($subtotal, $threshold) >= 0;
        return [
            'zone_id' => (int) $zone['id'],
            'zone_name' => (string) $zone['name'],
            'fee' => $isFree ? '0.00' : Money::normalize((string) $zone['fee']),
            'free_threshold' => $threshold,
            'is_free' => $isFree,
            'eta_min' => (int) $zone['min_days'],
            'eta_max' => (int) $zone['max_days'],
            'cod_allowed' => (int) $zone['cod_allowed'] === 1,
        ];
    }

    /** @return list<array<string,mixed>> active pickup points, optionally for one county */
    public function pickupPoints(?int $countyId = null): array
    {
        $sql = 'SELECT p.*, c.name AS county_name FROM pickup_points p JOIN counties c ON c.id = p.county_id WHERE p.is_active = 1';
        $params = [];
        if ($countyId !== null) {
            $sql .= ' AND p.county_id = ?';
            $params[] = $countyId;
        }
        return $this->db->all($sql . ' ORDER BY c.name, p.town, p.name', $params);
    }

    /**
     * Pickup quote. Null when the point is inactive/unknown; 'error' set when a restriction applies.
     * @return array{point:array<string,mixed>,fee:string,cod_allowed:bool,error:?string}|null
     */
    public function quotePickup(int $pointId, string $subtotal): ?array
    {
        $p = $this->db->one(
            'SELECT p.*, c.name AS county_name FROM pickup_points p JOIN counties c ON c.id = p.county_id WHERE p.id = ? AND p.is_active = 1',
            [$pointId]
        );
        if ($p === null) {
            return null;
        }
        $error = null;
        if ($p['max_order_value'] !== null && Money::cmp($subtotal, (string) $p['max_order_value']) > 0) {
            $error = 'This pickup station accepts orders up to ' . Money::format((string) $p['max_order_value']) . '. Choose another station or home delivery.';
        } elseif ($p['daily_capacity'] !== null) {
            $today = (int) $this->db->value(
                "SELECT COUNT(*) FROM order_fulfilment f JOIN orders o ON o.id = f.order_id
                  WHERE f.pickup_point_id = ? AND o.placed_at >= CURDATE() AND o.status NOT IN ('cancelled', 'expired')",
                [$pointId]
            );
            if ($today >= (int) $p['daily_capacity']) {
                $error = 'This pickup station is fully booked for today. Please choose another station.';
            }
        }
        return [
            'point' => $p,
            'fee' => Money::normalize((string) $p['pickup_fee']),
            'cod_allowed' => (int) $p['cod_allowed'] === 1,
            'error' => $error,
        ];
    }

    /** "We do not deliver here" help: the open pickup stations in the same county, if any (else ''). */
    public function pickupHint(int $countyId): string
    {
        $names = array_column($this->pickupPoints($countyId), 'name');
        if (!$names) {
            return '';
        }
        $list = count($names) > 3 ? implode(', ', array_slice($names, 0, 3)) . ' and ' . (count($names) - 3) . ' more' : implode(', ', $names);
        return ' You can collect your order from ' . (count($names) === 1 ? 'our pickup station' : 'our pickup stations') . " in this county ($list): choose “Pickup station” above.";
    }

    /**
     * Town suggestions for a county (checkout autocomplete): its main towns (KenyaTowns), plus any town named in a
     * delivery zone or at an open pickup station. @return list<string>
     */
    public function towns(int $countyId): array
    {
        $rows = $this->db->all(
            "SELECT town FROM delivery_zone_locations WHERE county_id = ? AND town <> ''
             UNION SELECT town FROM pickup_points WHERE county_id = ? AND is_active = 1",
            [$countyId, $countyId]
        );
        $county = (string) $this->db->value('SELECT name FROM counties WHERE id = ?', [$countyId]);
        $towns = array_values(array_unique(array_merge(KenyaTowns::forCounty($county), array_column($rows, 'town'))));
        natcasesort($towns);
        return array_values($towns);
    }

    /** @return list<array{id:int,name:string}> */
    public function counties(): array
    {
        return array_map(fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name']], $this->db->all('SELECT id, name FROM counties ORDER BY name'));
    }
}
