<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Database;
use Waimart\Core\Money;
use Waimart\Core\ValidationException;
use Waimart\Core\Validator;
use Waimart\Repositories\CatalogueRepository;

/**
 * Write-side catalogue management for staff. Products are never hard-deleted (orders keep
 * references); they are deactivated instead. Every change is audited.
 */
final class CatalogueAdminService
{
    private Database $db;

    public function __construct(?Database $db = null, private ?InventoryService $inventory = null, private ?ImageService $images = null)
    {
        $this->db = $db ?? App::db();
        $this->inventory ??= new InventoryService($this->db);
        $this->images ??= new ImageService();
    }

    // ------------------------------------------------------------------ categories

    /** @param array<string,mixed> $in */
    public function saveCategory(?int $id, array $in, int $actorId): int
    {
        Validator::validate($in, [
            'name' => 'required|max:120',
            'slug' => 'nullable|slug|max:140',
            'icon' => 'nullable|max:40',
            'sort_order' => 'nullable|int',
            'parent_id' => 'nullable|int',
        ]);
        if (($in['icon'] ?? '') !== '' && !preg_match('/^[a-z0-9-]+$/', (string) $in['icon'])) {
            throw new ValidationException(['icon' => 'Use a Bootstrap Icons name such as "phone" or "basket".']);
        }
        $parentId = ($in['parent_id'] ?? '') === '' ? null : (int) $in['parent_id'];
        if ($parentId !== null) {
            $parent = $this->db->one('SELECT id, parent_id FROM categories WHERE id = ?', [$parentId]);
            if ($parent === null || $parent['parent_id'] !== null) {
                throw new ValidationException(['parent_id' => 'Choose a top-level category as the parent (two levels are supported).']);
            }
            if ($id !== null && $parentId === $id) {
                throw new ValidationException(['parent_id' => 'A category cannot be its own parent.']);
            }
            if ($id !== null && (int) $this->db->value('SELECT COUNT(*) FROM categories WHERE parent_id = ?', [$id]) > 0) {
                throw new ValidationException(['parent_id' => 'This category has subcategories, so it must stay top-level.']);
            }
        }
        $data = [
            'name' => trim((string) $in['name']),
            'slug' => $this->uniqueSlug('categories', (string) (($in['slug'] ?? '') ?: $in['name']), $id),
            'parent_id' => $parentId,
            'icon' => ($in['icon'] ?? '') ?: null,
            'description' => ($in['description'] ?? '') ?: null,
            'sort_order' => (int) ($in['sort_order'] ?? 0),
            'is_active' => !empty($in['is_active']) ? 1 : 0,
        ];
        $id = $this->upsert('categories', $id, $data, 'category', $actorId);
        CatalogueRepository::flushCache();
        return $id;
    }

    // ------------------------------------------------------------------ brands

    /** @param array<string,mixed> $in */
    public function saveBrand(?int $id, array $in, int $actorId): int
    {
        Validator::validate($in, ['name' => 'required|max:120', 'slug' => 'nullable|slug|max:140']);
        $name = trim((string) $in['name']);
        $dup = $this->db->value('SELECT id FROM brands WHERE name = ? AND id <> ?', [$name, $id ?? 0]);
        if ($dup) {
            throw new ValidationException(['name' => 'A brand with this name already exists.']);
        }
        return $this->upsert('brands', $id, [
            'name' => $name,
            'slug' => $this->uniqueSlug('brands', (string) (($in['slug'] ?? '') ?: $name), $id),
            'is_active' => !empty($in['is_active']) ? 1 : 0,
        ], 'brand', $actorId);
    }

    // ------------------------------------------------------------------ products

    /**
     * Creates a product with its default variant and optional opening stock.
     * @param array<string,mixed> $in
     */
    public function createProduct(array $in, int $actorId): int
    {
        $product = $this->validateProduct($in, null);
        $variant = $this->validateVariant($in, null);
        $stock = $this->validateOpeningStock($in);

        return $this->db->transaction(function () use ($product, $variant, $stock, $actorId) {
            $productId = $this->db->insert('products', $product);
            $this->insertVariant($productId, $variant + ['is_default' => 1], $stock, $actorId);
            AuditLogger::log('catalogue.product_created', 'product', $productId, null, $product + ['variant' => $variant], $actorId);
            return $productId;
        });
    }

    /** @param array<string,mixed> $in */
    public function updateProduct(int $id, array $in, int $actorId): void
    {
        $before = $this->db->one('SELECT * FROM products WHERE id = ?', [$id]) ?? throw ValidationException::withMessage('Product not found.');
        $data = $this->validateProduct($in, $id);
        $this->db->transaction(function () use ($id, $data, $before, $actorId) {
            $this->db->exec(
                'UPDATE products SET ' . implode(', ', array_map(fn ($c) => "`$c` = ?", array_keys($data))) . ' WHERE id = ?',
                [...array_values($data), $id]
            );
            AuditLogger::log('catalogue.product_updated', 'product', $id, self::diffBefore($before, $data), $data, $actorId);
        });
    }

    /** @param array<string,mixed> $in */
    public function addVariant(int $productId, array $in, int $actorId): int
    {
        if (!$this->db->value('SELECT id FROM products WHERE id = ?', [$productId])) {
            throw ValidationException::withMessage('Product not found.');
        }
        $variant = $this->validateVariant($in, null);
        $stock = $this->validateOpeningStock($in);
        return $this->db->transaction(function () use ($productId, $variant, $stock, $actorId) {
            $hasDefault = (bool) $this->db->value('SELECT COUNT(*) FROM product_variants WHERE product_id = ? AND is_default = 1', [$productId]);
            $id = $this->insertVariant($productId, $variant + ['is_default' => $hasDefault ? 0 : 1], $stock, $actorId);
            AuditLogger::log('catalogue.variant_created', 'variant', $id, null, $variant + ['product_id' => $productId], $actorId);
            return $id;
        });
    }

    /** @param array<string,mixed> $in */
    public function updateVariant(int $variantId, array $in, int $actorId): void
    {
        $before = $this->db->one('SELECT * FROM product_variants WHERE id = ?', [$variantId]) ?? throw ValidationException::withMessage('Variant not found.');
        $data = $this->validateVariant($in, $variantId);
        if ((int) $before['is_default'] === 1 && $data['is_active'] === 0) {
            throw new ValidationException(['is_active' => 'Make another variant the default before deactivating this one.']);
        }
        $attrs = $data['attributes'];
        $threshold = $data['_threshold'];
        unset($data['attributes'], $data['_threshold']);
        $this->db->transaction(function () use ($variantId, $data, $attrs, $threshold, $before, $actorId) {
            $this->db->exec(
                'UPDATE product_variants SET ' . implode(', ', array_map(fn ($c) => "`$c` = ?", array_keys($data))) . ' WHERE id = ?',
                [...array_values($data), $variantId]
            );
            $this->replaceAttributes($variantId, $attrs);
            $this->db->exec('UPDATE inventory SET low_stock_threshold = ? WHERE variant_id = ?', [$threshold, $variantId]);
            AuditLogger::log('catalogue.variant_updated', 'variant', $variantId, self::diffBefore($before, $data), $data + ['attributes' => $attrs], $actorId);
        });
    }

    public function setDefaultVariant(int $productId, int $variantId, int $actorId): void
    {
        $this->db->transaction(function () use ($productId, $variantId, $actorId) {
            $v = $this->db->one('SELECT id, is_active FROM product_variants WHERE id = ? AND product_id = ? FOR UPDATE', [$variantId, $productId]);
            if ($v === null) {
                throw ValidationException::withMessage('Variant not found.');
            }
            if ((int) $v['is_active'] !== 1) {
                throw ValidationException::withMessage('Only an active variant can be the default.');
            }
            $this->db->exec('UPDATE product_variants SET is_default = 0 WHERE product_id = ? AND is_default = 1', [$productId]);
            $this->db->exec('UPDATE product_variants SET is_default = 1 WHERE id = ?', [$variantId]);
            AuditLogger::log('catalogue.default_variant_set', 'product', $productId, null, ['variant_id' => $variantId], $actorId);
        });
    }

    // ------------------------------------------------------------------ images

    /** @param array<string,mixed> $file */
    public function addImage(int $productId, array $file, ?string $alt, int $actorId): int
    {
        if (!$this->db->value('SELECT id FROM products WHERE id = ?', [$productId])) {
            throw ValidationException::withMessage('Product not found.');
        }
        if ((int) $this->db->value('SELECT COUNT(*) FROM product_images WHERE product_id = ?', [$productId]) >= 10) {
            throw new ValidationException(['image' => 'A product can have at most 10 images.']);
        }
        $path = $this->images->storeProductImage($file);
        try {
            $next = (int) $this->db->value('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM product_images WHERE product_id = ?', [$productId]);
            $id = $this->db->insert('product_images', [
                'product_id' => $productId, 'path' => $path, 'alt_text' => $alt ? mb_substr($alt, 0, 200) : null, 'sort_order' => $next,
            ]);
        } catch (\Throwable $e) {
            $this->images->delete($path);
            throw $e;
        }
        AuditLogger::log('catalogue.image_added', 'product', $productId, null, ['image_id' => $id, 'path' => $path], $actorId);
        return $id;
    }

    public function deleteImage(int $productId, int $imageId, int $actorId): void
    {
        $img = $this->db->one('SELECT * FROM product_images WHERE id = ? AND product_id = ?', [$imageId, $productId]);
        if ($img === null) {
            throw ValidationException::withMessage('Image not found.');
        }
        $this->db->exec('DELETE FROM product_images WHERE id = ?', [$imageId]);
        // Files referenced by order snapshots are kept; only uploads no longer referenced anywhere are removed.
        if (!$this->db->value('SELECT 1 FROM order_items WHERE image_path = ? LIMIT 1', [$img['path']])) {
            $this->images->delete((string) $img['path']);
        }
        AuditLogger::log('catalogue.image_deleted', 'product', $productId, $img, null, $actorId);
    }

    /** Moves an image to the front (main image). */
    public function makeMainImage(int $productId, int $imageId, int $actorId): void
    {
        $this->db->transaction(function () use ($productId, $imageId, $actorId) {
            if (!$this->db->value('SELECT id FROM product_images WHERE id = ? AND product_id = ?', [$imageId, $productId])) {
                throw ValidationException::withMessage('Image not found.');
            }
            $this->db->exec('UPDATE product_images SET sort_order = sort_order + 1 WHERE product_id = ?', [$productId]);
            $this->db->exec('UPDATE product_images SET sort_order = 0 WHERE id = ?', [$imageId]);
            AuditLogger::log('catalogue.image_main', 'product', $productId, null, ['image_id' => $imageId], $actorId);
        });
    }

    // ------------------------------------------------------------------ helpers

    /** @param array<string,mixed> $in @return array<string,mixed> */
    private function validateProduct(array $in, ?int $id): array
    {
        Validator::validate($in, [
            'name' => 'required|max:200',
            'slug' => 'nullable|slug|max:220',
            'category_id' => 'required|int',
            'brand_id' => 'nullable|int',
            'short_description' => 'nullable|max:500',
            'description' => 'nullable|max:20000',
            'specifications' => 'nullable|max:5000',
            'max_per_order' => 'required|int|gte:1|lte:100',
        ], ['category_id' => 'Category', 'brand_id' => 'Brand', 'max_per_order' => 'Maximum per order']);

        if (!$this->db->value('SELECT id FROM categories WHERE id = ?', [(int) $in['category_id']])) {
            throw new ValidationException(['category_id' => 'Select a valid category.']);
        }
        $brandId = ($in['brand_id'] ?? '') === '' ? null : (int) $in['brand_id'];
        if ($brandId !== null && !$this->db->value('SELECT id FROM brands WHERE id = ?', [$brandId])) {
            throw new ValidationException(['brand_id' => 'Select a valid brand.']);
        }
        return [
            'name' => trim((string) $in['name']),
            'slug' => $this->uniqueSlug('products', (string) (($in['slug'] ?? '') ?: $in['name']), $id),
            'category_id' => (int) $in['category_id'],
            'brand_id' => $brandId,
            'short_description' => ($in['short_description'] ?? '') ?: null,
            'description' => ($in['description'] ?? '') ?: null,
            'specifications' => self::encodePairs((string) ($in['specifications'] ?? ''), 'specifications'),
            'max_per_order' => (int) $in['max_per_order'],
            'is_active' => !empty($in['is_active']) ? 1 : 0,
            'is_featured' => !empty($in['is_featured']) ? 1 : 0,
        ];
    }

    /** @param array<string,mixed> $in @return array<string,mixed> */
    private function validateVariant(array $in, ?int $variantId): array
    {
        Validator::validate($in, [
            'sku' => 'required|max:64',
            'variant_name' => 'nullable|max:120',
            'price' => 'required|decimal',
            'compare_at_price' => 'nullable|decimal',
            'attributes' => 'nullable|max:1000',
            'low_stock_threshold' => 'nullable|int|gte:0|lte:10000',
        ], ['sku' => 'SKU', 'variant_name' => 'Variant name', 'compare_at_price' => 'Was price']);

        $sku = strtoupper(trim((string) $in['sku']));
        if (!preg_match('/^[A-Z0-9][A-Z0-9._-]*$/', $sku)) {
            throw new ValidationException(['sku' => 'SKU may contain letters, numbers, dots, dashes and underscores.']);
        }
        if ($this->db->value('SELECT id FROM product_variants WHERE sku = ? AND id <> ?', [$sku, $variantId ?? 0])) {
            throw new ValidationException(['sku' => 'This SKU is already in use.']);
        }
        $price = Money::normalize((string) $in['price']);
        if (Money::cmp($price, '0') <= 0) {
            throw new ValidationException(['price' => 'Price must be greater than zero.']);
        }
        $compare = ($in['compare_at_price'] ?? '') === '' ? null : Money::normalize((string) $in['compare_at_price']);
        if ($compare !== null && Money::cmp($compare, $price) <= 0) {
            throw new ValidationException(['compare_at_price' => 'The "was" price must be higher than the selling price, or left blank.']);
        }
        return [
            'sku' => $sku,
            'name' => trim((string) ($in['variant_name'] ?? '')) ?: 'Default',
            'price' => $price,
            'compare_at_price' => $compare,
            'is_active' => !array_key_exists('variant_active', $in) || !empty($in['variant_active']) ? 1 : 0,
            'attributes' => self::parsePairs((string) ($in['attributes'] ?? ''), 'attributes', 5),
            '_threshold' => (int) (($in['low_stock_threshold'] ?? '') === '' ? 5 : $in['low_stock_threshold']),
        ];
    }

    /** @param array<string,mixed> $in */
    private function validateOpeningStock(array $in): int
    {
        Validator::validate($in, ['opening_stock' => 'nullable|int|gte:0|lte:100000'], ['opening_stock' => 'Opening stock']);
        return (int) ($in['opening_stock'] ?? 0);
    }

    /** @param array<string,mixed> $variant */
    private function insertVariant(int $productId, array $variant, int $openingStock, int $actorId): int
    {
        $attrs = $variant['attributes'];
        $threshold = $variant['_threshold'];
        unset($variant['attributes'], $variant['_threshold']);
        $sort = (int) $this->db->value('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM product_variants WHERE product_id = ?', [$productId]);
        $variantId = $this->db->insert('product_variants', $variant + ['product_id' => $productId, 'sort_order' => $sort]);
        $this->replaceAttributes($variantId, $attrs);
        $this->db->insert('inventory', ['variant_id' => $variantId, 'on_hand' => 0, 'reserved' => 0, 'low_stock_threshold' => $threshold]);
        if ($openingStock > 0) {
            $this->inventory->lock($variantId);
            $this->inventory->applyChange($variantId, $openingStock, 0, 'receipt', [
                'actor_type' => 'staff', 'actor_user_id' => $actorId, 'reason' => 'opening', 'note' => 'Opening stock',
            ]);
        }
        return $variantId;
    }

    /** @param array<string,string> $attrs */
    private function replaceAttributes(int $variantId, array $attrs): void
    {
        $this->db->exec('DELETE FROM variant_attributes WHERE variant_id = ?', [$variantId]);
        foreach ($attrs as $name => $value) {
            $this->db->insert('variant_attributes', ['variant_id' => $variantId, 'attr_name' => $name, 'attr_value' => $value]);
        }
    }

    /** @param array<string,mixed> $data */
    private function upsert(string $table, ?int $id, array $data, string $entity, int $actorId): int
    {
        return $this->db->transaction(function () use ($table, $id, $data, $entity, $actorId) {
            if ($id === null) {
                $id = $this->db->insert($table, $data);
                AuditLogger::log("catalogue.{$entity}_created", $entity, $id, null, $data, $actorId);
                return $id;
            }
            $before = $this->db->one("SELECT * FROM `$table` WHERE id = ?", [$id]) ?? throw ValidationException::withMessage(ucfirst($entity) . ' not found.');
            $this->db->exec(
                "UPDATE `$table` SET " . implode(', ', array_map(fn ($c) => "`$c` = ?", array_keys($data))) . ' WHERE id = ?',
                [...array_values($data), $id]
            );
            AuditLogger::log("catalogue.{$entity}_updated", $entity, $id, self::diffBefore($before, $data), $data, $actorId);
            return $id;
        });
    }

    public function uniqueSlug(string $table, string $source, ?int $ignoreId): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($source)), '-') ?: 'item';
        $base = substr($base, 0, 120);
        $slug = $base;
        for ($n = 2; $this->db->value("SELECT id FROM `$table` WHERE slug = ? AND id <> ?", [$slug, $ignoreId ?? 0]); $n++) {
            $slug = $base . '-' . $n;
        }
        return $slug;
    }

    /**
     * Parses "Label: value" lines into an ordered map.
     * @return array<string,string>
     */
    public static function parsePairs(string $text, string $field, int $max = 30): array
    {
        $out = [];
        foreach (preg_split('/\R/', $text) ?: [] as $i => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (!str_contains($line, ':')) {
                throw new ValidationException([$field => 'Line ' . ($i + 1) . ' must look like "Label: value".']);
            }
            [$k, $v] = array_map('trim', explode(':', $line, 2));
            if ($k === '' || $v === '' || mb_strlen($k) > 60 || mb_strlen($v) > 200) {
                throw new ValidationException([$field => 'Line ' . ($i + 1) . ' has an empty or too-long label or value.']);
            }
            $out[$k] = $v;
        }
        if (count($out) > $max) {
            throw new ValidationException([$field => "At most $max lines are allowed."]);
        }
        return $out;
    }

    private static function encodePairs(string $text, string $field): ?string
    {
        $pairs = self::parsePairs($text, $field);
        return $pairs ? json_encode($pairs, JSON_UNESCAPED_UNICODE) : null;
    }

    /** "Label: value" lines from stored JSON, for editing. */
    public static function pairsToText(?string $json): string
    {
        $arr = $json ? json_decode($json, true) : [];
        return is_array($arr) ? implode("\n", array_map(fn ($k, $v) => "$k: $v", array_keys($arr), $arr)) : '';
    }

    /** @param array<string,mixed> $before @param array<string,mixed> $after */
    private static function diffBefore(array $before, array $after): array
    {
        return array_intersect_key($before, $after);
    }
}
