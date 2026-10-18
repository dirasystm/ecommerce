<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Crypto;
use Waimart\Core\Settings;
use Waimart\Core\ValidationException;

/**
 * Admin → Settings → Branding: the store's name, tagline, colours, order-number prefix and brand images.
 *
 * Uploads are validated and decoded by ImageService (content-checked, size-limited, never stored as uploaded) and
 * re-encoded here as PNG at the sizes each place needs. An icon upload produces the whole icon set (favicon, home-screen
 * and app icons). Replaced uploads are deleted after the new settings are saved; the shipped defaults never are.
 * Every change is audited (values only — files are referenced by path).
 */
final class BrandingService
{
    /** form field => [label, what it is for] */
    public const UPLOADS = [
        'logo_header' => ['Header logo (horizontal)', 'Shown in the site header, footer, Seller Centre and admin. A wide logo or wordmark works best; at least 300 px wide.'],
        'logo_icon' => ['Icon (square mark)', 'Shown beside the header logo, and used to make the browser tab icon and phone home-screen icons. A square image, at least 512 × 512 px.'],
        'logo_full' => ['Full logo', 'The complete logo with tagline: error pages and documents.'],
        'og_image' => ['Social sharing image', 'The preview shown when a link is shared on WhatsApp, Facebook or X. 1200 × 630 px is ideal.'],
    ];

    /** @param array<string,mixed> $in @param array<string,array<string,mixed>> $files */
    public function save(array $in, array $files, int $actorId): void
    {
        $name = trim((string) ($in['store_name'] ?? ''));
        $tagline = trim((string) ($in['store_tagline'] ?? ''));
        $prefix = strtoupper(trim((string) ($in['order_number_prefix'] ?? '')));
        $primary = Brand::hex((string) ($in['brand_primary'] ?? ''));
        $secondary = Brand::hex((string) ($in['brand_secondary'] ?? ''));
        $errors = [];
        if ($name === '' || mb_strlen($name) > 60) {
            $errors['store_name'] = 'Enter the store name (up to 60 characters).';
        }
        if (mb_strlen($tagline) > 120) {
            $errors['store_tagline'] = 'Keep the tagline to 120 characters.';
        }
        if (!preg_match('/^[A-Z]{2}$/', $prefix)) {
            $errors['order_number_prefix'] = 'Use exactly two letters, e.g. BM.';
        }
        if ($primary === null) {
            $errors['brand_primary'] = 'Enter a colour like #005B3D.';
        } elseif (Brand::contrast($primary, '#FFFFFF') < 4.5) {
            // The header, buttons and links put white text on the primary colour, or the colour on white.
            $errors['brand_primary'] = 'This colour is too light: white text on it would be hard to read (contrast '
                . number_format(Brand::contrast($primary, '#FFFFFF'), 1) . ':1; at least 4.5:1 is needed). Choose a darker shade.';
        }
        if ($secondary === null) {
            $errors['brand_secondary'] = 'Enter a colour like #FF6A00.';
        }
        if ($errors) {
            throw new ValidationException($errors);
        }

        // Images first (they can fail validation); nothing is saved unless everything is valid.
        $images = new ImageService();
        $new = [];
        $created = [];
        try {
            foreach (array_keys(self::UPLOADS) as $field) {
                $f = $files[$field] ?? null;
                if (is_array($f) && ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $paths = $this->render($field, $images->decode($f, $field));
                    $created = array_merge($created, array_values($paths));
                    $new += $paths;
                }
            }
        } catch (\Throwable $e) {
            foreach ($created as $p) {
                $images->delete($p);
            }
            throw $e;
        }
        foreach ((array) ($in['reset'] ?? []) as $key) {
            // "Use the supplied logo again" for any brand image.
            foreach (self::settingsFor((string) $key) as $k) {
                $new[$k] ??= Brand::ASSETS[$k];
            }
        }

        $values = ['store_name' => $name, 'store_tagline' => $tagline, 'order_number_prefix' => $prefix,
                   'brand_primary' => $primary, 'brand_secondary' => $secondary] + $new;
        $before = [];
        $after = [];
        App::db()->transaction(function () use ($values, &$before, &$after) {
            foreach ($values as $k => $v) {
                $old = (string) Settings::get($k, '');
                if ($old !== $v) {
                    $before[$k] = $old;
                    $after[$k] = $v;
                    Settings::set($k, $v);
                }
            }
        });
        Settings::flush();
        if ($after) {
            AuditLogger::log('branding.updated', 'settings', null, $before, $after, $actorId);
        }
        // Old uploaded files that are no longer referenced (shipped defaults live in assets/, never deleted).
        foreach ($before as $k => $old) {
            if (isset(Brand::ASSETS[$k]) && str_starts_with($old, 'uploads/brand/')) {
                $images->delete($old);
            }
        }
    }

    /** @return list<string> setting keys an upload field controls */
    public static function settingsFor(string $field): array
    {
        return match ($field) {
            'logo_header' => ['brand_logo_header'],
            'logo_icon' => ['brand_logo_icon', 'brand_favicon', 'brand_icon_180', 'brand_icon_192', 'brand_icon_512'],
            'logo_full' => ['brand_logo_full'],
            'og_image' => ['brand_og_image'],
            default => [],
        };
    }

    /** @return array<string,string> setting key => new public path */
    private function render(string $field, \GdImage $src): array
    {
        $w = imagesx($src);
        $h = imagesy($src);
        return match ($field) {
            'logo_header' => ['brand_logo_header' => $this->write($this->fit($src, 900, 112), 'logo-header')],
            'logo_full' => ['brand_logo_full' => $this->write($this->fit($src, 800, 800), 'logo-full')],
            'og_image' => ['brand_og_image' => $this->write($this->canvas($src, 1200, 630, 0.92), 'og-image')],
            'logo_icon' => $w < 64 || $h < 64
                ? throw new ValidationException(['logo_icon' => 'The icon is too small: use an image of at least 512 × 512 px.'])
                : [
                    'brand_logo_icon' => $this->write($this->fit($src, 240, 160), 'logo-icon'),
                    'brand_favicon' => $this->write($this->canvas($src, 32, 32, 1.0, true), 'favicon-32'),
                    'brand_icon_180' => $this->write($this->canvas($src, 180, 180, 0.84), 'icon-180'),   // iOS: opaque
                    'brand_icon_192' => $this->write($this->canvas($src, 192, 192, 0.84, true), 'icon-192'),
                    'brand_icon_512' => $this->write($this->canvas($src, 512, 512, 0.84, true), 'icon-512'),
                ],
            default => throw new \InvalidArgumentException("Unknown brand upload $field"),
        };
    }

    /** Scaled down (never up) to fit within the box, transparency kept. */
    private function fit(\GdImage $src, int $maxW, int $maxH): \GdImage
    {
        [$w, $h] = [imagesx($src), imagesy($src)];
        $s = min(1, $maxW / $w, $maxH / $h);
        $dst = $this->blank(max(1, (int) round($w * $s)), max(1, (int) round($h * $s)), true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, imagesx($dst), imagesy($dst), $w, $h);
        return $dst;
    }

    /** The image centred on a fixed-size canvas, using at most $fill of it; white or transparent background. */
    private function canvas(\GdImage $src, int $cw, int $ch, float $fill, bool $transparent = false): \GdImage
    {
        [$w, $h] = [imagesx($src), imagesy($src)];
        $s = min($cw * $fill / $w, $ch * $fill / $h);
        $nw = max(1, (int) round($w * $s));
        $nh = max(1, (int) round($h * $s));
        $dst = $this->blank($cw, $ch, $transparent);
        imagecopyresampled($dst, $src, (int) (($cw - $nw) / 2), (int) (($ch - $nh) / 2), 0, 0, $nw, $nh, $w, $h);
        return $dst;
    }

    private function blank(int $w, int $h, bool $transparent): \GdImage
    {
        $im = imagecreatetruecolor($w, $h);
        if ($transparent) {
            imagealphablending($im, false);
            imagefill($im, 0, 0, imagecolorallocatealpha($im, 255, 255, 255, 127));
            imagealphablending($im, true);
            imagesavealpha($im, true);
        } else {
            imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
        }
        return $im;
    }

    private function write(\GdImage $im, string $name): string
    {
        $rel = 'uploads/brand/' . date('Y/m') . '/' . $name . '-' . strtolower(Crypto::code(10)) . '.png';
        $abs = App::root() . '/public/' . $rel;
        if (!is_dir(dirname($abs)) && !mkdir(dirname($abs), 0775, true) && !is_dir(dirname($abs))) {
            throw new \RuntimeException('Cannot create upload directory.');
        }
        imagesavealpha($im, true);
        if (!imagepng($im, $abs, 9)) {
            throw new \RuntimeException('Failed to save image.');
        }
        return $rel;
    }
}
