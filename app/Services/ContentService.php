<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Database;
use Waimart\Core\Money;
use Waimart\Core\ValidationException;
use Waimart\Core\Validator;

/**
 * Home-page banners and flash sales (content.manage).
 * Banner links must be internal paths (no off-site redirects from the home page).
 * Flash prices must be below the variant price, and a variant cannot be in two overlapping active sales.
 */
final class ContentService
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? App::db();
    }

    // ------------------------------------------------------------------ banners

    /** @param array<string,mixed> $in @param array<string,mixed>|null $file */
    public function saveBanner(?int $id, array $in, ?array $file, int $actorId): int
    {
        Validator::validate($in, [
            'title' => 'required|max:120', 'subtitle' => 'nullable|max:200', 'link_url' => 'nullable|max:255',
            'sort_order' => 'nullable|int', 'starts_at' => 'nullable|date', 'ends_at' => 'nullable|date',
        ], ['link_url' => 'Link']);
        $link = trim((string) ($in['link_url'] ?? ''));
        if ($link !== '' && !preg_match('#^/(?!/)[A-Za-z0-9/_\-.?=&%]*$#', $link)) {
            throw new ValidationException(['link_url' => 'Use an internal path such as /category/phones-tablets (links to other sites are not allowed).']);
        }
        [$starts, $ends] = $this->window($in['starts_at'] ?? '', $in['ends_at'] ?? '', false);
        $data = [
            'title' => trim((string) $in['title']),
            'subtitle' => trim((string) ($in['subtitle'] ?? '')) ?: null,
            'link_url' => $link ?: null,
            'position' => 'hero',
            'sort_order' => (int) ($in['sort_order'] ?? 0),
            'is_active' => !empty($in['is_active']) ? 1 : 0,
            'starts_at' => $starts,
            'ends_at' => $ends,
        ];
        if ($file !== null && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $data['image_path'] = (new ImageService())->storeImage($file, 'banners', 1600);
        } elseif (!empty($in['remove_image'])) {
            $data['image_path'] = null;
        }
        return $this->db->transaction(function () use ($id, $data, $actorId) {
            if ($id === null) {
                $id = $this->db->insert('banners', $data);
                AuditLogger::log('content.banner_created', 'banner', $id, null, $data, $actorId);
                return $id;
            }
            $before = $this->db->one('SELECT * FROM banners WHERE id = ?', [$id]) ?? throw ValidationException::withMessage('Banner not found.');
            $this->db->exec('UPDATE banners SET ' . implode(', ', array_map(fn ($c) => "`$c` = ?", array_keys($data))) . ' WHERE id = ?', [...array_values($data), $id]);
            AuditLogger::log('content.banner_updated', 'banner', $id, array_intersect_key($before, $data), $data, $actorId);
            if (array_key_exists('image_path', $data) && $before['image_path'] && $before['image_path'] !== $data['image_path']) {
                (new ImageService())->delete((string) $before['image_path']);
            }
            return $id;
        });
    }

    public function deleteBanner(int $id, int $actorId): void
    {
        $b = $this->db->one('SELECT * FROM banners WHERE id = ?', [$id]) ?? throw ValidationException::withMessage('Banner not found.');
        $this->db->exec('DELETE FROM banners WHERE id = ?', [$id]);
        if ($b['image_path']) {
            (new ImageService())->delete((string) $b['image_path']);
        }
        AuditLogger::log('content.banner_deleted', 'banner', $id, $b, null, $actorId);
    }

    // ------------------------------------------------------------------ flash sales

    /** @param array<string,mixed> $in */
    public function saveSale(?int $id, array $in, int $actorId): int
    {
        Validator::validate($in, ['title' => 'required|max:120', 'starts_at' => 'required|date', 'ends_at' => 'required|date']);
        [$starts, $ends] = $this->window($in['starts_at'], $in['ends_at'], true);
        $data = ['title' => trim((string) $in['title']), 'starts_at' => $starts, 'ends_at' => $ends, 'is_active' => !empty($in['is_active']) ? 1 : 0];
        return $this->db->transaction(function () use ($id, $data, $actorId) {
            if ($id === null) {
                $id = $this->db->insert('flash_sales', $data);
                AuditLogger::log('content.flash_sale_created', 'flash_sale', $id, null, $data, $actorId);
                return $id;
            }
            $before = $this->db->one('SELECT * FROM flash_sales WHERE id = ? FOR UPDATE', [$id]) ?? throw ValidationException::withMessage('Flash sale not found.');
            // Changing the window must not create overlaps for items already in the sale.
            foreach ($this->db->all('SELECT variant_id FROM flash_sale_items WHERE flash_sale_id = ?', [$id]) as $it) {
                $this->assertNoOverlap((int) $it['variant_id'], $data['starts_at'], $data['ends_at'], $id);
            }
            $this->db->exec('UPDATE flash_sales SET title = ?, starts_at = ?, ends_at = ?, is_active = ? WHERE id = ?',
                [$data['title'], $data['starts_at'], $data['ends_at'], $data['is_active'], $id]);
            AuditLogger::log('content.flash_sale_updated', 'flash_sale', $id, array_intersect_key($before, $data), $data, $actorId);
            return $id;
        });
    }

    public function addSaleItem(int $saleId, string $sku, string $price, int $actorId): void
    {
        $sale = $this->db->one('SELECT * FROM flash_sales WHERE id = ?', [$saleId]) ?? throw ValidationException::withMessage('Flash sale not found.');
        $v = $this->db->one(
            'SELECT v.id, v.price, p.name, s.is_platform, s.allow_promotions, s.name AS seller_name
               FROM product_variants v JOIN products p ON p.id = v.product_id JOIN sellers s ON s.id = p.seller_id WHERE v.sku = ?',
            [strtoupper(trim($sku))]
        ) ?? throw new ValidationException(['sku' => 'No product variant has that SKU.']);
        // A flash price lowers what a marketplace seller earns, so their products join only if they opted in.
        if (!(int) $v['is_platform'] && !(int) $v['allow_promotions']) {
            throw new ValidationException(['sku' => $v['seller_name'] . ' has not agreed to take part in Waimart flash sales.']);
        }
        if (!preg_match('/^\d{1,10}(\.\d{1,2})?$/', trim($price))) {
            throw new ValidationException(['sale_price' => 'Enter a valid sale price.']);
        }
        $p = Money::normalize($price);
        if (Money::cmp($p, '0') <= 0 || Money::cmp($p, (string) $v['price']) >= 0) {
            throw new ValidationException(['sale_price' => 'The sale price must be above zero and below the normal price of ' . Money::format((string) $v['price']) . '.']);
        }
        if ($this->db->value('SELECT 1 FROM flash_sale_items WHERE flash_sale_id = ? AND variant_id = ?', [$saleId, $v['id']])) {
            throw new ValidationException(['sku' => 'This item is already in the sale.']);
        }
        $this->assertNoOverlap((int) $v['id'], (string) $sale['starts_at'], (string) $sale['ends_at'], $saleId);
        $this->db->insert('flash_sale_items', ['flash_sale_id' => $saleId, 'variant_id' => $v['id'], 'sale_price' => $p]);
        AuditLogger::log('content.flash_item_added', 'flash_sale', $saleId, null, ['variant_id' => $v['id'], 'sale_price' => $p], $actorId);
    }

    public function removeSaleItem(int $saleId, int $variantId, int $actorId): void
    {
        if ($this->db->exec('DELETE FROM flash_sale_items WHERE flash_sale_id = ? AND variant_id = ?', [$saleId, $variantId]) === 1) {
            AuditLogger::log('content.flash_item_removed', 'flash_sale', $saleId, ['variant_id' => $variantId], null, $actorId);
        }
    }

    private function assertNoOverlap(int $variantId, string $starts, string $ends, int $exceptSaleId): void
    {
        $clash = $this->db->value(
            'SELECT f.title FROM flash_sale_items i JOIN flash_sales f ON f.id = i.flash_sale_id
              WHERE i.variant_id = ? AND f.id <> ? AND f.is_active = 1 AND f.starts_at < ? AND f.ends_at > ? LIMIT 1',
            [$variantId, $exceptSaleId, $ends, $starts]
        );
        if ($clash) {
            throw new ValidationException(['sku' => "This item is already in the overlapping sale \"$clash\"."]);
        }
    }

    /** @return array{0:?string,1:?string} normalised 'Y-m-d H:i:s' window */
    private function window(mixed $start, mixed $end, bool $required): array
    {
        $s = trim((string) $start) === '' ? null : date('Y-m-d H:i:s', (int) strtotime((string) $start));
        $e = trim((string) $end) === '' ? null : date('Y-m-d H:i:s', (int) strtotime((string) $end));
        if ($required && ($s === null || $e === null)) {
            throw new ValidationException(['starts_at' => 'Set both a start and an end time.']);
        }
        if ($s !== null && $e !== null && $e <= $s) {
            throw new ValidationException(['ends_at' => 'The end must be after the start.']);
        }
        return [$s, $e];
    }
}
