<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\Settings;
use Waimart\Core\ValidationException;

/**
 * Which verification documents seller applicants must or may upload. Configured by the administrator
 * (setting `seller_required_documents`) according to the marketplace's own onboarding policy — the
 * application does not decide what the law requires.
 */
final class SellerDocumentPolicy
{
    public const TYPES = [
        'national_id' => 'National ID or passport',
        'business_registration' => 'Business registration certificate',
        'tax_registration' => 'Tax registration certificate',
        'other' => 'Other supporting document',
    ];

    /** Admin form choices per document type. */
    public const MODES = [
        'all' => 'Required for every applicant',
        'business' => 'Required for registered businesses only',
        'individual' => 'Required for individuals only',
        'optional' => 'Optional',
        'off' => 'Not requested',
    ];

    /** @return array{individual:list<string>,registered_business:list<string>,optional:list<string>} */
    public static function load(): array
    {
        $raw = json_decode((string) Settings::get('seller_required_documents', ''), true);
        $clean = fn ($list) => array_values(array_intersect(is_array($list) ? $list : [], array_keys(self::TYPES)));
        return [
            'individual' => $clean($raw['individual'] ?? []),
            'registered_business' => $clean($raw['registered_business'] ?? []),
            'optional' => $clean($raw['optional'] ?? []),
        ];
    }

    /** @return list<string> document types an applicant of this business type must upload */
    public static function required(string $businessType): array
    {
        $p = self::load();
        return $businessType === 'registered_business' ? $p['registered_business'] : $p['individual'];
    }

    /** @return list<string> every type this applicant may upload (required first, then optional) */
    public static function requested(string $businessType): array
    {
        return array_values(array_unique(array_merge(self::required($businessType), self::load()['optional'])));
    }

    /** @return array<string,string> type => mode, for the admin form */
    public static function modes(): array
    {
        $p = self::load();
        $out = [];
        foreach (array_keys(self::TYPES) as $t) {
            $ind = in_array($t, $p['individual'], true);
            $bus = in_array($t, $p['registered_business'], true);
            $out[$t] = match (true) {
                $ind && $bus => 'all',
                $bus => 'business',
                $ind => 'individual',
                in_array($t, $p['optional'], true) => 'optional',
                default => 'off',
            };
        }
        return $out;
    }

    /**
     * Turns the admin form (type => mode) into the stored JSON.
     * @param array<string,mixed> $modes
     */
    public static function encode(array $modes): string
    {
        $p = ['individual' => [], 'registered_business' => [], 'optional' => []];
        foreach (array_keys(self::TYPES) as $t) {
            $m = (string) ($modes[$t] ?? 'off');
            if (!isset(self::MODES[$m])) {
                throw new ValidationException(['doc_mode' => 'Choose a valid option for each document type.']);
            }
            if ($m === 'all' || $m === 'individual') {
                $p['individual'][] = $t;
            }
            if ($m === 'all' || $m === 'business') {
                $p['registered_business'][] = $t;
            }
            if ($m === 'optional') {
                $p['optional'][] = $t;
            }
        }
        return (string) json_encode($p);
    }
}
