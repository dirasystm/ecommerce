<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Crypto;
use Waimart\Core\Database;
use Waimart\Core\Money;
use Waimart\Core\ValidationException;
use Waimart\Repositories\CatalogueRepository;

/**
 * Shopping carts (D4). Carts never reserve stock — reservation happens only when checkout
 * creates an order (Phase 6). Every read re-validates lines against live catalogue data.
 *
 * Guests: a random token in an HttpOnly cookie; only its keyed hash is stored.
 * Registered users: one persistent cart per user. On login the guest cart is merged in,
 * capped at what can actually be bought.
 */
final class CartService
{
    public const COOKIE = 'wm_cart';
    private const COOKIE_DAYS = 30;

    private Database $db;
    private ?int $cartId = null;

    public function __construct(private ?int $userId, private ?string $guestToken, ?Database $db = null)
    {
        $this->db = $db ?? App::db();
    }

    /** Cart for the current visitor (session user or guest cookie). */
    public static function current(): self
    {
        $token = $_COOKIE[self::COOKIE] ?? null;
        $token = is_string($token) && preg_match('/^[A-Za-z0-9_-]{43}$/', $token) ? $token : null;
        return new self(\Waimart\Core\Auth::id(), $token);
    }

    public function guestToken(): ?string
    {
        return $this->guestToken;
    }

    /**
     * Adds units, capped at min(available, max per order). Never exceeds what can be bought.
     * @return array{quantity:int,added:int,message:string}
     */
    public function add(int $variantId, int $qty): array
    {
        if ($qty < 1 || $qty > 100) {
            throw new ValidationException(['quantity' => 'Choose a quantity between 1 and 100.']);
        }
        $v = $this->purchasable($variantId);
        $limit = $this->limitFor($v);
        if ($limit <= 0) {
            throw ValidationException::withMessage('Sorry, this item is out of stock.');
        }
        $cartId = $this->cartId(true);
        $current = (int) $this->db->value('SELECT quantity FROM cart_items WHERE cart_id = ? AND variant_id = ?', [$cartId, $variantId]);
        $new = min($current + $qty, $limit);
        if ($new <= $current) {
            throw ValidationException::withMessage(sprintf(
                'You already have the maximum of %d for "%s" in your cart.', $limit, $v['product_name']
            ));
        }
        $this->db->exec(
            'INSERT INTO cart_items (cart_id, variant_id, quantity) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)',
            [$cartId, $variantId, $new]
        );
        $this->touch($cartId);
        $added = $new - $current;
        $message = $added < $qty
            ? sprintf('Only %d more could be added — %s is limited to %d per order or by available stock.', $added, $v['product_name'], $limit)
            : sprintf('"%s" added to your cart.', $v['product_name']);
        return ['quantity' => $new, 'added' => $added, 'message' => $message];
    }

    /** Sets a line quantity (0 removes). Capped like add(). @return int resulting quantity */
    public function setQuantity(int $variantId, int $qty): int
    {
        $cartId = $this->cartId(false);
        if ($cartId === null || !$this->db->value('SELECT 1 FROM cart_items WHERE cart_id = ? AND variant_id = ?', [$cartId, $variantId])) {
            throw ValidationException::withMessage('That item is not in your cart.');
        }
        if ($qty <= 0) {
            $this->remove($variantId);
            return 0;
        }
        $v = $this->variant($variantId);
        $limit = $v ? $this->limitFor($v) : 0;
        if ($limit <= 0) {
            throw ValidationException::withMessage('This item is no longer available. Please remove it from your cart.');
        }
        $new = min($qty, $limit, 100);
        $this->db->exec('UPDATE cart_items SET quantity = ? WHERE cart_id = ? AND variant_id = ?', [$new, $cartId, $variantId]);
        $this->touch($cartId);
        return $new;
    }

    public function remove(int $variantId): void
    {
        $cartId = $this->cartId(false);
        if ($cartId !== null) {
            $this->db->exec('DELETE FROM cart_items WHERE cart_id = ? AND variant_id = ?', [$cartId, $variantId]);
        }
    }

    public function clear(): void
    {
        $cartId = $this->cartId(false);
        if ($cartId !== null) {
            $this->db->exec('DELETE FROM cart_items WHERE cart_id = ?', [$cartId]);
        }
    }

    public function count(): int
    {
        $cartId = $this->cartId(false);
        return $cartId === null ? 0 : (int) $this->db->value('SELECT COALESCE(SUM(quantity), 0) FROM cart_items WHERE cart_id = ?', [$cartId]);
    }

    /**
     * Live, validated view of the cart.
     * @return array{lines:list<array<string,mixed>>,subtotal:string,item_count:int,has_issues:bool,is_empty:bool}
     */
    public function summary(): array
    {
        $cartId = $this->cartId(false);
        $rows = $cartId === null ? [] : $this->db->all(
            'SELECT ci.variant_id, ci.quantity, v.sku, v.name AS variant_name, v.price, v.compare_at_price, v.is_active AS v_active,
                    p.id AS product_id, p.name AS product_name, p.slug, ' . CatalogueRepository::PUBLIC_EXPR . ' AS p_active, p.max_per_order,
                    p.seller_id, ps.name AS seller_name, ps.slug AS seller_slug, ps.is_platform AS seller_is_platform,
                    c.is_active AS c_active, (i.on_hand - i.reserved) AS available, fs.sale_price,
                    (SELECT pi.path FROM product_images pi WHERE pi.product_id = p.id ORDER BY pi.sort_order, pi.id LIMIT 1) AS image,
                    (SELECT COUNT(*) FROM product_variants v2 WHERE v2.product_id = p.id AND v2.is_active = 1) AS variant_count
               FROM cart_items ci
               JOIN product_variants v ON v.id = ci.variant_id
               JOIN products p ON p.id = v.product_id
               LEFT JOIN sellers ps ON ps.id = p.seller_id
               JOIN categories c ON c.id = p.category_id
               JOIN inventory i ON i.variant_id = v.id
               LEFT JOIN (' . PricingService::ACTIVE_FLASH_SQL . ') fs ON fs.variant_id = v.id
              WHERE ci.cart_id = ?
              ORDER BY ci.added_at, ci.variant_id',
            [$cartId]
        );

        $lines = [];
        $subtotal = '0.00';
        $count = 0;
        $issues = false;
        foreach ($rows as $r) {
            $pricing = PricingService::resolve((string) $r['price'], $r['compare_at_price'] !== null ? (string) $r['compare_at_price'] : null, $r['sale_price'] !== null ? (string) $r['sale_price'] : null);
            $qty = (int) $r['quantity'];
            $available = max(0, (int) $r['available']);
            $limit = min($available, (int) $r['max_per_order']);
            $issue = null;
            if (!(int) $r['v_active'] || !(int) $r['p_active'] || !(int) $r['c_active']) {
                $issue = ['code' => 'unavailable', 'message' => 'This item is no longer sold. Please remove it.'];
            } elseif ($available <= 0) {
                $issue = ['code' => 'out_of_stock', 'message' => 'Out of stock. Please remove it to continue.'];
            } elseif ($qty > $limit) {
                $issue = ['code' => 'limited', 'allowed' => $limit, 'message' => $limit < (int) $r['max_per_order']
                    ? "Only $limit available. Please reduce the quantity."
                    : "Limit of $limit per order. Please reduce the quantity."];
            }
            $lineTotal = Money::mul($pricing['price'], $qty);
            if ($issue === null) {
                $subtotal = Money::add($subtotal, $lineTotal);
            } else {
                $issues = true;
            }
            $count += $qty;
            $lines[] = [
                'variant_id' => (int) $r['variant_id'],
                'product_id' => (int) $r['product_id'],
                'name' => $r['product_name'],
                'variant_name' => (int) $r['variant_count'] > 1 ? $r['variant_name'] : null,
                'sku' => $r['sku'],
                'slug' => $r['slug'],
                'seller_name' => $r['seller_name'],
                'seller_slug' => $r['seller_slug'],
                'seller_id' => (int) $r['seller_id'],
                'seller_is_platform' => (int) $r['seller_is_platform'] === 1,
                'image' => $r['image'],
                'quantity' => $qty,
                'max' => max(1, $limit),
                'available' => $available,
                'line_total' => $lineTotal,
                'issue' => $issue,
            ] + $pricing;
        }
        return ['lines' => $lines, 'subtotal' => $subtotal, 'item_count' => $count, 'has_issues' => $issues, 'is_empty' => $lines === []];
    }

    /**
     * Merges a guest cart into the user's cart after login (D4): quantities are summed but capped
     * at available stock and the per-order limit. The guest cart is deleted.
     * @return list<string> messages about adjusted lines
     */
    public static function mergeGuestIntoUser(int $userId, ?string $guestToken, ?Database $db = null): array
    {
        $db ??= App::db();
        if ($guestToken === null) {
            return [];
        }
        return $db->transaction(function () use ($db, $userId, $guestToken) {
            $guestCart = $db->one('SELECT id FROM carts WHERE token_hash = ? AND user_id IS NULL FOR UPDATE', [Crypto::hash($guestToken)]);
            if ($guestCart === null) {
                return [];
            }
            $user = new self($userId, null, $db);
            $userCartId = $user->cartId(true);
            $messages = [];
            foreach ($db->all('SELECT variant_id, quantity FROM cart_items WHERE cart_id = ?', [$guestCart['id']]) as $item) {
                $v = $user->variant((int) $item['variant_id']);
                $limit = $v ? $user->limitFor($v) : 0;
                $existing = (int) $db->value('SELECT quantity FROM cart_items WHERE cart_id = ? AND variant_id = ?', [$userCartId, $item['variant_id']]);
                $wanted = $existing + (int) $item['quantity'];
                $new = min($wanted, max($limit, $existing));
                if ($limit <= 0 && $existing === 0) {
                    $messages[] = sprintf('"%s" is no longer available and was not added.', $v['product_name'] ?? 'An item');
                    continue;
                }
                if ($new < $wanted) {
                    $messages[] = sprintf('"%s" quantity was limited to %d.', $v['product_name'] ?? 'An item', $new);
                }
                $db->exec(
                    'INSERT INTO cart_items (cart_id, variant_id, quantity) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)',
                    [$userCartId, $item['variant_id'], $new]
                );
            }
            $db->exec('DELETE FROM carts WHERE id = ?', [$guestCart['id']]);
            return $messages;
        });
    }

    /** Sends/clears the guest cookie (no-op on CLI). */
    public static function setCookie(?string $token): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }
        setcookie(self::COOKIE, $token ?? '', [
            'expires' => $token === null ? time() - 3600 : time() + self::COOKIE_DAYS * 86400,
            'path' => rtrim((string) config('app.base_path', ''), '/') . '/',
            'secure' => (bool) config('session.secure', false),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    // ------------------------------------------------------------------ internals

    private function cartId(bool $create): ?int
    {
        if ($this->cartId !== null) {
            return $this->cartId;
        }
        if ($this->userId !== null) {
            $id = $this->db->value('SELECT id FROM carts WHERE user_id = ?', [$this->userId]);
            if (!$id && $create) {
                $this->db->exec('INSERT IGNORE INTO carts (user_id) VALUES (?)', [$this->userId]);
                $id = $this->db->value('SELECT id FROM carts WHERE user_id = ?', [$this->userId]);
            }
        } else {
            $id = $this->guestToken !== null ? $this->db->value('SELECT id FROM carts WHERE token_hash = ? AND user_id IS NULL', [Crypto::hash($this->guestToken)]) : null;
            if (!$id && $create) {
                $this->guestToken = Crypto::token(32);
                $id = $this->db->insert('carts', ['token_hash' => Crypto::hash($this->guestToken)]);
                self::setCookie($this->guestToken);
            }
        }
        return $this->cartId = $id ? (int) $id : null;
    }

    private function touch(int $cartId): void
    {
        $this->db->exec('UPDATE carts SET updated_at = NOW() WHERE id = ?', [$cartId]);
    }

    /** @return array<string,mixed>|null */
    private function variant(int $variantId): ?array
    {
        return $this->db->one(
            'SELECT v.id, v.is_active, ' . CatalogueRepository::PUBLIC_EXPR . ' AS p_active, c.is_active AS c_active, p.name AS product_name, p.max_per_order,
                    (i.on_hand - i.reserved) AS available
               FROM product_variants v JOIN products p ON p.id = v.product_id
               LEFT JOIN sellers ps ON ps.id = p.seller_id
               JOIN categories c ON c.id = p.category_id JOIN inventory i ON i.variant_id = v.id
              WHERE v.id = ?',
            [$variantId]
        );
    }

    /** @return array<string,mixed> */
    private function purchasable(int $variantId): array
    {
        $v = $this->variant($variantId);
        if ($v === null || !(int) $v['is_active'] || !(int) $v['p_active'] || !(int) $v['c_active']) {
            throw ValidationException::withMessage('This item is not available.');
        }
        return $v;
    }

    /** @param array<string,mixed> $v */
    private function limitFor(array $v): int
    {
        if (!(int) $v['is_active'] || !(int) $v['p_active'] || !(int) $v['c_active']) {
            return 0;
        }
        return max(0, min((int) $v['available'], (int) $v['max_per_order']));
    }
}
