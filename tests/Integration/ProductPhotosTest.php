<?php

declare(strict_types=1);

namespace Waimart\Tests\Integration;

use Waimart\Core\App;
use Waimart\Core\ValidationException;
use Waimart\Repositories\ProductRepository;
use Waimart\Services\CatalogueAdminService;
use Waimart\Services\ImageService;

/** Several photos per product, pasted specifications, and forgiving form input. */
final class ProductPhotosTest extends DbTestCase
{
    private CatalogueAdminService $svc;
    private int $staff;
    private int $cat;
    /** @var list<string> */
    private array $tmp = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new CatalogueAdminService($this->db);
        $this->staff = $this->db->insert('users', ['email' => 'admin@x.co', 'password_hash' => 'x', 'first_name' => 'A', 'last_name' => 'D', 'is_staff' => 1]);
        $this->cat = $this->svc->saveCategory(null, ['name' => 'Chargers', 'is_active' => '1'], $this->staff);
    }

    protected function tearDown(): void
    {
        foreach ($this->db->all('SELECT path FROM product_images') as $r) {
            @unlink(App::root() . '/public/' . $r['path']);
        }
        foreach ($this->tmp as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function input(array $o = []): array
    {
        return $o + [
            'name' => 'Wall Charger', 'category_id' => (string) $this->cat, 'max_per_order' => '5', 'is_active' => '1',
            'sku' => 'wc-1', 'price' => '900', 'compare_at_price' => '', 'opening_stock' => '4', 'specifications' => '',
        ];
    }

    /** @return array{name:string,type:string,tmp_name:string,error:int,size:int} */
    private function photo(string $name, int $r = 200): array
    {
        $path = tempnam(sys_get_temp_dir(), 'ph') . '.png';
        $im = imagecreatetruecolor(300, 300);
        imagefill($im, 0, 0, imagecolorallocate($im, $r, 80, 80));
        imagepng($im, $path);
        $this->tmp[] = $path;
        return ['name' => $name, 'type' => 'image/png', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => (int) filesize($path)];
    }

    public function testThePastedChargerSpecificationsAreAccepted(): void
    {
        $specs = "★PD 3.0 USB-C Wall Charger: Fast Charging for phones and tablets\n"
            . "• Safe Charging： over-heat protection\n"
            . "Output\t20W\n"
            . "- Compact, travel-friendly design\n"
            . "Colour: White\n";
        $id = $this->svc->createProduct($this->input(['specifications' => $specs]), $this->staff);
        $stored = json_decode((string) $this->db->value('SELECT specifications FROM products WHERE id = ?', [$id]), true);
        $this->assertSame('Fast Charging for phones and tablets', $stored['PD 3.0 USB-C Wall Charger']);
        $this->assertSame('over-heat protection', $stored['Safe Charging']);
        $this->assertSame('20W', $stored['Output']);
        $this->assertSame('White', $stored['Colour']);
        $this->assertSame('Compact, travel-friendly design', $stored['• 1']);

        $p = (new ProductRepository($this->db))->findActiveBySlug('wall-charger');
        $this->assertSame(['Compact, travel-friendly design'], $p['features'], 'plain lines are key features');
        $this->assertArrayNotHasKey('• 1', $p['specs']);
        $this->assertSame('20W', $p['specs']['Output']);

        // The edit form shows the text back the way it was typed (bullets removed).
        $text = CatalogueAdminService::pairsToText(json_encode($stored));
        $this->assertStringContainsString("Compact, travel-friendly design\n", $text . "\n");
        $this->assertStringContainsString('Output: 20W', $text);
    }

    public function testFriendlyPricesSlugsAndSkusAreAccepted(): void
    {
        $id = $this->svc->createProduct($this->input([
            'price' => 'KSh 1,500', 'compare_at_price' => 'Ksh. 2,000.00', 'opening_stock' => '1,000',
            'slug' => 'My Fast Charger!', 'sku' => 'wc 22',
        ]), $this->staff);
        $row = $this->db->one('SELECT p.slug, v.sku, v.price, v.compare_at_price, i.on_hand FROM products p
            JOIN product_variants v ON v.product_id = p.id JOIN inventory i ON i.variant_id = v.id WHERE p.id = ?', [$id]);
        $this->assertSame('my-fast-charger', $row['slug']);
        $this->assertSame('WC-22', $row['sku']);
        $this->assertSame(1500.0, (float) $row['price']);
        $this->assertSame(2000.0, (float) $row['compare_at_price']);
        $this->assertSame(1000, (int) $row['on_hand']);
    }

    public function testAllMistakesAreReportedTogether(): void
    {
        try {
            $this->svc->createProduct($this->input(['name' => '', 'price' => 'abc', 'opening_stock' => '-3']), $this->staff);
            $this->fail('expected validation errors');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('name', $e->errors);
            $this->assertArrayHasKey('price', $e->errors);
            $this->assertArrayHasKey('opening_stock', $e->errors);
        }
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM products'));
    }

    public function testCreatingAProductWithSeveralPhotosKeepsTheirOrder(): void
    {
        $id = $this->svc->createProduct($this->input(), $this->staff, null, 'published', [$this->photo('front.png', 10), $this->photo('back.png', 90), $this->photo('box.png', 170)]);
        $rows = $this->db->all('SELECT path, sort_order FROM product_images WHERE product_id = ? ORDER BY sort_order', [$id]);
        $this->assertCount(3, $rows);
        $this->assertSame([0, 1, 2], array_map(fn ($r) => (int) $r['sort_order'], $rows), 'first photo is the main one');
        foreach ($rows as $r) {
            $this->assertFileExists(App::root() . '/public/' . $r['path']);
        }
        $this->assertCount(3, (new ProductRepository($this->db))->findActiveBySlug('wall-charger')['images']);
    }

    public function testABadPhotoStopsTheWholeProductAndNamesTheFile(): void
    {
        $fake = tempnam(sys_get_temp_dir(), 'ph') . '.png';
        file_put_contents($fake, "<?php echo 'x';");
        $this->tmp[] = $fake;
        $before = glob(App::root() . '/public/uploads/products/*/*/*.webp') ?: [];
        try {
            $this->svc->createProduct($this->input(), $this->staff, null, 'published', [
                $this->photo('good.png'), ['name' => 'virus.png', 'type' => 'image/png', 'tmp_name' => $fake, 'error' => UPLOAD_ERR_OK, 'size' => 14],
            ]);
            $this->fail('expected rejection');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('"virus.png"', $e->errors['images']);
        }
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM products'));
        $this->assertSame(count($before), count(glob(App::root() . '/public/uploads/products/*/*/*.webp') ?: []), 'no files left behind');
    }

    public function testEditPageAddsSeveralPhotosWithinTheLimit(): void
    {
        $id = $this->svc->createProduct($this->input(), $this->staff, null, 'published', array_map(fn ($i) => $this->photo("p$i.png", 20 * $i), range(1, 8)));
        try {
            $this->svc->addImages($id, [$this->photo('a.png'), $this->photo('b.png'), $this->photo('c.png')], null, $this->staff);
            $this->fail('11 photos must be refused');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('you can add 2 more', $e->errors['images']);
        }
        $this->assertSame(8, (int) $this->db->value('SELECT COUNT(*) FROM product_images WHERE product_id = ?', [$id]));
        $this->assertSame(2, $this->svc->addImages($id, [$this->photo('a.png'), $this->photo('b.png')], 'Side view', $this->staff));
        $this->assertSame(10, (int) $this->db->value('SELECT COUNT(*) FROM product_images WHERE product_id = ?', [$id]));

        try {
            $this->svc->addImages($id, [], null, $this->staff);
            $this->fail('empty upload');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('images', $e->errors);
        }
    }

    public function testUploadedListFlattensSingleAndMultipleInputs(): void
    {
        $files = [
            'images' => [
                'name' => ['a.jpg', '', 'c.png'], 'type' => ['image/jpeg', '', 'image/png'],
                'tmp_name' => ['/t/a', '', '/t/c'], 'error' => [0, UPLOAD_ERR_NO_FILE, 0], 'size' => [10, 0, 30],
            ],
            'image' => ['name' => 'single.webp', 'type' => 'image/webp', 'tmp_name' => '/t/s', 'error' => 0, 'size' => 5],
        ];
        $list = ImageService::uploadedList($files, 'images', 'image');
        $this->assertSame(['a.jpg', 'c.png', 'single.webp'], array_column($list, 'name'));
        $this->assertSame([], ImageService::uploadedList([], 'images'));
        $this->assertSame([], ImageService::uploadedList(['images' => 'junk'], 'images'));
    }
}
