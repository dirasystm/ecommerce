<?php

declare(strict_types=1);

namespace Waimart\Tests\Feature;

use Waimart\Core\App;

/** Over HTTP: attach several photos when adding a product, the product page gallery, key features and sharing. */
final class ProductPhotosHttpTest extends FeatureTestCase
{
    private int $cat;
    /** @var list<string> */
    private array $tmp = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cat = $this->db->insert('categories', ['name' => 'Chargers', 'slug' => 'chargers-' . bin2hex(random_bytes(2))]);
    }

    protected function tearDown(): void
    {
        foreach ($this->db->all('SELECT path FROM product_images') as $i) {
            @unlink(App::root() . '/public/' . $i['path']);
        }
        foreach ($this->tmp as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function jpg(int $shade): string
    {
        $path = tempnam(sys_get_temp_dir(), 'wmjpg') . '.jpg';
        $im = imagecreatetruecolor(400, 400);
        imagefill($im, 0, 0, imagecolorallocate($im, $shade, 120, 60));
        imagejpeg($im, $path, 85);
        $this->tmp[] = $path;
        return $path;
    }

    private function product(array $over = []): array
    {
        return $over + [
            '_form' => 'new', 'name' => 'USB-C Wall Charger 20W', 'category_id' => (string) $this->cat, 'max_per_order' => '3', 'is_active' => '1',
            'sku' => 'WC-20', 'price' => 'KSh 1,250', 'opening_stock' => '15',
            'specifications' => "★PD 3.0 USB-C Wall Charger: Fast Charging for iPhone and Android\nOutput: 20W\n• Compact, travel-friendly design",
        ];
    }

    public function testAdminAttachesSeveralPhotosAndTheShopShowsAGalleryWithSharing(): void
    {
        $this->loginStaff('manager');
        $form = $this->get('/admin/products/new')['body'];
        $this->assertStringContainsString('enctype="multipart/form-data"', $form);
        $this->assertStringContainsString('name="images[]"', $form);
        $this->assertStringContainsString('multiple', $form);
        $this->assertStringContainsString('Attach photos', $form);

        $r = $this->post('/admin/products', $this->product(), '/admin/products/new', [
            'images[0]' => $this->jpg(20), 'images[1]' => $this->jpg(120), 'images[2]' => $this->jpg(220),
        ]);
        $this->assertSame(303, $r['status'], $this->lastBody);
        $this->assertMatchesRegularExpression('#/admin/products/\d+$#', $r['headers']['location']);
        $pid = (int) $this->db->value('SELECT id FROM products ORDER BY id DESC LIMIT 1');
        $this->assertSame(3, (int) $this->db->value('SELECT COUNT(*) FROM product_images WHERE product_id = ?', [$pid]));
        $this->assertStringContainsString('Product created with 3 photos', $this->follow($r)['body']);

        $page = $this->get('/product/usb-c-wall-charger-20w');
        $this->assertSame(200, $page['status']);
        $html = $page['body'];
        $this->assertSame(3, substr_count($html, 'data-gallery-index='), 'three thumbnails');
        $this->assertSame(3, substr_count($html, 'data-lb-index='), 'three photos in the full-screen viewer');
        $this->assertStringContainsString('id="wmLightbox"', $html);
        $this->assertStringContainsString('1 / 3', $html);
        $this->assertStringContainsString('Share this product', $html);
        $shareUrl = rawurlencode(rtrim((string) config('app.url'), '/') . '/product/usb-c-wall-charger-20w');
        $this->assertStringContainsString('https://www.facebook.com/sharer/sharer.php?u=' . $shareUrl, $html);
        $this->assertStringContainsString('https://twitter.com/intent/tweet?url=' . $shareUrl, $html);
        $this->assertStringContainsString('https://wa.me/?text=', $html);
        $this->assertStringContainsString('data-share-copy', $html);
        $this->assertMatchesRegularExpression('#<meta property="og:image" content="https?://[^"]+/uploads/products/[^"]+\.webp">#', $html);
        $this->assertStringContainsString('Key features', $html);
        $this->assertStringContainsString('<li>Compact, travel-friendly design</li>', $html);
        $this->assertStringContainsString('<th scope="row">PD 3.0 USB-C Wall Charger</th>', $html);
        $this->assertStringContainsString('KSh 1,250', $html);
        $this->assertStringContainsString("blob:", $page['headers']['content-security-policy'] ?? '', 'local photo previews are allowed');

        // Edit page: several more at once, and the count limit is explained.
        $edit = $this->get("/admin/products/$pid")['body'];
        $this->assertStringContainsString('name="images[]"', $edit);
        $r = $this->post("/admin/products/$pid/images", ['alt_text' => 'Side'], "/admin/products/$pid", ['images[0]' => $this->jpg(60), 'images[1]' => $this->jpg(90)]);
        $this->assertSame(303, $r['status']);
        $this->assertSame(5, (int) $this->db->value('SELECT COUNT(*) FROM product_images WHERE product_id = ?', [$pid]));
        $files = [];
        foreach (range(0, 5) as $i) {
            $files["images[$i]"] = $this->jpg(10 * $i);
        }
        $r = $this->post("/admin/products/$pid/images", [], "/admin/products/$pid", $files);
        $this->assertStringContainsString('you can add 5 more', $this->follow($r)['body']);
        $this->assertSame(5, (int) $this->db->value('SELECT COUNT(*) FROM product_images WHERE product_id = ?', [$pid]));
    }

    public function testMistakesAreShownTogetherAndAskToReattachPhotos(): void
    {
        $this->loginStaff('manager');
        $bad = tempnam(sys_get_temp_dir(), 'wmbad') . '.jpg';
        file_put_contents($bad, 'not an image');
        $this->tmp[] = $bad;
        $r = $this->post('/admin/products', $this->product(['price' => '', 'opening_stock' => 'lots']), '/admin/products/new', ['images[0]' => $bad]);
        $this->assertSame(303, $r['status']);
        $page = $this->follow($r)['body'];
        $this->assertStringContainsString('Only JPEG, PNG or WebP images are allowed', $page);
        $this->assertStringContainsString('Price', $page);
        $this->assertStringContainsString('Opening stock', $page);
        $this->assertStringContainsString('Please attach your photos again', $page);
        $this->assertStringContainsString('value="USB-C Wall Charger 20W"', $page, 'typed details are kept');
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM products'));
    }

    public function testSellerAttachesPhotosWhenAddingAProduct(): void
    {
        $s = $this->db->insert('sellers', ['name' => 'Charge Hub', 'slug' => 'charge-hub']);
        $u = $this->db->insert('users', ['email' => 'hub@example.com', 'password_hash' => password_hash('Secret123', PASSWORD_DEFAULT), 'first_name' => 'H', 'last_name' => 'B']);
        $this->db->insert('seller_users', ['seller_id' => $s, 'user_id' => $u, 'role' => 'owner']);
        $this->post('/login', ['email' => 'hub@example.com', 'password' => 'Secret123'], '/login');

        $this->assertStringContainsString('name="images[]"', $this->get('/seller/products/new')['body']);
        $r = $this->post('/seller/products', $this->product(['sku' => 'hub 1']), '/seller/products/new', ['images[0]' => $this->jpg(30), 'images[1]' => $this->jpg(200)]);
        $this->assertSame(303, $r['status'], $this->lastBody);
        $p = $this->db->one('SELECT * FROM products ORDER BY id DESC LIMIT 1');
        $this->assertSame($s, (int) $p['seller_id']);
        $this->assertSame('draft', $p['moderation_status']);
        $this->assertSame(2, (int) $this->db->value('SELECT COUNT(*) FROM product_images WHERE product_id = ?', [$p['id']]));
        $this->assertSame("S$s-HUB-1", $this->db->value('SELECT sku FROM product_variants WHERE product_id = ?', [$p['id']]));

        $r = $this->post("/seller/products/{$p['id']}/images", [], "/seller/products/{$p['id']}", ['images[0]' => $this->jpg(70), 'images[1]' => $this->jpg(140)]);
        $this->assertSame(303, $r['status']);
        $this->assertStringContainsString('2 photos uploaded', $this->follow($r)['body']);
        $this->assertSame(4, (int) $this->db->value('SELECT COUNT(*) FROM product_images WHERE product_id = ?', [$p['id']]));
    }
}
