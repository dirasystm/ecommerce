<?php

declare(strict_types=1);

namespace Waimart\Tests\Feature;

use Waimart\Core\Crypto;

/** Phase 5: public store pages and seller attribution across the storefront. */
final class StorePagesHttpTest extends FeatureTestCase
{
    private int $cat;
    private int $cat2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cat = $this->db->insert('categories', ['name' => 'Home Decor', 'slug' => 'home-decor-' . bin2hex(random_bytes(2))]);
        $this->cat2 = $this->db->insert('categories', ['name' => 'Jewellery', 'slug' => 'jewellery-' . bin2hex(random_bytes(2))]);
    }

    private function store(string $slug, array $over = []): int
    {
        return $this->db->insert('sellers', $over + ['name' => ucwords(str_replace('-', ' ', $slug)), 'slug' => $slug, 'status' => 'active',
            'approved_at' => '2026-03-01 10:00:00', 'description' => 'Handmade goods from Kisumu.', 'county_id' => 42, 'town' => 'Kisumu']);
    }

    /** A product for a seller; published and in stock unless stated. */
    private function product(int $sellerId, string $name, array $over = [], int $stock = 5): array
    {
        $pid = $this->db->insert('products', $over + ['seller_id' => $sellerId, 'category_id' => $this->cat, 'name' => $name,
            'slug' => strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name)) . '-' . bin2hex(random_bytes(2)), 'moderation_status' => 'published']);
        $vid = $this->db->insert('product_variants', ['product_id' => $pid, 'sku' => 'S' . $sellerId . '-' . strtoupper(bin2hex(random_bytes(3))), 'name' => 'Default', 'price' => '1200', 'is_default' => 1]);
        $this->db->insert('inventory', ['variant_id' => $vid, 'on_hand' => $stock, 'reserved' => 0]);
        return ['product' => $pid, 'variant' => $vid, 'slug' => (string) $this->db->value('SELECT slug FROM products WHERE id = ?', [$pid])];
    }

    public function testStoreShowsOnlyItsOwnLiveProducts(): void
    {
        $s = $this->store('lake-crafts');
        $other = $this->store('other-crafts');
        $this->product($s, 'Beaded Necklace');
        $this->product($s, 'Draft Lamp', ['moderation_status' => 'draft']);
        $this->product($s, 'Pending Vase', ['moderation_status' => 'pending']);
        $this->product($s, 'Unlisted Mat', ['is_active' => 0]);
        $this->product($other, 'Other Seller Bowl');

        $r = $this->get('/store/lake-crafts');
        $this->assertSame(200, $r['status']);
        $this->assertStringContainsString('Lake Crafts', $r['body']);
        $this->assertStringContainsString('Beaded Necklace', $r['body']);
        foreach (['Draft Lamp', 'Pending Vase', 'Unlisted Mat', 'Other Seller Bowl'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $r['body'], $hidden);
        }
        $this->assertStringContainsString('1 product shown', $r['body']);
        $this->assertStringContainsString('Selling on Ecommerce since March 2026', $r['body']);
        $this->assertStringContainsString('Kisumu, Kisumu', $r['body']);
        $this->assertSame(404, $this->get('/store/no-such-store')['status']);
        $this->assertSame(200, $this->get('/store/official-store')['status'], 'the Official Store has a page too');
        $old = $this->get('/store/waimart-official');
        $this->assertSame([301, '/store/official-store'], [$old['status'], parse_url((string) ($old['headers']['location'] ?? ''), PHP_URL_PATH)], 'the pre-rebrand address redirects');
    }

    public function testSuspendedStoresAndTheirProductsDisappear(): void
    {
        $s = $this->store('paused-crafts');
        $p = $this->product($s, 'Woven Basket');
        $this->assertSame(200, $this->get('/product/' . $p['slug'])['status']);
        $this->db->exec("UPDATE sellers SET status = 'suspended', suspension_reason = 'Internal compliance note' WHERE id = ?", [$s]);
        $this->assertSame(404, $this->get('/store/paused-crafts')['status']);
        $this->assertSame(404, $this->get('/product/' . $p['slug'])['status']);
    }

    public function testPrivateSellerInformationNeverAppears(): void
    {
        $owner = $this->db->insert('users', ['email' => 'owner-private@example.com', 'password_hash' => 'x', 'first_name' => 'Private', 'last_name' => 'Owner']);
        $s = $this->store('quiet-shop', ['legal_name' => 'Quiet Holdings Limited', 'public_email' => 'hello@quiet.example', 'public_phone' => '254711223344', 'show_contacts' => 0]);
        $this->db->insert('seller_users', ['seller_id' => $s, 'user_id' => $owner, 'role' => 'owner']);
        $this->db->insert('seller_payout_methods', ['seller_id' => $s, 'method' => 'bank', 'details_enc' => Crypto::encrypt('{"account_number":"9988776655"}'), 'masked' => 'KCB ••••6655']);
        $this->db->insert('seller_applications', ['application_number' => 'SA-PRIVATE1', 'user_id' => $owner, 'seller_id' => $s, 'status' => 'approved',
            'store_name' => 'Quiet Shop', 'store_slug' => 'quiet-shop', 'contact_name' => 'Private Owner', 'email' => 'owner-private@example.com',
            'phone' => '254700000001', 'business_type' => 'registered_business', 'business_description' => 'Private application text that is long enough.',
            'county_id' => 47, 'town' => 'Nairobi', 'business_address' => 'Plot 12 Secret Lane', 'registration_number' => 'PVT-123456']);
        $this->product($s, 'Quiet Candle');

        $body = $this->get('/store/quiet-shop')['body'];
        foreach (['Quiet Holdings Limited', 'owner-private@example.com', 'Private Owner', 'Plot 12 Secret Lane', 'PVT-123456', '6655', '9988776655',
                  'hello@quiet.example', '0711 223 344', 'Private application text'] as $secret) {
            $this->assertStringNotContainsString($secret, $body, "must not expose: $secret");
        }
        $this->db->exec('UPDATE sellers SET show_contacts = 1 WHERE id = ?', [$s]);
        $body = $this->get('/store/quiet-shop')['body'];
        $this->assertStringContainsString('hello@quiet.example', $body, 'contacts appear once the seller chooses to publish them');
        $this->assertStringContainsString('0711 223 344', $body);
    }

    public function testStoreTextIsEscaped(): void
    {
        $this->store('xss-shop', ['description' => '<script>alert(1)</script><b>bold</b>']);
        $body = $this->get('/store/xss-shop')['body'];
        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body);
    }

    public function testStoreRatingCountsApprovedVerifiedReviewsOnly(): void
    {
        $s = $this->store('rated-shop');
        $p = $this->product($s, 'Rated Bowl');
        $buyer = $this->db->insert('users', ['email' => 'buyer-r@example.com', 'password_hash' => 'x', 'first_name' => 'B', 'last_name' => 'R']);
        foreach ([[5, 'approved'], [4, 'approved'], [1, 'pending'], [1, 'rejected']] as $i => [$stars, $status]) {
            $o = $this->makeOrder($p['variant']);
            $this->db->insert('reviews', ['product_id' => $p['product'], 'user_id' => $buyer, 'order_item_id' => $o['item_id'], 'rating' => $stars, 'status' => $status]);
        }
        $body = $this->get('/store/rated-shop')['body'];
        $this->assertStringContainsString('4.5 from 2 verified reviews', $body);
        $this->assertStringContainsString('4.5 (2 reviews)', $this->get('/product/' . $p['slug'])['body'], 'seller box on the product page');
    }

    public function testCategoryFilterAndSearchStayInsideTheStore(): void
    {
        $s = $this->store('mixed-shop');
        $other = $this->store('rival-shop');
        $this->product($s, 'Silver Bangle', ['category_id' => $this->cat2]);
        $this->product($s, 'Clay Pot');
        $this->product($other, 'Silver Earrings', ['category_id' => $this->cat2]);

        $all = $this->get('/store/mixed-shop')['body'];
        $this->assertStringContainsString('Jewellery (1)', $all, 'store categories with counts');
        $this->assertStringContainsString('Home Decor (1)', $all);

        $jewellery = $this->get('/store/mixed-shop?category=' . $this->cat2)['body'];
        $this->assertStringContainsString('Silver Bangle', $jewellery);
        $this->assertStringNotContainsString('Clay Pot', $jewellery);
        $this->assertStringNotContainsString('Silver Earrings', $jewellery);
        $this->assertStringContainsString('name="category" value="' . $this->cat2 . '"', $jewellery, 'sorting/filters keep the category');

        $search = $this->get('/store/mixed-shop?q=silver')['body'];
        $this->assertStringContainsString('Silver Bangle', $search);
        $this->assertStringNotContainsString('Silver Earrings', $search, "another store's match is excluded");

        $ignored = $this->get('/store/mixed-shop?category=999999')['body'];
        $this->assertStringContainsString('Clay Pot', $ignored, 'an unknown category is ignored, not an error');
    }

    public function testSoldByAppearsOnProductPageCardsAndCart(): void
    {
        $s = $this->store('kitenge-house', ['is_verified' => 1]);
        $p = $this->product($s, 'Kitenge Tote');
        $page = $this->get('/product/' . $p['slug'])['body'];
        $this->assertStringContainsString('Sold by <a href="/store/kitenge-house">Kitenge House</a>', $page);
        $this->assertStringContainsString('Verified seller', $page);
        $internal = (string) $this->db->value('SELECT sku FROM product_variants WHERE id = ?', [$p['variant']]);
        $this->assertStringNotContainsString($internal, $page, 'the internal S{seller}- SKU prefix is never shown to customers');
        $this->assertStringContainsString('<span data-sku>' . substr($internal, strlen('S' . $s . '-')) . '</span>', $page);

        $listing = $this->get('/category/' . $this->db->value('SELECT slug FROM categories WHERE id = ?', [$this->cat]))['body'];
        $this->assertStringContainsString('Sold by Kitenge House', $listing, 'cards name marketplace sellers');

        $official = $this->makeVariant(3);
        $this->db->exec('UPDATE products SET category_id = ? WHERE id = ?', [$this->cat, $official['product_id']]);
        $offSlug = (string) $this->db->value('SELECT slug FROM products WHERE id = ?', [$official['product_id']]);
        $this->assertStringContainsString('Ecommerce Official Store', $this->get('/product/' . $offSlug)['body']);

        $this->post('/cart/add', ['variant_id' => (string) $p['variant'], 'quantity' => '1'], '/product/' . $p['slug']);
        $this->assertStringContainsString('Sold by <a href="/store/kitenge-house">Kitenge House</a>', $this->get('/cart')['body']);
    }
}
