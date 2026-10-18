<?php

declare(strict_types=1);

namespace Waimart\Tests\Integration;

use Waimart\Core\ValidationException;
use Waimart\Services\CartService;

final class CartServiceTest extends DbTestCase
{
    private function guest(?string $token = null): CartService
    {
        return new CartService(null, $token, $this->db);
    }

    private function user(): int
    {
        return $this->db->insert('users', ['email' => 'u' . random_int(1, 99999) . '@x.co', 'password_hash' => 'x', 'first_name' => 'U', 'last_name' => 'S']);
    }

    public function testGuestCartPersistsByTokenAndNeverReservesStock(): void
    {
        $v = $this->makeVariant(10)['variant_id'];
        $cart = $this->guest();
        $cart->add($v, 2);
        $token = $cart->guestToken();
        $this->assertNotNull($token);

        // A later request with the same cookie sees the same cart.
        $this->assertSame(2, $this->guest($token)->count());
        // A different/forged token sees nothing.
        $this->assertSame(0, $this->guest(str_repeat('A', 43))->count());
        // Carts do not touch inventory.
        $this->assertSame(0, (int) $this->db->value('SELECT reserved FROM inventory WHERE variant_id = ?', [$v]));
        // Only the hash is stored.
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM carts WHERE token_hash = ?', [$token]));
    }

    public function testAddIsCappedByStockAndPerOrderLimit(): void
    {
        $v = $this->makeVariant(3)['variant_id'];
        $cart = $this->guest();
        $r = $cart->add($v, 5);
        $this->assertSame(3, $r['quantity']);
        $this->assertStringContainsString('Only 3 more', $r['message']);
        $this->expectException(ValidationException::class);
        $cart->add($v, 1);
    }

    public function testMaxPerOrderLimit(): void
    {
        $p = $this->makeVariant(50);
        $this->db->exec('UPDATE products SET max_per_order = 2 WHERE id = ?', [$p['product_id']]);
        $this->assertSame(2, $this->guest()->add($p['variant_id'], 10)['quantity']);
    }

    public function testCannotAddUnavailableItems(): void
    {
        $out = $this->makeVariant(0)['variant_id'];
        $inactive = $this->makeVariant(5);
        $this->db->exec('UPDATE products SET is_active = 0 WHERE id = ?', [$inactive['product_id']]);
        $reserved = $this->makeVariant(2)['variant_id'];
        $this->db->exec('UPDATE inventory SET reserved = 2 WHERE variant_id = ?', [$reserved]);

        $rejected = 0;
        foreach ([$out, $inactive['variant_id'], $reserved, 999999] as $v) {
            try {
                $this->guest()->add($v, 1);
                $this->fail("Variant $v should not be addable");
            } catch (ValidationException) {
                $rejected++;
            }
        }
        $this->assertSame(4, $rejected);
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM cart_items'));
    }

    public function testSummaryFlagsChangesAfterAddingAndExcludesThemFromTotal(): void
    {
        $a = $this->makeVariant(10, '1000.00');
        $b = $this->makeVariant(10, '250.50');
        $cart = $this->guest();
        $cart->add($a['variant_id'], 2);
        $cart->add($b['variant_id'], 4);
        $s = $cart->summary();
        $this->assertSame('3002.00', $s['subtotal']);
        $this->assertFalse($s['has_issues']);

        // Someone else's order reserves stock; item B can now only supply 1.
        $this->db->exec('UPDATE inventory SET reserved = 9 WHERE variant_id = ?', [$b['variant_id']]);
        $s = $cart->summary();
        $this->assertTrue($s['has_issues']);
        $lineB = array_values(array_filter($s['lines'], fn ($l) => $l['variant_id'] === $b['variant_id']))[0];
        $this->assertSame('limited', $lineB['issue']['code']);
        $this->assertSame(1, $lineB['issue']['allowed']);
        $this->assertSame('2000.00', $s['subtotal'], 'problem lines excluded from totals');

        // Product A deactivated.
        $this->db->exec('UPDATE products SET is_active = 0 WHERE id = ?', [$a['product_id']]);
        $lineA = array_values(array_filter($cart->summary()['lines'], fn ($l) => $l['variant_id'] === $a['variant_id']))[0];
        $this->assertSame('unavailable', $lineA['issue']['code']);
    }

    public function testSetQuantityCapsAndZeroRemoves(): void
    {
        $v = $this->makeVariant(4)['variant_id'];
        $cart = $this->guest();
        $cart->add($v, 1);
        $this->assertSame(4, $cart->setQuantity($v, 99));
        $this->assertSame(0, $cart->setQuantity($v, 0));
        $this->assertTrue($cart->summary()['is_empty']);
    }

    public function testMergeOnLoginSumsButNeverExceedsStock(): void
    {
        $uid = $this->user();
        $shared = $this->makeVariant(5)['variant_id'];
        $guestOnly = $this->makeVariant(10)['variant_id'];
        $gone = $this->makeVariant(3)['variant_id'];

        (new CartService($uid, null, $this->db))->add($shared, 3);
        $guest = $this->guest();
        $guest->add($shared, 4);
        $guest->add($guestOnly, 2);
        $guest->add($gone, 1);
        $this->db->exec('UPDATE inventory SET reserved = 3 WHERE variant_id = ?', [$gone]);   // sold out meanwhile

        $messages = CartService::mergeGuestIntoUser($uid, $guest->guestToken(), $this->db);
        $userCart = new CartService($uid, null, $this->db);
        $qty = array_column($userCart->summary()['lines'], 'quantity', 'variant_id');
        $this->assertSame(5, $qty[$shared], '3 + 4 capped at 5 in stock');
        $this->assertSame(2, $qty[$guestOnly]);
        $this->assertArrayNotHasKey($gone, $qty);
        $this->assertCount(2, $messages);
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM carts WHERE user_id IS NULL'), 'guest cart removed');

        // Merging again (e.g. replayed login) changes nothing.
        $this->assertSame([], CartService::mergeGuestIntoUser($uid, $guest->guestToken(), $this->db));
    }
}
