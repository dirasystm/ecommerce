<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Money;
use Waimart\Core\Settings;
use Waimart\Core\ValidationException;
use Waimart\Core\Validator;

/**
 * Payment methods (A4, Basemall Stage 4). The methods the order system can handle are fixed in code (LABELS: manual
 * M-Pesa and cash on delivery); how each is presented and whether it is offered is managed in Admin → Payment methods
 * (`payment_methods`). A method is offered at checkout only when it is switched on, its M-Pesa number is set
 * (Settings, payments group) and the order total is within its limits; cash on delivery also depends on the
 * fulfilment option and the marketplace rule. Checkout re-checks all of this on the server when the order is placed.
 */
final class PaymentOptions
{
    /** Built-in names, used if the table is unavailable. */
    public const LABELS = [
        'mpesa_till'    => 'M-Pesa Buy Goods (Till)',
        'mpesa_paybill' => 'M-Pesa Paybill',
        'mpesa_phone'   => 'M-Pesa Send Money',
        'cod'           => 'Cash on delivery / on collection',
    ];

    /** Settings that hold each M-Pesa method's details; the first is the number the method needs. */
    public const ACCOUNT_SETTINGS = [
        'mpesa_till' => ['mpesa_till_number', 'mpesa_till_name'],
        'mpesa_paybill' => ['mpesa_paybill_number', 'mpesa_paybill_account_hint'],
        'mpesa_phone' => ['mpesa_phone_number', 'mpesa_phone_name'],
        'cod' => [],
    ];

    /** @var array<string,array<string,mixed>>|null */
    private static ?array $cache = null;

    public static function flush(): void
    {
        self::$cache = null;
    }

    /** @return array<string,array<string,mixed>> code => row, in display order */
    public static function all(): array
    {
        if (self::$cache === null) {
            $rows = App::db()->all('SELECT * FROM payment_methods ORDER BY sort_order, code');
            self::$cache = array_column($rows, null, 'code');
        }
        return self::$cache;
    }

    public static function label(string $code): string
    {
        return (string) (self::all()[$code]['name'] ?? self::LABELS[$code] ?? ucfirst(str_replace('_', ' ', $code)));
    }

    /** Whether the details customers need are set (M-Pesa number); cash on delivery needs none. */
    public static function configured(string $code): bool
    {
        $need = self::ACCOUNT_SETTINGS[$code][0] ?? null;
        return $need === null || (string) Settings::get($need, '') !== '';
    }

    /** Switched on and configured (limits and location aside). */
    public static function offered(string $code): bool
    {
        return (int) (self::all()[$code]['is_enabled'] ?? 0) === 1 && self::configured($code);
    }

    /**
     * The methods to show at checkout.
     * @param ?bool $codAllowed false when the location or marketplace rule does not allow COD
     * @param ?string $total order total to check limits against (null: not known yet)
     * @return array<string,array{label:string,description:string,available:bool,reason:?string}>
     */
    public static function forFulfilment(?bool $codAllowed, ?string $codReason = null, ?string $total = null): array
    {
        $out = [];
        foreach (self::all() as $code => $m) {
            if (!isset(self::LABELS[$code]) || !self::offered($code)) {
                continue;
            }
            $reason = null;
            if ($code === 'cod' && $codAllowed === false) {
                $reason = $codReason ?? 'Cash on delivery is not available for the selected location.';
            } elseif ($total !== null) {
                $reason = self::limitReason($m, $total);
            }
            $out[$code] = ['label' => (string) $m['name'], 'description' => (string) $m['description'], 'available' => $reason === null, 'reason' => $reason];
        }
        return $out;
    }

    /** Why the order total is outside a method's limits, or null. @param array<string,mixed> $m */
    public static function limitReason(array $m, string $total): ?string
    {
        if ($m['min_order_total'] !== null && Money::cmp($total, (string) $m['min_order_total']) < 0) {
            return "{$m['name']} is available for orders of " . Money::format((string) $m['min_order_total']) . ' or more.';
        }
        if ($m['max_order_total'] !== null && Money::cmp($total, (string) $m['max_order_total']) > 0) {
            return "{$m['name']} is available for orders up to " . Money::format((string) $m['max_order_total']) . '.';
        }
        return null;
    }

    /** Footer badges: the methods currently offered. @return array{mpesa:bool,names:list<string>} */
    public static function badges(): array
    {
        $names = [];
        $mpesa = false;
        foreach (self::all() as $code => $m) {
            if (isset(self::LABELS[$code]) && self::offered($code)) {
                $names[] = (string) $m['short_name'];
                $mpesa = $mpesa || str_starts_with($code, 'mpesa_');
            }
        }
        return ['mpesa' => $mpesa, 'names' => $names];
    }

    /** The method's own instructions, rendered (content format), or ''. @param array<string,string> $vars */
    public static function instructionsHtml(string $code, array $vars = []): string
    {
        // Blocks ([[…]]) are not available here: [[payment_instructions]] inside a method's instructions would loop.
        $text = trim((string) preg_replace('/\[\[[a-z_]+\]\]/', '', (string) (self::all()[$code]['instructions'] ?? '')));
        return $text === '' ? '' : (new ContentRenderer($vars))->render($text);
    }

    /**
     * Saves one method: presentation, availability, limits and (M-Pesa) its payment details. Everything is validated
     * before anything is written. Payment numbers go through the settings registry, so their changes keep their own
     * audit entry (settings.payment_destination_changed).
     * @param array<string,mixed> $in
     */
    public function save(string $code, array $in, int $actorId): void
    {
        $m = self::all()[$code] ?? throw new \InvalidArgumentException("Unknown payment method $code");
        $errors = Validator::check($in, [
            'name' => 'required|max:80', 'short_name' => 'required|max:30', 'description' => 'nullable|max:255', 'instructions' => 'nullable|max:3000',
            'sort_order' => 'required|int|gte:0|lte:9999', 'min_order_total' => 'nullable|decimal', 'max_order_total' => 'nullable|decimal',
        ], ['name' => 'Name', 'short_name' => 'Badge name', 'description' => 'Description', 'instructions' => 'Instructions', 'sort_order' => 'Order',
            'min_order_total' => 'Minimum order', 'max_order_total' => 'Maximum order']);
        $min = ($v = trim((string) ($in['min_order_total'] ?? ''))) !== '' ? $v : null;   // '0' is a value, not blank
        $max = ($v = trim((string) ($in['max_order_total'] ?? ''))) !== '' ? $v : null;
        if (!isset($errors['max_order_total']) && $max !== null && Money::isZero($max)) {
            $errors['max_order_total'] = 'Leave the maximum blank for no limit.';
        }
        if (!isset($errors['min_order_total']) && !isset($errors['max_order_total']) && $min !== null && $max !== null && Money::cmp($max, $min) < 0) {
            $errors['max_order_total'] = 'The maximum must be at least the minimum.';
        }
        $enabled = !empty($in['is_enabled']);
        $keys = self::ACCOUNT_SETTINGS[$code];
        $settings = [];
        if ($keys) {
            try {
                $settings = (new SettingsService())->validateKeys($in, $keys);
            } catch (ValidationException $e) {
                $errors += $e->errors;
            }
            if ($enabled && !isset($errors[$keys[0]]) && ($settings[$keys[0]] ?? '') === '') {
                $errors[$keys[0]] = 'Enter the ' . strtolower(SettingsService::FIELDS[$keys[0]][0]) . ' to offer this method, or switch it off.';
            }
        }
        if (!$enabled && !array_filter(self::all(), fn ($o, $c) => $c !== $code && (int) $o['is_enabled'] === 1, ARRAY_FILTER_USE_BOTH)) {
            $errors['is_enabled'] = 'At least one payment method must stay on, or customers cannot check out.';
        }
        if ($errors) {
            throw new ValidationException($errors);
        }

        $row = [
            'name' => trim((string) $in['name']), 'short_name' => trim((string) $in['short_name']), 'description' => trim((string) ($in['description'] ?? '')),
            'instructions' => ($i = trim(str_replace("\r\n", "\n", (string) ($in['instructions'] ?? '')))) !== '' ? $i : null,
            'is_enabled' => $enabled ? 1 : 0, 'sort_order' => (int) $in['sort_order'],
            'min_order_total' => $min !== null ? Money::normalize($min) : null, 'max_order_total' => $max !== null ? Money::normalize($max) : null,
        ];
        App::db()->transaction(function () use ($code, $row, $actorId) {
            App::db()->exec(
                'UPDATE payment_methods SET name = ?, short_name = ?, description = ?, instructions = ?, is_enabled = ?, sort_order = ?,
                        min_order_total = ?, max_order_total = ?, updated_by = ? WHERE code = ?',
                [$row['name'], $row['short_name'], $row['description'], $row['instructions'], $row['is_enabled'], $row['sort_order'],
                 $row['min_order_total'], $row['max_order_total'], $actorId, $code]
            );
        });
        if ($keys) {
            (new SettingsService())->saveKeys($in, $actorId, $keys);
        }
        $before = array_intersect_key($m, $row);
        $changed = array_filter($row, fn ($v, $k) => (string) $v !== (string) ($before[$k] ?? ''), ARRAY_FILTER_USE_BOTH);
        if ($changed) {
            AuditLogger::log('payment_method.updated', 'payment_method', $code, array_intersect_key($before, $changed), $changed, $actorId);
        }
        self::flush();
    }

    /**
     * What staff need to see at a glance: is each method working, and what is waiting.
     * @return array<string,array{status:string,status_label:string,pending:int,verified_30d:int,rejected_30d:int,last_verified:?string}>
     */
    public static function health(): array
    {
        $db = App::db();
        $stats = array_column($db->all(
            "SELECT method,
                    SUM(status = 'pending') AS pending,
                    SUM(status = 'verified' AND submitted_at >= NOW() - INTERVAL 30 DAY) AS verified_30d,
                    SUM(status = 'rejected' AND submitted_at >= NOW() - INTERVAL 30 DAY) AS rejected_30d
               FROM payment_submissions GROUP BY method"
        ), null, 'method');
        $last = array_column($db->all(
            "SELECT s.method, MAX(v.created_at) AS at FROM payment_verifications v JOIN payment_submissions s ON s.id = v.submission_id
              WHERE v.decision = 'verified' GROUP BY s.method"
        ), 'at', 'method');
        $codPending = (int) $db->value("SELECT COUNT(*) FROM orders WHERE payment_method = 'cod' AND status = 'pending_confirmation'");
        $out = [];
        foreach (self::all() as $code => $m) {
            [$status, $label] = match (true) {
                (int) $m['is_enabled'] !== 1 => ['off', 'Off'],
                !self::configured($code) => ['incomplete', 'Not offered: ' . strtolower(SettingsService::FIELDS[self::ACCOUNT_SETTINGS[$code][0]][0]) . ' missing'],
                default => ['on', 'Offered at checkout'],
            };
            $s = $stats[$code] ?? [];
            $out[$code] = ['status' => $status, 'status_label' => $label,
                'pending' => $code === 'cod' ? $codPending : (int) ($s['pending'] ?? 0),
                'verified_30d' => (int) ($s['verified_30d'] ?? 0), 'rejected_30d' => (int) ($s['rejected_30d'] ?? 0), 'last_verified' => $last[$code] ?? null];
        }
        return $out;
    }
}
