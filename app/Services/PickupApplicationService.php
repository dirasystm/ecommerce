<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Database;
use Waimart\Core\Logger;
use Waimart\Core\RateLimiter;
use Waimart\Core\Settings;
use Waimart\Core\ValidationException;
use Waimart\Core\Validator;

/**
 * "Become a pickup station": businesses apply, a super admin (pickup_points.approve) verifies the details and approves
 * or rejects. Approval creates an ordinary pickup point (FulfilmentAdminService), which is then offered at checkout.
 * The applicant gets a reference, can check the status with it, and is told every decision by email and SMS.
 */
final class PickupApplicationService
{
    public const BUSINESS_TYPES = [
        'retail_shop' => 'Retail shop', 'supermarket' => 'Supermarket or mini-mart', 'pharmacy' => 'Pharmacy or chemist',
        'mpesa_agent' => 'M-Pesa or bank agent', 'electronics' => 'Electronics or phone shop', 'courier' => 'Courier or parcel office',
        'petrol_station' => 'Petrol station', 'hardware' => 'Hardware', 'stationery' => 'Bookshop or stationery', 'other' => 'Other business',
    ];
    public const STORAGE = ['small' => 'Small: up to 20 parcels', 'medium' => 'Medium: 20–60 parcels', 'large' => 'Large: more than 60 parcels'];
    public const STATUSES = ['pending' => 'Being reviewed', 'approved' => 'Approved', 'rejected' => 'Not approved'];
    private const DAYS = ['mon' => 'Mon', 'tue' => 'Tue', 'wed' => 'Wed', 'thu' => 'Thu', 'fri' => 'Fri', 'sat' => 'Sat', 'sun' => 'Sun'];

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? App::db();
    }

    /**
     * Records an application from the public form.
     * @param array<string,mixed> $in @param array<string,mixed>|null $photo one $_FILES entry (optional)
     * @return array<string,mixed> the stored application
     */
    public function apply(array $in, ?array $photo, string $ip): array
    {
        if (trim((string) ($in['website'] ?? '')) !== '') {
            throw ValidationException::withMessage('Your application could not be sent. Please try again.');   // honeypot
        }
        if (!(new RateLimiter($this->db))->attempt('pickup-apply|' . $ip, 5, 3600)) {
            throw ValidationException::withMessage('Too many applications from your network. Please try again in an hour.');
        }
        $errors = Validator::check($in, [
            'business_name' => 'required|max:120',
            'business_type' => 'required|in:' . implode(',', array_keys(self::BUSINESS_TYPES)),
            'registration_number' => 'nullable|max:60',
            'contact_name' => 'required|max:120',
            'phone' => 'required|phone',
            'email' => 'required|email',
            'county_id' => 'required|int',
            'town' => 'required|max:100',
            'address' => 'required|max:255',
            'landmark' => 'nullable|max:255',
            'map_url' => 'nullable|url|max:500',
            'storage_space' => 'required|in:' . implode(',', array_keys(self::STORAGE)),
            'daily_capacity' => 'nullable|int|gte:1|lte:1000',
            'notes' => 'nullable|max:1000',
        ], ['business_name' => 'Business name', 'business_type' => 'Type of business', 'contact_name' => 'Contact person', 'county_id' => 'County',
            'map_url' => 'Map link', 'storage_space' => 'Storage space', 'daily_capacity' => 'Parcels per day']);
        if (!isset($errors['county_id']) && !$this->db->value('SELECT id FROM counties WHERE id = ?', [(int) ($in['county_id'] ?? 0)])) {
            $errors['county_id'] = 'Choose your county.';
        }
        if (empty($in['secure_storage'])) {
            $errors['secure_storage'] = 'A pickup station needs a lockable place to keep parcels.';
        }
        if (empty($in['accept_terms'])) {
            $errors['accept_terms'] = 'Please confirm the details are correct and that we may contact you to verify them.';
        }
        $hours = null;
        try {
            $hours = self::hoursText((array) ($in['hours'] ?? []));
        } catch (ValidationException $e) {
            $errors += $e->errors;
        }
        [$lat, $lng] = self::coordinates((string) ($in['latitude'] ?? ''), (string) ($in['longitude'] ?? ''), (string) ($in['map_url'] ?? ''));
        $phone = (string) Validator::normalizePhone((string) ($in['phone'] ?? ''));
        if (!$errors && $this->db->value("SELECT id FROM pickup_station_applications WHERE phone = ? AND status = 'pending'", [$phone])) {
            $errors['phone'] = 'There is already an application being reviewed for this phone number. You can check its status with your reference.';
        }
        if ($errors) {
            throw new ValidationException($errors);
        }

        $stored = null;
        if (is_array($photo) && ($photo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $stored = (new SellerDocumentStore())->store($photo);
            } catch (ValidationException $e) {
                throw new ValidationException(['photo' => (string) (reset($e->errors) ?: 'The photo could not be used.')]);
            }
            if ($stored['mime_type'] === 'application/pdf') {
                (new SellerDocumentStore())->delete($stored['stored_name']);
                throw new ValidationException(['photo' => 'Upload a photo (JPEG or PNG) of the shop front.']);
            }
        }

        $ref = $this->newReference();
        $id = $this->db->insert('pickup_station_applications', [
            'reference' => $ref, 'status' => 'pending',
            'business_name' => trim((string) $in['business_name']), 'business_type' => (string) $in['business_type'],
            'registration_number' => trim((string) ($in['registration_number'] ?? '')) ?: null,
            'contact_name' => trim((string) $in['contact_name']), 'phone' => $phone, 'email' => strtolower(trim((string) $in['email'])),
            'county_id' => (int) $in['county_id'], 'town' => trim((string) $in['town']), 'address' => trim((string) $in['address']),
            'landmark' => trim((string) ($in['landmark'] ?? '')) ?: null,
            'latitude' => $lat, 'longitude' => $lng, 'map_url' => trim((string) ($in['map_url'] ?? '')) ?: null,
            'opening_hours' => $hours, 'storage_space' => (string) $in['storage_space'],
            'daily_capacity' => ($in['daily_capacity'] ?? '') === '' ? null : (int) $in['daily_capacity'],
            'secure_storage' => 1, 'accepts_cod' => !empty($in['accepts_cod']) ? 1 : 0,
            'notes' => trim((string) ($in['notes'] ?? '')) ?: null,
            'photo_stored_name' => $stored['stored_name'] ?? null, 'photo_mime' => $stored['mime_type'] ?? null,
            'ip_address' => $ip,
        ]);
        AuditLogger::log('pickup_application.submitted', 'pickup_application', $id, null, ['reference' => $ref], null);
        $app = $this->find($id);
        $this->notifyApplicant('pickup_application_received', $app, ['status_url' => self::siteUrl('/become-a-pickup-station/check')]);
        $this->notifyReviewers($app);
        return $app;
    }

    /** Status check by reference + phone (the same answer for a wrong reference or phone). */
    public function lookup(string $reference, string $phone, string $ip): ?array
    {
        if (!(new RateLimiter($this->db))->attempt('pickup-status|' . $ip, 20, 900)) {
            throw ValidationException::withMessage('Too many attempts. Please wait 15 minutes and try again.');
        }
        $phone = Validator::normalizePhone($phone);
        $app = $this->db->one('SELECT * FROM pickup_station_applications WHERE reference = ?', [strtoupper(trim($reference))]);
        return $app !== null && $phone !== null && hash_equals((string) $app['phone'], $phone) ? $app : null;
    }

    /** @return array<string,mixed>|null with county name and reviewer */
    public function find(int $id): ?array
    {
        return $this->db->one(
            "SELECT a.*, c.name AS county_name, CONCAT(u.first_name, ' ', u.last_name) AS reviewer, p.code AS station_code, p.name AS station_name
               FROM pickup_station_applications a JOIN counties c ON c.id = a.county_id
               LEFT JOIN users u ON u.id = a.reviewed_by LEFT JOIN pickup_points p ON p.id = a.pickup_point_id
              WHERE a.id = ?", [$id]);
    }

    /** @return list<array<string,mixed>> */
    /** One page of applications (see list()). @return list<array<string,mixed>> */
    public function page(string $status, \Waimart\Core\ListParams $lp): array
    {
        $where = ['1=1'];
        $params = [];
        if (isset(self::STATUSES[$status])) {
            $where[] = 'a.status = ?';
            $params[] = $status;
        }
        if ($lp->q !== '') {
            $where[] = '(a.reference LIKE ? OR a.business_name LIKE ? OR a.town LIKE ? OR c.name LIKE ? OR a.contact_name LIKE ? OR a.phone LIKE ?)';
            array_push($params, ...array_fill(0, 6, $lp->like()));
        }
        return $lp->fetch($this->db, 'SELECT a.*, c.name AS county_name', 'FROM pickup_station_applications a JOIN counties c ON c.id = a.county_id WHERE ' . implode(' AND ', $where), $params, 'a.id DESC');
    }

    public function list(string $status = 'pending', string $search = ''): array
    {
        $where = ['1=1'];
        $params = [];
        if (isset(self::STATUSES[$status])) {
            $where[] = 'a.status = ?';
            $params[] = $status;
        }
        if (($search = trim(mb_substr($search, 0, 100))) !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $where[] = '(a.reference LIKE ? OR a.business_name LIKE ? OR a.town LIKE ? OR c.name LIKE ? OR a.contact_name LIKE ? OR a.phone LIKE ?)';
            array_push($params, ...array_fill(0, 6, $like));
        }
        return $this->db->all('SELECT a.*, c.name AS county_name FROM pickup_station_applications a JOIN counties c ON c.id = a.county_id WHERE '
            . implode(' AND ', $where) . ' ORDER BY a.status = \'pending\' DESC, a.created_at DESC LIMIT 200', $params);
    }

    /** @return array<string,int> */
    public function counts(): array
    {
        $out = array_fill_keys(array_keys(self::STATUSES), 0);
        foreach ($this->db->all('SELECT status, COUNT(*) AS n FROM pickup_station_applications GROUP BY status') as $r) {
            $out[$r['status']] = (int) $r['n'];
        }
        return $out;
    }

    /**
     * Approves: creates the pickup point from the (possibly corrected) station details and tells the business.
     * @param array<string,mixed> $station fields for FulfilmentAdminService::savePickupPoint
     */
    public function approve(int $id, array $station, int $actorId): int
    {
        $pointId = $this->db->transaction(function () use ($id, $station, $actorId) {
            $app = $this->db->one('SELECT * FROM pickup_station_applications WHERE id = ? FOR UPDATE', [$id]) ?? throw ValidationException::withMessage('Application not found.');
            if ($app['status'] !== 'pending') {
                throw ValidationException::withMessage('This application has already been decided.');
            }
            $station['is_active'] = '1';
            $pointId = (new FulfilmentAdminService($this->db))->savePickupPoint(null, $station, $actorId);
            $this->db->exec("UPDATE pickup_station_applications SET status = 'approved', pickup_point_id = ?, reviewed_by = ?, reviewed_at = NOW(), decision_note = ? WHERE id = ?",
                [$pointId, $actorId, trim((string) ($station['decision_note'] ?? '')) ?: null, $id]);
            AuditLogger::log('pickup_application.approved', 'pickup_application', $id, ['status' => 'pending'], ['status' => 'approved', 'pickup_point_id' => $pointId], $actorId);
            return $pointId;
        });
        $app = (array) $this->find($id);
        $this->notifyApplicant('pickup_application_approved', $app, ['station_name' => (string) $app['station_name'], 'station_code' => (string) $app['station_code']]);
        return $pointId;
    }

    public function reject(int $id, string $reason, int $actorId): void
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 1000) {
            throw new ValidationException(['reason' => 'Explain the reason in a sentence (10 to 1,000 characters). The business will see it.']);
        }
        $this->db->transaction(function () use ($id, $reason, $actorId) {
            $app = $this->db->one('SELECT status FROM pickup_station_applications WHERE id = ? FOR UPDATE', [$id]) ?? throw ValidationException::withMessage('Application not found.');
            if ($app['status'] !== 'pending') {
                throw ValidationException::withMessage('This application has already been decided.');
            }
            $this->db->exec("UPDATE pickup_station_applications SET status = 'rejected', decision_note = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?", [$reason, $actorId, $id]);
            AuditLogger::log('pickup_application.rejected', 'pickup_application', $id, ['status' => 'pending'], ['status' => 'rejected'], $actorId);
        });
        $this->notifyApplicant('pickup_application_rejected', (array) $this->find($id), ['reason' => $reason]);
    }

    /**
     * Station details suggested for approval: the application's details plus a free station code in the
     * existing style (county–town–number, e.g. LKP-NYK-01).
     * @param array<string,mixed> $app
     * @return array<string,string>
     */
    public function suggestedStation(array $app): array
    {
        $prefix = self::abbr((string) $app['county_name']) . '-' . self::abbr((string) $app['town']);
        $n = 1;
        while ($this->db->value('SELECT id FROM pickup_points WHERE code = ?', [sprintf('%s-%02d', $prefix, $n)]) && $n < 99) {
            $n++;
        }
        $instructions = trim('Ask for ' . $app['contact_name'] . ' at ' . $app['business_name'] . '.' . ($app['landmark'] ? ' Landmark: ' . $app['landmark'] . '.' : ''));
        return [
            'code' => sprintf('%s-%02d', $prefix, $n), 'name' => brand_name() . ' Pickup – ' . $app['business_name'],
            'county_id' => (string) $app['county_id'], 'town' => (string) $app['town'], 'address' => (string) $app['address'],
            'latitude' => (string) ($app['latitude'] ?? ''), 'longitude' => (string) ($app['longitude'] ?? ''), 'map_url' => (string) ($app['map_url'] ?? ''),
            'phone' => Validator::displayPhone((string) $app['phone']), 'email' => (string) $app['email'], 'opening_hours' => (string) $app['opening_hours'],
            'instructions' => mb_substr($instructions, 0, 1000), 'pickup_fee' => '0', 'cod_allowed' => $app['accepts_cod'] ? '1' : '',
            'daily_capacity' => (string) ($app['daily_capacity'] ?? ''), 'max_order_value' => '',
        ];
    }

    /**
     * Opening hours from the form (per day: closed, or open–close times) as one readable line, e.g.
     * "Mon–Fri 8:00 am – 6:00 pm; Sat 9:00 am – 4:00 pm; Sun closed".
     * @param array<string,array<string,string>> $hours day => [open, close, closed]
     */
    public static function hoursText(array $hours): string
    {
        $days = [];
        $openDays = 0;
        foreach (self::DAYS as $key => $label) {
            $d = (array) ($hours[$key] ?? []);
            if (!empty($d['closed'])) {
                $days[$key] = 'closed';
                continue;
            }
            $o = (string) ($d['open'] ?? '');
            $c = (string) ($d['close'] ?? '');
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $o) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $c) || $c <= $o) {
                throw new ValidationException(['hours' => "Enter the opening and closing time for $label, or tick Closed."]);
            }
            $days[$key] = self::time12($o) . ' – ' . self::time12($c);
            $openDays++;
        }
        if ($openDays < 5) {
            throw new ValidationException(['hours' => 'A pickup station needs to be open at least 5 days a week.']);
        }
        // Group consecutive days with the same hours.
        $parts = [];
        $keys = array_keys(self::DAYS);
        for ($i = 0; $i < count($keys); $i++) {
            $j = $i;
            while ($j + 1 < count($keys) && $days[$keys[$j + 1]] === $days[$keys[$i]]) {
                $j++;
            }
            $span = self::DAYS[$keys[$i]] . ($j > $i ? '–' . self::DAYS[$keys[$j]] : '');
            $parts[] = $span . ' ' . $days[$keys[$i]];
            $i = $j;
        }
        return implode('; ', $parts);
    }

    /**
     * Coordinates from the "use my location" button, or read from a Google Maps link (…@-0.0221,37.0726… or ?q=lat,lng).
     * @return array{0:?string,1:?string}
     */
    public static function coordinates(string $lat, string $lng, string $mapUrl): array
    {
        if ($lat === '' && $lng === '' && preg_match('/(?:@|[?&](?:q|query|ll)=)(-?\d{1,2}\.\d+),\s*(-?\d{1,3}\.\d+)/', $mapUrl, $m)) {
            [$lat, $lng] = [$m[1], $m[2]];
        }
        if (!is_numeric($lat) || !is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180) {
            return [null, null];
        }
        // Kenya is roughly 5°N–5°S, 33°E–42°E; anything far outside is a mistake, not a station.
        if ((float) $lat < -6 || (float) $lat > 6 || (float) $lng < 32 || (float) $lng > 43) {
            return [null, null];
        }
        return [number_format((float) $lat, 7, '.', ''), number_format((float) $lng, 7, '.', '')];
    }

    private static function time12(string $hhmm): string
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm));
        return sprintf('%d:%02d %s', $h % 12 === 0 ? 12 : $h % 12, $m, $h < 12 ? 'am' : 'pm');
    }

    private static function abbr(string $s): string
    {
        $letters = strtoupper((string) preg_replace('/[^A-Za-z]/', '', $s));
        $consonants = $letters === '' ? '' : (string) preg_replace('/(.)\1+/', '$1', $letters[0] . preg_replace('/[AEIOU]/', '', substr($letters, 1)));
        return str_pad(substr(strlen($consonants) >= 3 ? $consonants : $letters, 0, 3), 3, 'X');
    }

    private function newReference(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $ref = 'PS-';
            for ($i = 0; $i < 8; $i++) {
                $ref .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while ($this->db->value('SELECT id FROM pickup_station_applications WHERE reference = ?', [$ref]));
        return $ref;
    }

    private static function siteUrl(string $path): string
    {
        return rtrim((string) config('app.url'), '/') . $path;
    }

    /** @param array<string,mixed> $app @param array<string,string> $extra */
    private function notifyApplicant(string $template, array $app, array $extra): void
    {
        try {
            $first = explode(' ', trim((string) $app['contact_name']))[0] ?: (string) $app['contact_name'];
            (new NotificationService())->sendTemplate($template,
                ['first_name' => $first, 'reference' => (string) $app['reference'], 'business_name' => (string) $app['business_name']] + $extra + MessageTemplates::contactVars(),
                ['email' => (string) $app['email'], 'phone' => (string) $app['phone'], 'prefs' => null]);
        } catch (\Throwable $e) {
            Logger::error('Pickup application notification failed', ['template' => $template, 'error' => $e->getMessage()]);
        }
    }

    /** Emails everyone who can approve (super admins and anyone given pickup_points.approve). */
    private function notifyReviewers(array $app): void
    {
        try {
            $reviewers = $this->db->all(
                "SELECT DISTINCT u.id, u.email, u.first_name FROM users u
                   LEFT JOIN user_roles ur ON ur.user_id = u.id LEFT JOIN roles r ON r.id = ur.role_id
                   LEFT JOIN role_permissions rp ON rp.role_id = r.id LEFT JOIN user_permissions up ON up.user_id = u.id
                   LEFT JOIN permissions p ON p.id = rp.permission_id OR p.id = up.permission_id
                  WHERE u.is_staff = 1 AND u.status = 'active' AND (r.slug = 'super_admin' OR p.slug = 'pickup_points.approve')"
            );
            foreach ($reviewers as $r) {
                (new NotificationService())->sendTemplate('pickup_application_new',
                    ['first_name' => (string) $r['first_name'], 'reference' => (string) $app['reference'], 'business_name' => (string) $app['business_name'],
                     'town' => (string) $app['town'], 'county' => (string) $app['county_name'],
                     'review_url' => self::siteUrl('/admin/pickup-points/applications/' . $app['id'])] + MessageTemplates::contactVars(),
                    ['email' => (string) $r['email'], 'prefs' => null], ['user_id' => (int) $r['id']]);
            }
        } catch (\Throwable $e) {
            Logger::error('Pickup application reviewer notification failed', ['error' => $e->getMessage()]);
        }
    }
}
