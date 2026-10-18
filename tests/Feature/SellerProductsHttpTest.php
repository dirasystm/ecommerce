<?php

declare(strict_types=1);

namespace Waimart\Tests\Feature;

use Waimart\Core\Settings;

/** Phase 4 over HTTP: seller product pages, cross-seller attempts, roles, suspension and moderation permissions. */
final class SellerProductsHttpTest extends FeatureTestCase
{
    private int $cat;
    /** @var list<string> */
    private array $tmp = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cat = $this->db->insert('categories', ['name' => 'Crafts', 'slug' => 'crafts-' . bin2hex(random_bytes(2))]);
        Settings::set('product_moderation', '1');
    }

    protected function tearDown(): void
    {
        foreach ($this->db->all('SELECT path FROM product_images') as $i) {
            @unlink(\Waimart\Core\App::root() . '/public/' . $i['path']);
        }
        foreach ($this->tmp as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    /** @return array{seller:int,user:int,email:string} signed in as this seller member */
    private function sellerLogin(string $slug, string $role = 'owner'): array
    {
        $s = (int) ($this->db->value('SELECT id FROM sellers WHERE slug = ?', [$slug])
            ?: $this->db->insert('sellers', ['name' => ucwords(str_replace('-', ' ', $slug)), 'slug' => $slug]));
        $email = "$slug-$role@example.com";
        $u = $this->db->insert('users', ['email' => $email, 'password_hash' => password_hash('Secret123', PASSWORD_DEFAULT), 'first_name' => 'S', 'last_name' => 'L']);
        $this->db->insert('seller_users', ['seller_id' => $s, 'user_id' => $u, 'role' => $role]);
        $this->post('/logout', [], '/');
        $this->post('/login', ['email' => $email, 'password' => 'Secret123'], '/login');
        return ['seller' => $s, 'user' => $u, 'email' => $email];
    }

    private function png(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'wmpng') . '.png';
        imagepng(imagecreatetruecolor(30, 30), $path);
        $this->tmp[] = $path;
        return $path;
    }

    private function details(array $over = []): array
    {
        return $over + ['name' => 'Soapstone Bowl', 'category_id' => (string) $this->cat, 'short_description' => 'Carved Kisii soapstone.',
            'description' => 'Hand carved.', 'max_per_order' => '3', 'sku' => 'SB-1', 'price' => '1800', 'opening_stock' => '4', 'is_active' => '1'];
    }

    public function testSellerListsProductThatAppearsOnlyAfterApproval(): void
    {
        $me = $this->sellerLogin('kisii-carvers');
        $r = $this->post('/seller/products', $this->details(['seller_id' => '1', 'is_featured' => '1']), '/seller/products/new');
        $this->assertSame(303, $r['status'], $this->lastBody);
        $p = (array) $this->db->one('SELECT * FROM products ORDER BY id DESC LIMIT 1');
        $this->assertSame([$me['seller'], 0, 'draft'], [(int) $p['seller_id'], (int) $p['is_featured'], $p['moderation_status']]);
        $edit = $this->get('/seller/products/' . $p['id'])['body'];
        $this->assertStringContainsString('value="SB-1"', $edit, 'seller sees their own SKU, not the internal one');
        $this->assertStringNotContainsString('S' . $me['seller'] . '-SB-1', $edit);

        $this->post("/seller/products/{$p['id']}/images", ['alt_text' => 'Bowl'], "/seller/products/{$p['id']}", ['image' => $this->png()]);
        $this->post("/seller/products/{$p['id']}/submit", [], "/seller/products/{$p['id']}");
        $this->assertSame('pending', $this->db->value('SELECT moderation_status FROM products WHERE id = ?', [$p['id']]));
        $this->assertSame(404, $this->get('/product/' . $p['slug'])['status'], 'hidden until approved');

        $this->post('/logout', [], '/');
        $this->loginStaff('manager');
        $this->assertStringContainsString('Soapstone Bowl', $this->get('/admin/moderation/products')['body']);
        $this->post("/admin/moderation/products/{$p['id']}/approve", [], '/admin/moderation/products');
        $this->assertSame('published', $this->db->value('SELECT moderation_status FROM products WHERE id = ?', [$p['id']]));
        $this->assertSame(200, $this->get('/product/' . $p['slug'])['status']);
    }

    public function testEverySellerProductRouteRejectsAnotherSellersIds(): void
    {
        $a = $this->sellerLogin('shop-a');
        $this->post('/seller/products', $this->details(), '/seller/products/new');
        $pa = (int) $this->db->value('SELECT id FROM products WHERE seller_id = ?', [$a['seller']]);
        $va = (int) $this->db->value('SELECT id FROM product_variants WHERE product_id = ?', [$pa]);
        $this->post("/seller/products/$pa/images", [], "/seller/products/$pa", ['image' => $this->png()]);
        $ia = (int) $this->db->value('SELECT id FROM product_images WHERE product_id = ?', [$pa]);
        $snapshot = json_encode([$this->db->one('SELECT * FROM products WHERE id = ?', [$pa]), $this->db->one('SELECT * FROM inventory WHERE variant_id = ?', [$va]),
            $this->db->all('SELECT id FROM product_images WHERE product_id = ?', [$pa])]);

        $this->sellerLogin('shop-b');
        $this->assertSame(404, $this->get("/seller/products/$pa")['status']);
        $posts = [
            "/seller/products/$pa" => $this->details(['name' => 'Hijacked']),
            "/seller/products/$pa/submit" => [], "/seller/products/$pa/archive" => [], "/seller/products/$pa/restore" => [],
            "/seller/products/$pa/variants" => ['sku' => 'X', 'price' => '1'],
            "/seller/products/$pa/variants/$va" => ['sku' => 'X', 'price' => '1'],
            "/seller/products/$pa/variants/$va/default" => [],
            "/seller/products/$pa/variants/$va/stock" => ['action' => 'count', 'counted' => '0'],
            "/seller/products/$pa/images/$ia/delete" => [], "/seller/products/$pa/images/$ia/main" => [],
        ];
        foreach ($posts as $path => $data) {
            $this->assertSame(404, $this->post($path, $data, '/seller/products')['status'], "POST $path");
        }
        $this->assertSame(404, $this->post("/seller/products/$pa/images", [], '/seller/products', ['image' => $this->png()])['status']);
        $this->assertStringNotContainsString('Soapstone', $this->get('/seller/products')['body'], "B's list never shows A's products");
        $this->assertSame($snapshot, json_encode([$this->db->one('SELECT * FROM products WHERE id = ?', [$pa]), $this->db->one('SELECT * FROM inventory WHERE variant_id = ?', [$va]),
            $this->db->all('SELECT id FROM product_images WHERE product_id = ?', [$pa])]), 'nothing of A changed');
    }

    public function testStaffMembersManageStockButNotProductDetails(): void
    {
        $owner = $this->sellerLogin('team-shop');
        $this->post('/seller/products', $this->details(), '/seller/products/new');
        $p = (int) $this->db->value('SELECT id FROM products WHERE seller_id = ?', [$owner['seller']]);
        $v = (int) $this->db->value('SELECT id FROM product_variants WHERE product_id = ?', [$p]);

        $this->sellerLogin('team-shop', 'staff');
        $this->assertSame(200, $this->get("/seller/products/$p")['status']);
        $this->assertSame(403, $this->post("/seller/products/$p", $this->details(['name' => 'Changed']), "/seller/products/$p")['status']);
        $this->assertSame(403, $this->get('/seller/products/new')['status']);
        $this->assertSame(303, $this->post("/seller/products/$p/variants/$v/stock", ['action' => 'receive', 'quantity' => '6', 'reason' => 'purchase'], "/seller/products/$p")['status']);
        $this->assertSame(10, (int) $this->db->value('SELECT on_hand FROM inventory WHERE variant_id = ?', [$v]));
    }

    public function testSuspendedStoreCanLookButNotChangeAndItsProductsAreHidden(): void
    {
        $me = $this->sellerLogin('paused-shop');
        $this->post('/seller/products', $this->details(), '/seller/products/new');
        $p = (array) $this->db->one('SELECT * FROM products WHERE seller_id = ?', [$me['seller']]);
        $this->db->exec("UPDATE products SET moderation_status = 'published' WHERE id = ?", [$p['id']]);
        $v = (int) $this->db->value('SELECT id FROM product_variants WHERE product_id = ?', [$p['id']]);
        $this->assertSame(200, $this->get('/product/' . $p['slug'])['status']);

        $this->db->exec("UPDATE sellers SET status = 'suspended' WHERE id = ?", [$me['seller']]);
        $this->assertSame(200, $this->get('/seller/products')['status']);
        $this->assertSame(403, $this->post("/seller/products/{$p['id']}/variants/$v/stock", ['action' => 'receive', 'quantity' => '1', 'reason' => 'purchase'], '/seller/products')['status']);
        $this->assertSame(404, $this->get('/product/' . $p['slug'])['status']);
        $this->assertStringNotContainsString('Soapstone Bowl', $this->get('/search?q=soapstone')['body']);
    }

    public function testModerationNeedsPermission(): void
    {
        $this->loginStaff('support');
        $this->assertSame(403, $this->get('/admin/moderation/products')['status']);
        $this->post('/logout', [], '/');
        $this->loginStaff('inventory_clerk');   // catalogue.manage, but not products.moderate
        $this->assertSame(403, $this->get('/admin/moderation/products')['status']);
        $this->assertSame(200, $this->get('/admin/products')['status']);
    }
}
