<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Database;
use Waimart\Core\HttpException;
use Waimart\Core\Settings;
use Waimart\Core\ValidationException;

/**
 * Seller product management and Waimart's product moderation.
 *
 * Every seller method takes the seller id from SellerContext (never from the request) and first proves the
 * product/variant/image belongs to that seller — anything else is a 404, indistinguishable from "missing".
 * Catalogue rules themselves live in CatalogueAdminService; this class adds ownership, moderation and the
 * fields a seller may not touch (owner, featured flag, URL slug, moderation state).
 *
 * Moderation (setting `product_moderation`): 'required' → a submitted product waits in `pending` for staff;
 * 'none' → it is published on submission. With moderation required, changing what customers read or see on a
 * published product (name, category, brand, descriptions, specifications, images) sends it back to `pending`;
 * prices, stock and variant availability do not.
 */
final class SellerProductService
{
    public const CONTENT_FIELDS = ['name', 'category_id', 'brand_id', 'short_description', 'description', 'specifications'];

    public const STATUS_LABELS = [
        'draft' => ['badge-soft-muted', 'Draft'], 'pending' => ['badge-soft-warning', 'Waiting for approval'],
        'published' => ['badge-soft-success', 'Published'], 'rejected' => ['badge-soft-danger', 'Rejected'],
        'archived' => ['badge-soft-muted', 'Archived'],
    ];

    public static function badge(string $status): string
    {
        [$class, $label] = self::STATUS_LABELS[$status] ?? ['badge-soft-muted', $status];
        return '<span class="badge ' . $class . '">' . e($label) . '</span>';
    }

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? App::db();
    }

    private function catalogue(): CatalogueAdminService
    {
        return (new CatalogueAdminService($this->db))->actingAs('seller');
    }

    public static function moderationRequired(): bool
    {
        // Stored as 'required'/'none' (migration default) or '1'/'0' (settings form checkbox).
        return !in_array((string) Settings::get('product_moderation', 'required'), ['none', '0'], true);
    }

    /** Seller SKUs are stored as S{sellerId}-{CODE}: unique per seller, and no seller can probe another's codes. */
    public static function internalSku(int $sellerId, string $code): string
    {
        $code = strtoupper(trim($code));
        if ($code === '' || mb_strlen($code) > 50 || !preg_match('/^[A-Z0-9][A-Z0-9._-]*$/', $code)) {
            throw new ValidationException(['sku' => 'Your SKU may contain letters, numbers, dots, dashes and underscores (max 50).']);
        }
        return 'S' . $sellerId . '-' . $code;
    }

    public static function displaySku(string $sku): string
    {
        return (string) preg_replace('/^S\d+-/', '', $sku);
    }

    // ------------------------------------------------------------------ reading (seller-scoped)

    /** @return list<array<string,mixed>> */
    public function list(int $sellerId, ?string $status = null): array
    {
        $params = [$sellerId];
        $where = 'p.seller_id = ?';
        if ($status !== null) {
            $where .= ' AND p.moderation_status = ?';
            $params[] = $status;
        }
        return $this->db->all(
            "SELECT p.id, p.name, p.slug, p.is_active, p.moderation_status, p.moderation_note, p.updated_at, c.name AS category,
                    (SELECT path FROM product_images pi WHERE pi.product_id = p.id ORDER BY sort_order, id LIMIT 1) AS image,
                    (SELECT MIN(v.price) FROM product_variants v WHERE v.product_id = p.id AND v.is_active = 1) AS min_price,
                    (SELECT COUNT(*) FROM product_variants v WHERE v.product_id = p.id) AS variants,
                    (SELECT COALESCE(SUM(i.on_hand - i.reserved), 0) FROM product_variants v JOIN inventory i ON i.variant_id = v.id
                      WHERE v.product_id = p.id AND v.is_active = 1) AS available,
                    (SELECT COUNT(*) FROM product_variants v JOIN inventory i ON i.variant_id = v.id
                      WHERE v.product_id = p.id AND v.is_active = 1 AND i.on_hand - i.reserved <= i.low_stock_threshold) AS low_variants
               FROM products p JOIN categories c ON c.id = p.category_id
              WHERE $where ORDER BY p.updated_at DESC, p.id DESC",
            $params
        );
    }

    /** @return array<string,int> moderation status => count, plus 'all' and 'low_stock' */
    public function counts(int $sellerId): array
    {
        $c = array_map('intval', array_column($this->db->all(
            'SELECT moderation_status, COUNT(*) AS n FROM products WHERE seller_id = ? GROUP BY moderation_status', [$sellerId]
        ), 'n', 'moderation_status'));
        $c['all'] = array_sum($c);
        $c['low_stock'] = (int) $this->db->value(
            'SELECT COUNT(DISTINCT p.id) FROM products p JOIN product_variants v ON v.product_id = p.id AND v.is_active = 1
               JOIN inventory i ON i.variant_id = v.id
              WHERE p.seller_id = ? AND p.moderation_status <> \'archived\' AND i.on_hand - i.reserved <= i.low_stock_threshold',
            [$sellerId]
        );
        return $c;
    }

    /** @return array<string,mixed> the seller's own product, or 404 */
    public function owned(int $sellerId, int $productId): array
    {
        return $this->db->one('SELECT * FROM products WHERE id = ? AND seller_id = ?', [$productId, $sellerId])
            ?? throw HttpException::notFound();
    }

    /** @return list<array<string,mixed>> variants with stock, SKUs shown as the seller's own codes */
    public function variants(int $sellerId, int $productId): array
    {
        $this->owned($sellerId, $productId);
        $rows = $this->db->all(
            'SELECT v.*, i.on_hand, i.reserved, i.low_stock_threshold,
                    (SELECT GROUP_CONCAT(CONCAT(attr_name, ": ", attr_value) ORDER BY attr_name SEPARATOR "\n")
                       FROM variant_attributes a WHERE a.variant_id = v.id) AS attributes_text
               FROM product_variants v JOIN inventory i ON i.variant_id = v.id
              WHERE v.product_id = ? ORDER BY v.is_default DESC, v.sort_order, v.id',
            [$productId]
        );
        return array_map(fn ($v) => ['sku' => self::displaySku((string) $v['sku'])] + $v, $rows);
    }

    /** @return list<array<string,mixed>> */
    public function images(int $sellerId, int $productId): array
    {
        $this->owned($sellerId, $productId);
        return $this->db->all('SELECT * FROM product_images WHERE product_id = ? ORDER BY sort_order, id', [$productId]);
    }

    // ------------------------------------------------------------------ seller actions

    /**
     * New product (always starts as draft unless submitted straight away).
     * @param array<string,mixed> $in
     */
    public function create(int $sellerId, int $actorId, array $in, bool $submit = false): int
    {
        $in = $this->sellerInput($sellerId, $in, null);
        $in['sku'] = self::internalSku($sellerId, (string) ($in['sku'] ?? ''));
        $id = $this->catalogue()->createProduct($in, $actorId, $sellerId, 'draft');
        if ($submit) {
            $this->submit($sellerId, $id, $actorId);
        }
        return $id;
    }

    /** @param array<string,mixed> $in */
    public function update(int $sellerId, int $productId, int $actorId, array $in): void
    {
        $before = $this->owned($sellerId, $productId);
        $this->assertEditable($before);
        $this->catalogue()->updateProduct($productId, $this->sellerInput($sellerId, $in, $before), $actorId);
        $after = (array) $this->db->one('SELECT * FROM products WHERE id = ?', [$productId]);
        $changed = array_filter(self::CONTENT_FIELDS, fn ($f) => (string) ($before[$f] ?? '') !== (string) ($after[$f] ?? ''));
        if ($changed) {
            $this->contentChanged($before, $actorId, 'Edited: ' . implode(', ', $changed));
        }
    }

    /** @param array<string,mixed> $in */
    public function addVariant(int $sellerId, int $productId, int $actorId, array $in): int
    {
        $this->assertEditable($this->owned($sellerId, $productId));
        $in['sku'] = self::internalSku($sellerId, (string) ($in['sku'] ?? ''));
        return $this->catalogue()->addVariant($productId, $in, $actorId);
    }

    /** @param array<string,mixed> $in */
    public function updateVariant(int $sellerId, int $productId, int $variantId, int $actorId, array $in): void
    {
        $this->assertEditable($this->owned($sellerId, $productId));
        $this->ownedVariant($productId, $variantId);
        $in['sku'] = self::internalSku($sellerId, (string) ($in['sku'] ?? ''));
        $this->catalogue()->updateVariant($variantId, $in, $actorId);
    }

    public function setDefaultVariant(int $sellerId, int $productId, int $variantId, int $actorId): void
    {
        $this->assertEditable($this->owned($sellerId, $productId));
        $this->ownedVariant($productId, $variantId);
        $this->catalogue()->setDefaultVariant($productId, $variantId, $actorId);
    }

    /** @param array<string,mixed> $file */
    public function addImage(int $sellerId, int $productId, int $actorId, array $file, ?string $alt): int
    {
        $p = $this->owned($sellerId, $productId);
        $this->assertEditable($p);
        $id = $this->catalogue()->addImage($productId, $file, $alt, $actorId);
        $this->contentChanged($p, $actorId, 'Image added');
        return $id;
    }

    public function deleteImage(int $sellerId, int $productId, int $imageId, int $actorId): void
    {
        $p = $this->owned($sellerId, $productId);
        $this->assertEditable($p);
        $this->catalogue()->deleteImage($productId, $imageId, $actorId);
        $this->contentChanged($p, $actorId, 'Image removed');
    }

    public function makeMainImage(int $sellerId, int $productId, int $imageId, int $actorId): void
    {
        $this->assertEditable($this->owned($sellerId, $productId));
        $this->catalogue()->makeMainImage($productId, $imageId, $actorId);
    }

    /**
     * Stock for one of the seller's variants: receive, adjust (+/-) or set a counted level.
     * @param array<string,mixed> $in
     */
    public function changeStock(int $sellerId, int $productId, int $variantId, int $actorId, array $in): void
    {
        $this->owned($sellerId, $productId);
        $this->ownedVariant($productId, $variantId);
        $inv = new InventoryService($this->db);
        $note = trim((string) ($in['note'] ?? '')) ?: null;
        match ((string) ($in['action'] ?? '')) {
            'receive' => $inv->receive($variantId, (int) ($in['quantity'] ?? 0), (string) ($in['reason'] ?? 'purchase'), $note, $actorId, 'seller'),
            'adjust' => $inv->adjust($variantId, (int) ($in['delta'] ?? 0), (string) ($in['reason'] ?? ''), $note, $actorId, 'seller'),
            'count' => $inv->setCount($variantId, (int) ($in['counted'] ?? -1), $note, $actorId, 'seller'),
            default => throw ValidationException::withMessage('Choose a stock action.'),
        };
    }

    /** Draft or rejected → pending (moderation on) or published (moderation off). */
    public function submit(int $sellerId, int $productId, int $actorId): void
    {
        $p = $this->owned($sellerId, $productId);
        if (!in_array($p['moderation_status'], ['draft', 'rejected'], true)) {
            throw ValidationException::withMessage('Only a draft or rejected product can be submitted.');
        }
        $problems = [];
        if (!$this->db->value('SELECT 1 FROM product_images WHERE product_id = ?', [$productId])) {
            $problems[] = 'add at least one photo';
        }
        if (!$this->db->value('SELECT 1 FROM product_variants WHERE product_id = ? AND is_default = 1 AND is_active = 1', [$productId])) {
            $problems[] = 'make sure the default option is active';
        }
        if (trim((string) $p['description']) === '' && trim((string) $p['short_description']) === '') {
            $problems[] = 'add a description';
        }
        if ($problems) {
            throw ValidationException::withMessage('Before submitting, please ' . implode(' and ', $problems) . '.');
        }
        $to = self::moderationRequired() ? 'pending' : 'published';
        $this->setStatus($productId, (string) $p['moderation_status'], $to, $actorId, null,
            ['submitted_at' => date('Y-m-d H:i:s')] + ($to === 'published' ? ['moderated_at' => date('Y-m-d H:i:s'), 'moderated_by' => null] : []));
        AuditLogger::log('product.submitted', 'product', $productId, ['moderation_status' => $p['moderation_status']], ['moderation_status' => $to], $actorId);
    }

    /** Hides the product from the shop for good (it can be restored to a draft). */
    public function archive(int $sellerId, int $productId, int $actorId): void
    {
        $p = $this->owned($sellerId, $productId);
        if ($p['moderation_status'] === 'archived') {
            return;
        }
        $this->setStatus($productId, (string) $p['moderation_status'], 'archived', $actorId, null);
        AuditLogger::log('product.archived', 'product', $productId, ['moderation_status' => $p['moderation_status']], ['moderation_status' => 'archived'], $actorId);
    }

    public function restore(int $sellerId, int $productId, int $actorId): void
    {
        $p = $this->owned($sellerId, $productId);
        if ($p['moderation_status'] !== 'archived') {
            throw ValidationException::withMessage('Only an archived product can be restored.');
        }
        $this->setStatus($productId, 'archived', 'draft', $actorId, null);
        AuditLogger::log('product.restored', 'product', $productId, ['moderation_status' => 'archived'], ['moderation_status' => 'draft'], $actorId);
    }

    // ------------------------------------------------------------------ Waimart moderation (products.moderate)

    /** @return list<array<string,mixed>> */
    public function moderationQueue(string $status = 'pending'): array
    {
        return $this->db->all(
            'SELECT p.id, p.name, p.slug, p.moderation_status, p.moderation_note, p.submitted_at, p.moderated_at, s.id AS seller_id, s.name AS seller_name,
                    s.status AS seller_status, c.name AS category,
                    (SELECT path FROM product_images pi WHERE pi.product_id = p.id ORDER BY sort_order, id LIMIT 1) AS image,
                    (SELECT MIN(v.price) FROM product_variants v WHERE v.product_id = p.id AND v.is_active = 1) AS min_price
               FROM products p JOIN sellers s ON s.id = p.seller_id JOIN categories c ON c.id = p.category_id
              WHERE p.moderation_status = ? ORDER BY COALESCE(p.submitted_at, p.updated_at)' . ($status === 'pending' ? '' : ' DESC') . ' LIMIT 300',
            [$status]
        );
    }

    public function approve(int $productId, int $staffId, ?string $note = null): void
    {
        $p = $this->db->one('SELECT * FROM products WHERE id = ?', [$productId]) ?? throw HttpException::notFound();
        if ($p['moderation_status'] !== 'pending') {
            throw ValidationException::withMessage('Only a product waiting for approval can be approved.');
        }
        $this->setStatus($productId, 'pending', 'published', $staffId, $note, ['moderated_by' => $staffId, 'moderated_at' => date('Y-m-d H:i:s')]);
        AuditLogger::log('product.approved', 'product', $productId, ['moderation_status' => 'pending'], ['moderation_status' => 'published', 'note' => $note], $staffId);
        $this->notifySeller($p, 'product_approved', "Your product \"{$p['name']}\" is approved",
            "Your product \"{$p['name']}\" has been approved and is now visible to customers (while it is active and in stock).");
    }

    /** Reject a pending product, or take down a published one; the reason is shown to the seller. */
    public function reject(int $productId, int $staffId, string $reason): void
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw new ValidationException(['reason' => 'Give the seller a reason (5–500 characters).']);
        }
        $p = $this->db->one('SELECT * FROM products WHERE id = ?', [$productId]) ?? throw HttpException::notFound();
        if (!in_array($p['moderation_status'], ['pending', 'published'], true)) {
            throw ValidationException::withMessage('Only a pending or published product can be rejected.');
        }
        $this->setStatus($productId, (string) $p['moderation_status'], 'rejected', $staffId, $reason, ['moderated_by' => $staffId, 'moderated_at' => date('Y-m-d H:i:s')]);
        AuditLogger::log('product.rejected', 'product', $productId, ['moderation_status' => $p['moderation_status']], ['moderation_status' => 'rejected', 'reason' => $reason], $staffId);
        $this->notifySeller($p, 'product_rejected', "Your product \"{$p['name']}\" needs changes",
            "Your product \"{$p['name']}\" " . ($p['moderation_status'] === 'published' ? 'has been removed from the shop' : 'was not approved') . ".\n\nReason: $reason\n\n"
            . 'You can edit it and submit it again from the Seller Centre: ' . config('app.url') . '/seller/products/' . $productId);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Keeps seller input to what a seller may change. Owner, featured flag, URL slug and moderation fields
     * are platform-controlled and taken from the existing product (or defaults), whatever the request says.
     * @param array<string,mixed> $in
     * @param array<string,mixed>|null $existing
     * @return array<string,mixed>
     */
    private function sellerInput(int $sellerId, array $in, ?array $existing): array
    {
        unset($in['seller_id'], $in['moderation_status'], $in['moderation_note'], $in['moderated_by'], $in['moderated_at'], $in['submitted_at']);
        // Sellers choose only from categories and brands that are live in the shop.
        if (ctype_digit((string) ($in['category_id'] ?? '')) && !$this->db->value(
            'SELECT 1 FROM categories c LEFT JOIN categories p ON p.id = c.parent_id WHERE c.id = ? AND c.is_active = 1 AND (p.id IS NULL OR p.is_active = 1)',
            [(int) $in['category_id']]
        )) {
            throw new ValidationException(['category_id' => 'Select a valid category.']);
        }
        if (ctype_digit((string) ($in['brand_id'] ?? '')) && !$this->db->value('SELECT 1 FROM brands WHERE id = ? AND is_active = 1', [(int) $in['brand_id']])) {
            throw new ValidationException(['brand_id' => 'Select a valid brand.']);
        }
        $in['is_featured'] = (int) ($existing['is_featured'] ?? 0) === 1 ? '1' : '';
        $in['slug'] = $existing['slug'] ?? '';
        if ($existing === null && !array_key_exists('is_active', $in)) {
            $in['is_active'] = '1';
        }
        return $in;
    }

    /** @param array<string,mixed> $p */
    private function assertEditable(array $p): void
    {
        if ($p['moderation_status'] === 'archived') {
            throw ValidationException::withMessage('Restore this product before editing it.');
        }
    }

    private function ownedVariant(int $productId, int $variantId): void
    {
        if (!$this->db->value('SELECT 1 FROM product_variants WHERE id = ? AND product_id = ?', [$variantId, $productId])) {
            throw HttpException::notFound();
        }
    }

    /** With moderation on, a content change takes a published product back to review. @param array<string,mixed> $p */
    private function contentChanged(array $p, int $actorId, string $what): void
    {
        if ($p['moderation_status'] === 'published' && self::moderationRequired()) {
            $this->setStatus((int) $p['id'], 'published', 'pending', $actorId, null, ['submitted_at' => date('Y-m-d H:i:s')]);
            AuditLogger::log('product.resubmitted', 'product', (int) $p['id'], ['moderation_status' => 'published'], ['moderation_status' => 'pending', 'change' => $what], $actorId);
        }
    }

    /** Guarded moderation-state change. @param array<string,mixed> $sets */
    private function setStatus(int $productId, string $from, string $to, int $actorId, ?string $note, array $sets = []): void
    {
        $cols = ['moderation_status = ?', 'moderation_note = ?'];
        $params = [$to, $note];
        foreach ($sets as $k => $v) {
            $cols[] = "`$k` = ?";
            $params[] = $v;
        }
        array_push($params, $productId, $from);
        if ($this->db->exec('UPDATE products SET ' . implode(', ', $cols) . ' WHERE id = ? AND moderation_status = ?', $params) !== 1) {
            throw ValidationException::withMessage('This product changed in the meantime. Please reload and try again.');
        }
    }

    /** @param array<string,mixed> $p */
    private function notifySeller(array $p, string $template, string $subject, string $body): void
    {
        (new SellerNotifier($this->db))->send((int) $p['seller_id'], $template, $subject, $body);
    }
}
