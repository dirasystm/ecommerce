<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Database;
use Waimart\Core\Money;
use Waimart\Core\ValidationException;
use Waimart\Core\Validator;

/**
 * Admin management of delivery zones (B2) and pickup points (B3). Changes never alter existing
 * orders: orders keep their own snapshot of the zone / pickup point at the time of purchase.
 */
final class FulfilmentAdminService
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? App::db();
    }

    // ------------------------------------------------------------------ delivery zones

    /** @param array<string,mixed> $in */
    public function saveZone(?int $id, array $in, int $actorId): int
    {
        Validator::validate($in, [
            'name' => 'required|max:100',
            'description' => 'nullable|max:255',
            'fee' => 'required|decimal',
            'free_delivery_threshold' => 'nullable|decimal',
            'min_days' => 'required|int|gte:0|lte:60',
            'max_days' => 'required|int|gte:0|lte:60',
            'sort_order' => 'nullable|int',
        ], ['free_delivery_threshold' => 'Free-delivery threshold', 'min_days' => 'Minimum days', 'max_days' => 'Maximum days']);
        if ((int) $in['max_days'] < (int) $in['min_days']) {
            throw new ValidationException(['max_days' => 'Maximum days must be at least the minimum days.']);
        }
        $name = trim((string) $in['name']);
        if ($this->db->value('SELECT id FROM delivery_zones WHERE name = ? AND id <> ?', [$name, $id ?? 0])) {
            throw new ValidationException(['name' => 'A zone with this name already exists.']);
        }
        $data = [
            'name' => $name,
            'description' => trim((string) ($in['description'] ?? '')) ?: null,
            'fee' => Money::normalize((string) $in['fee']),
            'free_delivery_threshold' => ($in['free_delivery_threshold'] ?? '') === '' ? null : Money::normalize((string) $in['free_delivery_threshold']),
            'min_days' => (int) $in['min_days'],
            'max_days' => (int) $in['max_days'],
            'cod_allowed' => !empty($in['cod_allowed']) ? 1 : 0,
            'is_active' => !empty($in['is_active']) ? 1 : 0,
            'sort_order' => (int) ($in['sort_order'] ?? 0),
        ];
        return $this->upsert('delivery_zones', $id, $data, 'delivery_zone', $actorId);
    }

    /** Adds a county-wide rule (town blank) or a town-specific rule to a zone. */
    public function addZoneLocation(int $zoneId, array $in, int $actorId): int
    {
        Validator::validate($in, ['county_id' => 'required|int', 'town' => 'nullable|max:100'], ['county_id' => 'County']);
        if (!$this->db->value('SELECT id FROM delivery_zones WHERE id = ?', [$zoneId])) {
            throw ValidationException::withMessage('Zone not found.');
        }
        $county = $this->db->one('SELECT id, name FROM counties WHERE id = ?', [(int) $in['county_id']])
            ?? throw new ValidationException(['county_id' => 'Select a county.']);
        $town = trim(preg_replace('/\s+/', ' ', (string) ($in['town'] ?? '')) ?? '');
        $clash = $this->db->one(
            'SELECT z.name FROM delivery_zone_locations l JOIN delivery_zones z ON z.id = l.zone_id WHERE l.county_id = ? AND l.town = ?',
            [$county['id'], $town]
        );
        if ($clash !== null) {
            throw new ValidationException(['town' => sprintf('%s is already covered by the "%s" zone.', $town !== '' ? "$town, {$county['name']}" : "All of {$county['name']}", $clash['name'])]);
        }
        $id = $this->db->insert('delivery_zone_locations', ['zone_id' => $zoneId, 'county_id' => $county['id'], 'town' => $town]);
        AuditLogger::log('fulfilment.zone_location_added', 'delivery_zone', $zoneId, null, ['county' => $county['name'], 'town' => $town], $actorId);
        return $id;
    }

    public function removeZoneLocation(int $zoneId, int $locationId, int $actorId): void
    {
        $loc = $this->db->one('SELECT * FROM delivery_zone_locations WHERE id = ? AND zone_id = ?', [$locationId, $zoneId])
            ?? throw ValidationException::withMessage('Location not found.');
        $this->db->exec('DELETE FROM delivery_zone_locations WHERE id = ?', [$locationId]);
        AuditLogger::log('fulfilment.zone_location_removed', 'delivery_zone', $zoneId, $loc, null, $actorId);
    }

    // ------------------------------------------------------------------ pickup points

    /** @param array<string,mixed> $in */
    public function savePickupPoint(?int $id, array $in, int $actorId): int
    {
        Validator::validate($in, [
            'code' => 'required|max:20',
            'name' => 'required|max:120',
            'county_id' => 'required|int',
            'town' => 'required|max:100',
            'address' => 'required|max:255',
            'latitude' => 'nullable|max:20',
            'longitude' => 'nullable|max:20',
            'map_url' => 'nullable|url|max:500',
            'phone' => 'nullable|phone',
            'email' => 'nullable|email',
            'opening_hours' => 'required|max:500',
            'instructions' => 'nullable|max:1000',
            'pickup_fee' => 'required|decimal',
            'max_order_value' => 'nullable|decimal',
            'daily_capacity' => 'nullable|int|gte:1|lte:10000',
        ], ['county_id' => 'County', 'map_url' => 'Map link', 'max_order_value' => 'Maximum order value', 'daily_capacity' => 'Daily capacity']);

        $code = strtoupper(trim((string) $in['code']));
        if (!preg_match('/^[A-Z0-9][A-Z0-9-]{1,19}$/', $code)) {
            throw new ValidationException(['code' => 'Use letters, numbers and dashes, e.g. NBO-CBD-01.']);
        }
        if ($this->db->value('SELECT id FROM pickup_points WHERE code = ? AND id <> ?', [$code, $id ?? 0])) {
            throw new ValidationException(['code' => 'This code is already used by another pickup point.']);
        }
        if (!$this->db->value('SELECT id FROM counties WHERE id = ?', [(int) $in['county_id']])) {
            throw new ValidationException(['county_id' => 'Select a county.']);
        }
        $lat = self::coordinate($in['latitude'] ?? '', -90, 90, 'latitude');
        $lng = self::coordinate($in['longitude'] ?? '', -180, 180, 'longitude');

        $data = [
            'code' => $code,
            'name' => trim((string) $in['name']),
            'county_id' => (int) $in['county_id'],
            'town' => trim((string) $in['town']),
            'address' => trim((string) $in['address']),
            'latitude' => $lat,
            'longitude' => $lng,
            'map_url' => trim((string) ($in['map_url'] ?? '')) ?: null,
            'phone' => ($p = trim((string) ($in['phone'] ?? ''))) !== '' ? Validator::normalizePhone($p) : null,
            'email' => strtolower(trim((string) ($in['email'] ?? ''))) ?: null,
            'opening_hours' => trim((string) $in['opening_hours']),
            'instructions' => trim((string) ($in['instructions'] ?? '')) ?: null,
            'pickup_fee' => Money::normalize((string) $in['pickup_fee']),
            'cod_allowed' => !empty($in['cod_allowed']) ? 1 : 0,
            'max_order_value' => ($in['max_order_value'] ?? '') === '' ? null : Money::normalize((string) $in['max_order_value']),
            'daily_capacity' => ($in['daily_capacity'] ?? '') === '' ? null : (int) $in['daily_capacity'],
            'is_active' => !empty($in['is_active']) ? 1 : 0,
        ];
        return $this->upsert('pickup_points', $id, $data, 'pickup_point', $actorId);
    }

    /** @return int number of open orders still assigned to this point (informational) */
    public function setPickupActive(int $id, bool $active, int $actorId): int
    {
        $p = $this->db->one('SELECT * FROM pickup_points WHERE id = ?', [$id]) ?? throw ValidationException::withMessage('Pickup point not found.');
        $this->db->exec('UPDATE pickup_points SET is_active = ? WHERE id = ?', [$active ? 1 : 0, $id]);
        AuditLogger::log($active ? 'fulfilment.pickup_activated' : 'fulfilment.pickup_deactivated', 'pickup_point', $id,
            ['is_active' => (int) $p['is_active']], ['is_active' => $active ? 1 : 0], $actorId);
        return $this->openOrdersAt($id);
    }

    public function openOrdersAt(int $pointId): int
    {
        return (int) $this->db->value(
            "SELECT COUNT(*) FROM order_fulfilment f JOIN orders o ON o.id = f.order_id
              WHERE f.pickup_point_id = ? AND o.status NOT IN ('collected','cancelled','expired','returned')",
            [$pointId]
        );
    }

    private static function coordinate(mixed $v, int $min, int $max, string $field): ?string
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        if (!preg_match('/^-?\d{1,3}(\.\d{1,7})?$/', $v) || (float) $v < $min || (float) $v > $max) {
            throw new ValidationException([$field => ucfirst($field) . " must be a number between $min and $max."]);
        }
        return $v;
    }

    /** @param array<string,mixed> $data */
    private function upsert(string $table, ?int $id, array $data, string $entity, int $actorId): int
    {
        return $this->db->transaction(function () use ($table, $id, $data, $entity, $actorId) {
            if ($id === null) {
                $id = $this->db->insert($table, $data);
                AuditLogger::log("fulfilment.{$entity}_created", $entity, $id, null, $data, $actorId);
                return $id;
            }
            $before = $this->db->one("SELECT * FROM `$table` WHERE id = ?", [$id]) ?? throw ValidationException::withMessage('Not found.');
            $this->db->exec(
                "UPDATE `$table` SET " . implode(', ', array_map(fn ($c) => "`$c` = ?", array_keys($data))) . ' WHERE id = ?',
                [...array_values($data), $id]
            );
            AuditLogger::log("fulfilment.{$entity}_updated", $entity, $id, array_intersect_key($before, $data), $data, $actorId);
            return $id;
        });
    }
}
