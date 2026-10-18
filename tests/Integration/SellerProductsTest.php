<?php

declare(strict_types=1);

namespace Waimart\Tests\Integration;

use Waimart\Core\HttpException;
use Waimart\Core\Settings;
use Waimart\Core\ValidationException;
use Waimart\Repositories\CatalogueRepository;
use Waimart\Repositories\ProductListing;
use Waimart\Repositories\ProductRepository;
use Waimart\Services\CartService;
use Waimart\Services\CatalogueAdminService;
use Waimart\Services\SellerProductService;

/** Phase 4: seller product ownership, moderation, storefront visibility and seller stock. */
final class SellerProductsTest extends DbTestCase
{
    private SellerProductService $svc;
    private int $cat;
    private int $staff;
    /** @var list<string> */
    private array $tmp = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new SellerProductService($this->db);
        $this->cat = $this->db->insert('categories', ['name' => 'Bags', 'slug' => 'bags-' . bin2hex(random_bytes(2))]);
        $this->staff = $this->db->insert('users', ['email' => 'mod@waimart.test', 'password_hash' => 'x', 'first_name' => 'M', 'last_name' => 'O', 'is_staff' => 1]);
        Settings::set('product_moderation', 'required');
    }

    protected function tearDown(): void
    {
        foreach ($this->db->all('SELECT path FROM product_images') as $i) {
            @unlink(\Waimart\Core\App::root() . '/public/' . $i['path']);
        }
        foreach ($this->tmp as $f) {
            @unlink($f);
        }
        Settings::set('product_moderation', 'required');
        parent::tearDown();
    }

    /** @return array{seller:int,user:int} */
    private function seller(string $slug): array
    {
        $s = $this->db->insert('sellers', ['name' => ucwords(str_replace('-', ' ', $slug)), 'slug' => $slug]);
        $u = $this->db->insert('users', ['email' => "$slug@example.com", 'password_hash' => 'x', 'first_name' => 'S', 'last_name' => 'L']);
        $this->db->insert('seller_users', ['seller_id' => $s, 'user_id' => $u, 'role' => 'owner']);
        return ['seller' => $s, 'user' => $u];
    }

    private function input(array $over = []): array
    {
        return $over + ['name' => 'Kiondo Basket', 'category_id' => (string) $this->cat, 'short_description' => 'Handwoven sisal basket.',
            'description' => 'Woven by hand in Machakos.', 'max_per_order' => '5', 'sku' => 'KB-01', 'price' => '2500', 'opening_stock' => '8'];
    }

    private function image(): array
    {
        $im = imagecreatetruecolor(40, 40);
        $path = tempnam(sys_get_temp_dir(), 'wmimg');
        imagepng($im, $path);
        $this->tmp[] = $path;
        return ['name' => 'p.png', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => filesize($path)];
    }

    /** @return array{seller:int,user:int,product:int,variant:int} a published seller product */
    private function published(string $slug = 'kiondo-house'): array
    {
        $s = $this->seller($slug);
        $p = $this->svc->create($s['seller'], $s['user'], $this->input());
        $this->svc->addImage($s['seller'], $p, $s['user'], $this->image(), 'Front');
        $this->svc->submit($s['seller'], $p, $s['user']);
        $this->svc->approve($p, $this->staff);
        return $s + ['product' => $p, 'variant' => (int) $this->db->value('SELECT id FROM product_variants WHERE product_id = ?', [$p])];
    }

    private function visible(int $productId): bool
    {
        $slug = (string) $this->db->value('SELECT slug FROM products WHERE id = ?', [$productId]);
        $page = (new ProductRepository($this->db))->findActiveBySlug($slug) !== null;
        $listing = in_array($productId, array_column((new ProductListing($this->db))->find(['category_ids' => [$this->cat]])['items'], 'id'), true);
        $cards = (new ProductRepository($this->db))->cardsByIds([$productId]) !== [];
        $this->assertSame($page, $listing, 'product page and listings agree');
        $this->assertSame($page, $cards, 'product page and cards agree');
        return $page;
    }

    public function testDraftToPublishedLifecycleAndVisibility(): void
    {
        $s = $this->seller('kiondo-house');
        $p = $this->svc->create($s['seller'], $s['user'], $this->input());
        $row = (array) $this->db->one('SELECT * FROM products WHERE id = ?', [$p]);
        $this->assertSame([$s['seller'], 'draft'], [(int) $row['seller_id'], $row['moderation_status']]);
        $this->assertSame('S' . $s['seller'] . '-KB-01', $this->db->value('SELECT sku FROM product_variants WHERE product_id = ?', [$p]));
        $this->assertSame('seller', $this->db->value("SELECT actor_type FROM stock_movements WHERE reason = 'opening' ORDER BY id DESC LIMIT 1"));
        $this->assertFalse($this->visible($p), 'drafts are hidden');

        try {
            $this->svc->submit($s['seller'], $p, $s['user']);
            $this->fail('a product without photos cannot be submitted');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('photo', $e->getMessage());
        }
        $this->svc->addImage($s['seller'], $p, $s['user'], $this->image(), null);
        $this->svc->submit($s['seller'], $p, $s['user']);
        $this->assertSame('pending', $this->db->value('SELECT moderation_status FROM products WHERE id = ?', [$p]));
        $this->assertFalse($this->visible($p), 'pending products are hidden');

        $this->svc->approve($p, $this->staff);
        $this->assertSame('published', $this->db->value('SELECT moderation_status FROM products WHERE id = ?', [$p]));
        $this->assertTrue($this->visible($p));
        $variant = (int) $this->db->value('SELECT id FROM product_variants WHERE product_id = ?', [$p]);
        $cart = new CartService(null, null, $this->db);
        $cart->add($variant, 1);
        $this->assertFalse($cart->summary()['has_issues']);
        $this->assertSame(['product.submitted', 'product.approved'],
            array_column($this->db->all("SELECT action FROM audit_logs WHERE entity_type = 'product' AND action LIKE 'product.%' ORDER BY id"), 'action'));
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM notifications WHERE template = 'product_approved'"));
    }

    public function testWithoutModerationSubmissionPublishesImmediately(): void
    {
        Settings::set('product_moderation', '0');
        $s = $this->seller('fast-shop');
        $p = $this->svc->create($s['seller'], $s['user'], $this->input());
        $this->svc->addImage($s['seller'], $p, $s['user'], $this->image(), null);
        $this->svc->submit($s['seller'], $p, $s['user']);
        $this->assertTrue($this->visible($p));
    }

    public function testContentEditsGoBackToReviewButPriceAndStockDoNot(): void
    {
        $x = $this->published();
        $this->svc->updateVariant($x['seller'], $x['product'], $x['variant'], $x['user'], ['sku' => 'KB-01', 'price' => '2300', 'variant_active' => '1']);
        $this->svc->changeStock($x['seller'], $x['product'], $x['variant'], $x['user'], ['action' => 'receive', 'quantity' => '5', 'reason' => 'purchase']);
        $this->assertTrue($this->visible($x['product']), 'price and stock changes keep the product live');
        $this->assertSame('2300.00', $this->db->value('SELECT price FROM product_variants WHERE id = ?', [$x['variant']]));

        $this->svc->update($x['seller'], $x['product'], $x['user'], $this->input(['name' => 'Kiondo Basket — Large', 'is_active' => '1']));
        $this->assertSame('pending', $this->db->value('SELECT moderation_status FROM products WHERE id = ?', [$x['product']]));
        $this->assertFalse($this->visible($x['product']));
        $this->svc->approve($x['product'], $this->staff);
        $this->svc->addImage($x['seller'], $x['product'], $x['user'], $this->image(), null);
        $this->assertSame('pending', $this->db->value('SELECT moderation_status FROM products WHERE id = ?', [$x['product']]), 'new photos are reviewed too');
    }

    public function testRejectionTakedownAndResubmission(): void
    {
        $x = $this->published();
        $this->expectValidation(fn () => $this->svc->reject($x['product'], $this->staff, 'no'), 'reason');
        $this->svc->reject($x['product'], $this->staff, 'Photos show a different product');
        $this->assertFalse($this->visible($x['product']), 'taken down');
        $this->assertSame('Photos show a different product', $this->db->value('SELECT moderation_note FROM products WHERE id = ?', [$x['product']]));
        $this->assertStringContainsString('removed from the shop', (string) $this->db->value("SELECT body FROM notifications WHERE template = 'product_rejected'"));
        $this->svc->submit($x['seller'], $x['product'], $x['user']);
        $this->assertSame('pending', $this->db->value('SELECT moderation_status FROM products WHERE id = ?', [$x['product']]));
        $this->expectValidation(fn () => $this->svc->approve($x['product'] + 999, $this->staff), '_form', 'unknown product', HttpException::class);
    }

    public function testSuspendedSellersProductsDisappearAndComeBack(): void
    {
        $x = $this->published();
        $cart = new CartService(null, null, $this->db);
        $cart->add($x['variant'], 1);
        $this->db->exec("UPDATE sellers SET status = 'suspended' WHERE id = ?", [$x['seller']]);
        $this->assertFalse($this->visible($x['product']));
        $line = $cart->summary()['lines'][0];
        $this->assertSame('unavailable', $line['issue']['code'] ?? null, 'cart line blocks checkout');
        try {
            (new CartService(null, null, $this->db))->add($x['variant'], 1);
            $this->fail('cannot add a suspended seller\'s item');
        } catch (ValidationException) {
        }
        $this->db->exec("UPDATE sellers SET status = 'active' WHERE id = ?", [$x['seller']]);
        $this->assertTrue($this->visible($x['product']));
    }

    public function testSellersCannotTouchAnotherSellersProducts(): void
    {
        $a = $this->published('shop-a');
        $b = $this->seller('shop-b');
        $bp = $this->svc->create($b['seller'], $b['user'], $this->input(['sku' => 'B-1']));
        $bv = (int) $this->db->value('SELECT id FROM product_variants WHERE product_id = ?', [$bp]);
        $attempts = [
            'owned' => fn () => $this->svc->owned($b['seller'], $a['product']),
            'update' => fn () => $this->svc->update($b['seller'], $a['product'], $b['user'], $this->input(['name' => 'Hijacked'])),
            'variant' => fn () => $this->svc->updateVariant($b['seller'], $a['product'], $a['variant'], $b['user'], ['sku' => 'X', 'price' => '1']),
            'add variant' => fn () => $this->svc->addVariant($b['seller'], $a['product'], $b['user'], ['sku' => 'X2', 'price' => '1']),
            'stock' => fn () => $this->svc->changeStock($b['seller'], $a['product'], $a['variant'], $b['user'], ['action' => 'count', 'counted' => '0']),
            'image' => fn () => $this->svc->addImage($b['seller'], $a['product'], $b['user'], $this->image(), null),
            'submit' => fn () => $this->svc->submit($b['seller'], $a['product'], $b['user']),
            'archive' => fn () => $this->svc->archive($b['seller'], $a['product'], $b['user']),
            // own product id, someone else's variant id
            'mixed ids' => fn () => $this->svc->changeStock($b['seller'], $bp, $a['variant'], $b['user'], ['action' => 'count', 'counted' => '0']),
            'mixed default' => fn () => $this->svc->setDefaultVariant($b['seller'], $bp, $a['variant'], $b['user']),
        ];
        foreach ($attempts as $what => $fn) {
            try {
                $fn();
                $this->fail("$what on another seller's product must fail");
            } catch (HttpException $e) {
                $this->assertSame(404, $e->status, $what);
            }
        }
        $this->assertSame('Kiondo Basket', $this->db->value('SELECT name FROM products WHERE id = ?', [$a['product']]));
        $this->assertSame(8, (int) $this->db->value('SELECT on_hand FROM inventory WHERE variant_id = ?', [$a['variant']]));
        $this->assertSame('published', $this->db->value('SELECT moderation_status FROM products WHERE id = ?', [$a['product']]));
        $this->assertNotSame($bv, $a['variant']);
    }

    public function testPlatformControlledFieldsCannotBeSetBySellers(): void
    {
        $x = $this->published();
        $before = (array) $this->db->one('SELECT slug, is_featured, seller_id FROM products WHERE id = ?', [$x['product']]);
        $this->svc->update($x['seller'], $x['product'], $x['user'], $this->input([
            'seller_id' => '1', 'is_featured' => '1', 'slug' => 'hijacked-slug', 'moderation_status' => 'published', 'is_active' => '1',
        ]));
        $after = (array) $this->db->one('SELECT slug, is_featured, seller_id, moderation_status FROM products WHERE id = ?', [$x['product']]);
        $this->assertSame([$before['slug'], (int) $before['is_featured'], (int) $before['seller_id']], [$after['slug'], (int) $after['is_featured'], (int) $after['seller_id']]);
        $this->assertSame('published', $after['moderation_status'], 'nothing a customer sees changed, so no re-review');

        $id = $this->svc->create($x['seller'], $x['user'], $this->input(['sku' => 'KB-02', 'seller_id' => '1', 'is_featured' => '1', 'moderation_status' => 'published']));
        $row = (array) $this->db->one('SELECT seller_id, is_featured, moderation_status FROM products WHERE id = ?', [$id]);
        $this->assertSame([$x['seller'], 0, 'draft'], [(int) $row['seller_id'], (int) $row['is_featured'], $row['moderation_status']]);
    }

    public function testSellerSkusAreNamespacedPerSeller(): void
    {
        $a = $this->seller('sku-a');
        $b = $this->seller('sku-b');
        $this->svc->create($a['seller'], $a['user'], $this->input(['sku' => 'mug-1']));
        $this->svc->create($b['seller'], $b['user'], $this->input(['sku' => 'MUG-1']));
        $this->expectValidation(fn () => $this->svc->create($a['seller'], $a['user'], $this->input(['sku' => 'MUG-1'])), 'sku', 'duplicate within one seller');
        $this->expectValidation(fn () => $this->svc->create($a['seller'], $a['user'], $this->input(['sku' => 'bad sku!'])), 'sku');
        $this->assertSame('MUG-1', SellerProductService::displaySku('S' . $a['seller'] . '-MUG-1'));
        $this->expectValidation(fn () => (new CatalogueAdminService($this->db))->createProduct($this->input(['sku' => 'S' . $a['seller'] . '-NEW']), $this->staff), 'sku', 'admin cannot use the seller namespace');
    }

    public function testSellerStockChangesAreValidatedAndRecorded(): void
    {
        $x = $this->published();
        $this->svc->changeStock($x['seller'], $x['product'], $x['variant'], $x['user'], ['action' => 'adjust', 'delta' => '-2', 'reason' => 'damaged']);
        $this->svc->changeStock($x['seller'], $x['product'], $x['variant'], $x['user'], ['action' => 'count', 'counted' => '10']);
        $this->assertSame(10, (int) $this->db->value('SELECT on_hand FROM inventory WHERE variant_id = ?', [$x['variant']]));
        $this->db->exec('UPDATE inventory SET reserved = 4 WHERE variant_id = ?', [$x['variant']]);
        $this->expectValidation(fn () => $this->svc->changeStock($x['seller'], $x['product'], $x['variant'], $x['user'], ['action' => 'count', 'counted' => '3']), 'delta', 'cannot go below reserved units');
        $this->expectValidation(fn () => $this->svc->changeStock($x['seller'], $x['product'], $x['variant'], $x['user'], ['action' => 'adjust', 'delta' => '-1', 'reason' => 'other']), 'note');
        $this->assertSame(3, (int) $this->db->value("SELECT COUNT(*) FROM stock_movements WHERE variant_id = ? AND actor_type = 'seller'", [$x['variant']]), 'opening, adjust, count');
    }

    public function testSellersOnlyUseLiveCategoriesAndBrands(): void
    {
        $s = $this->seller('cat-shop');
        $hidden = $this->db->insert('categories', ['name' => 'Hidden', 'slug' => 'hidden-' . bin2hex(random_bytes(2)), 'is_active' => 0]);
        $this->expectValidation(fn () => $this->svc->create($s['seller'], $s['user'], $this->input(['category_id' => (string) $hidden])), 'category_id');
        $brand = $this->db->insert('brands', ['name' => 'Old Brand', 'slug' => 'old-brand', 'is_active' => 0]);
        $this->expectValidation(fn () => $this->svc->create($s['seller'], $s['user'], $this->input(['brand_id' => (string) $brand])), 'brand_id');
    }

    public function testArchiveAndRestore(): void
    {
        $x = $this->published();
        $this->svc->archive($x['seller'], $x['product'], $x['user']);
        $this->assertFalse($this->visible($x['product']));
        $this->expectValidation(fn () => $this->svc->update($x['seller'], $x['product'], $x['user'], $this->input()), '_form', 'archived products are read-only');
        $this->svc->restore($x['seller'], $x['product'], $x['user']);
        $this->assertSame('draft', $this->db->value('SELECT moderation_status FROM products WHERE id = ?', [$x['product']]));
    }

    public function testOfficialStoreProductsAreUnaffected(): void
    {
        $v = $this->makeVariant(5);
        $this->db->exec('UPDATE products SET category_id = ? WHERE id = ?', [$this->cat, $v['product_id']]);
        $this->assertTrue($this->visible($v['product_id']), 'admin-created products stay published');
    }

    private function expectValidation(callable $fn, string $field, string $why = '', string $class = ValidationException::class): void
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            $this->assertSame(ValidationException::class, $class, $why);
            $this->assertArrayHasKey($field, $e->errors, "expected '$field' $why: " . json_encode($e->errors));
            return;
        } catch (HttpException $e) {
            $this->assertSame(HttpException::class, $class, $why);
            return;
        }
        $this->fail("Expected an error on '$field' $why");
    }
}
