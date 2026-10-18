<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Settings;

/**
 * The store's identity, read from settings (Admin → Settings → Branding): name, tagline, colours and brand images.
 * Every user-facing mention of the store name goes through Brand::name(), so renaming the store is a settings change.
 *
 * Fallbacks are neutral, never another business's details: the name falls back to config('app.name'); images fall
 * back to the files shipped in public/assets/brand; colours to the shipped palette.
 *
 * Colours: admins choose a primary and a secondary colour. Everything else (light/dark shades, the RGB triplets
 * Bootstrap needs, and a button colour that keeps white text readable) is derived here, so any choice stays accessible.
 */
final class Brand
{
    /** setting key => shipped default (paths relative to public/) */
    public const ASSETS = [
        'brand_logo_header' => 'assets/brand/logo-wordmark.png',   // horizontal wordmark: headers, emails
        'brand_logo_icon' => 'assets/brand/logo-icon.png',         // the mark on its own: header, compact places
        'brand_logo_full' => 'assets/brand/logo-full.png',         // stacked logo with tagline: about page, documents
        'brand_favicon' => 'assets/brand/favicon-32.png',
        'brand_icon_180' => 'assets/brand/apple-touch-icon.png',
        'brand_icon_192' => 'assets/brand/icon-192.png',
        'brand_icon_512' => 'assets/brand/icon-512.png',
        'brand_og_image' => 'assets/brand/og-image.png',           // social sharing preview (1200×630)
    ];
    /** Any order number, whatever prefix it was issued with (WM- before the rebrand, BM- after). Routes use the same shape. */
    public const ORDER_NUMBER_REGEX = '/^[A-Z]{2}-[A-Z0-9]{8}$/';
    public const DEFAULT_PRIMARY = '#005B3D';
    public const DEFAULT_SECONDARY = '#FF6A00';

    public static function name(): string
    {
        $n = trim((string) Settings::get('store_name', ''));
        return $n !== '' ? $n : (string) config('app.name', 'Online store');
    }

    /** The name as a lowercase slug ("Basemall Kenya" → "basemall-kenya"), e.g. for downloaded file names. */
    public static function slug(): string
    {
        $s = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(self::name())), '-');
        return $s !== '' ? $s : 'store';
    }

    /**
     * Words sellers may not use in store names or addresses, so no store can pass itself off as the platform: the
     * current store name, plus earlier names of this platform.
     * @return list<string> lowercase, without spaces
     */
    public static function protectedNames(): array
    {
        return array_values(array_unique(array_filter([str_replace([' ', '-'], '', strtolower(self::name())), 'waimart'])));
    }

    public static function tagline(): string
    {
        return trim((string) Settings::get('store_tagline', ''));
    }

    /** Public URL of a brand image, cache-busted by modification time so a new upload shows at once. */
    public static function asset(string $key): string
    {
        $default = self::ASSETS[$key] ?? throw new \InvalidArgumentException("Unknown brand asset $key");
        $path = (string) Settings::get($key, '');
        if ($path === '' || str_contains($path, '..') || !is_file(App::root() . '/public/' . $path)) {
            $path = $default;
        }
        $abs = App::root() . '/public/' . $path;
        return url('/' . $path) . (is_file($abs) ? '?v=' . filemtime($abs) : '');
    }

    /** Absolute URL (for e-mails and social previews), using app.url. */
    public static function absoluteAsset(string $key): string
    {
        $rel = self::asset($key);
        $base = rtrim((string) config('app.url', ''), '/');
        $basePath = rtrim((string) config('app.base_path', ''), '/');
        return $base . ($basePath !== '' && str_starts_with($rel, $basePath) ? substr($rel, strlen($basePath)) : $rel);
    }

    public static function primary(): string
    {
        return self::hex((string) Settings::get('brand_primary', '')) ?? self::DEFAULT_PRIMARY;
    }

    public static function secondary(): string
    {
        return self::hex((string) Settings::get('brand_secondary', '')) ?? self::DEFAULT_SECONDARY;
    }

    /** Prefix of new order numbers (existing numbers keep theirs). */
    public static function orderPrefix(): string
    {
        $p = strtoupper(trim((string) Settings::get('order_number_prefix', '')));
        return preg_match('/^[A-Z]{2}$/', $p) ? $p : 'BM';
    }

    /** @return array<string,string> CSS custom properties derived from the two brand colours */
    public static function palette(): array
    {
        $p = self::primary();
        $s = self::secondary();
        $action = self::readableOnWhiteText($s);
        return [
            '--wm-green' => $p,
            '--wm-green-700' => self::shade($p, -0.22),
            '--wm-green-50' => self::mix($p, '#FFFFFF', 0.92),
            '--wm-orange' => $s,
            '--wm-orange-deep' => self::shade($s, -0.10),
            '--wm-orange-50' => self::mix($s, '#FFFFFF', 0.94),
            '--wm-orange-rgb' => self::rgbList($s),
            '--wm-action' => $action,
            '--wm-action-hover' => self::shade($action, -0.14),
            '--bs-link-color-rgb' => self::rgbList($p),
            '--bs-primary-rgb' => self::rgbList($p),
        ];
    }

    /** The palette as a :root rule for the page <head>. Values are validated hex/RGB lists, so this is safe to print. */
    public static function css(): string
    {
        $out = [];
        foreach (self::palette() as $k => $v) {
            $out[] = "$k:$v";
        }
        return ':root{' . implode(';', $out) . '}';
    }

    // ------------------------------------------------------------------ colour maths (WCAG 2.x)

    /** "#abc" / "abc" / "#AABBCC" → "#AABBCC"; anything else → null. */
    public static function hex(string $v): ?string
    {
        $v = ltrim(trim($v), '#');
        if (preg_match('/^[0-9a-fA-F]{3}$/', $v)) {
            $v = $v[0] . $v[0] . $v[1] . $v[1] . $v[2] . $v[2];
        }
        return preg_match('/^[0-9a-fA-F]{6}$/', $v) ? '#' . strtoupper($v) : null;
    }

    public static function contrast(string $a, string $b): float
    {
        [$l1, $l2] = [self::luminance($a), self::luminance($b)];
        return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
    }

    /** The colour itself if white text on it reaches 4.5:1, otherwise the closest darker shade that does. */
    public static function readableOnWhiteText(string $hex): string
    {
        $c = $hex;
        for ($i = 0; $i < 60 && self::contrast($c, '#FFFFFF') < 4.5; $i++) {
            $c = self::shade($c, -0.03);
        }
        return $c;
    }

    /** Lighter (+) or darker (−) by a fraction, towards white or black. */
    public static function shade(string $hex, float $amount): string
    {
        return self::mix($hex, $amount < 0 ? '#000000' : '#FFFFFF', abs($amount));
    }

    /** $amount of $to mixed into $hex. */
    public static function mix(string $hex, string $to, float $amount): string
    {
        [$r1, $g1, $b1] = self::rgb($hex);
        [$r2, $g2, $b2] = self::rgb($to);
        $f = fn ($a, $b) => (int) round($a + ($b - $a) * $amount);
        return sprintf('#%02X%02X%02X', $f($r1, $r2), $f($g1, $g2), $f($b1, $b2));
    }

    /** @return array{int,int,int} */
    private static function rgb(string $hex): array
    {
        $h = ltrim($hex, '#');
        return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
    }

    private static function rgbList(string $hex): string
    {
        return implode(', ', self::rgb($hex));
    }

    private static function luminance(string $hex): float
    {
        $lin = function (int $c): float {
            $s = $c / 255;
            return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        };
        [$r, $g, $b] = self::rgb($hex);
        return 0.2126 * $lin($r) + 0.7152 * $lin($g) + 0.0722 * $lin($b);
    }
}
