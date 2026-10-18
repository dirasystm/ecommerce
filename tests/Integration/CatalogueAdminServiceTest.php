<?php

declare(strict_types=1);

namespace Waimart\Tests\Integration;

use Waimart\Core\App;
use Waimart\Core\ValidationException;
use Waimart\Services\CatalogueAdminService;

final class CatalogueAdminServiceTest extends DbTestCase
{
    private CatalogueAdminService $svc;
    private int $staff;
    private int $cat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new CatalogueAdminService($this->db);
        $this->staff = $this->db->insert('users', ['email' => 'admin@x.co', 'password_hash' => 'x', 'first_name' => 'A', 'last_name' => 'D', 'is_staff' => 1]);
        $this->cat = $this->svc->saveCategory(null, ['name' => 'Phones', 'is_active' => '1'], $this->staff);
    }

    private function productInput(array $o = []): array
    {
        return $o + [
            'name' => 'Test Phone', 'category_id' => (string) $this->cat, 'max_per_order' => '5', 'is_active' => '1',
            'sku' => 'tp-001', 'price' => '9999', 'compare_at_price' => '', 'opening_stock' => '12',
            'specifications' => "Screen: 6.5 inches\nBattery: 5000 mAh", 'attributes' => 'Colour: Black',
        ];
    }

    public function testCreateProductBuildsDefaultVariantInventoryAndLedger(): void
    {
        $id = $this->svc->createProduct($this->productInput(), $this->staff);
        $v = $this->db->one('SELECT * FROM product_variants WHERE product_id = ?', [$id]);
        $this->assertSame('TP-001', $v['sku'], 'SKU normalised to upper case');
        $this->assertSame(1, (int) $v['is_default']);
        $this->assertSame('9999.00', $v['price']);
        $this->assertSame(12, (int) $this->db->value('SELECT on_hand FROM inventory WHERE variant_id = ?', [$v['id']]));
        $this->assertSame('opening', $this->db->value("SELECT reason FROM stock_movements WHERE variant_id = ? AND movement_type = 'receipt'", [$v['id']]));
        $this->assertSame('Black', $this->db->value("SELECT attr_value FROM variant_attributes WHERE variant_id = ? AND attr_name = 'Colour'", [$v['id']]));
        $specs = json_decode((string) $this->db->value('SELECT specifications FROM products WHERE id = ?', [$id]), true);
        $this->assertSame(['Screen' => '6.5 inches', 'Battery' => '5000 mAh'], $specs);
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'catalogue.product_created'"));
    }

    public function testValidationRules(): void
    {
        $this->svc->createProduct($this->productInput(), $this->staff);
        $cases = [
            'sku' => ['sku' => 'TP-001'],                                   // duplicate
            'compare_at_price' => ['sku' => 'X1', 'compare_at_price' => '9999'],  // not above price
            'price' => ['sku' => 'X2', 'price' => '0'],
            'category_id' => ['sku' => 'X3', 'category_id' => '99999'],
            'specifications' => ['sku' => 'X4', 'specifications' => 'no colon here'],
        ];
        foreach ($cases as $field => $override) {
            try {
                $this->svc->createProduct($this->productInput($override), $this->staff);
                $this->fail("Expected $field error");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors, "case $field");
            }
        }
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM products'), 'failed creates leave nothing behind');
    }

    public function testSlugsAreUnique(): void
    {
        $a = $this->svc->createProduct($this->productInput(), $this->staff);
        $b = $this->svc->createProduct($this->productInput(['sku' => 'TP-002']), $this->staff);
        $this->assertSame('test-phone', $this->db->value('SELECT slug FROM products WHERE id = ?', [$a]));
        $this->assertSame('test-phone-2', $this->db->value('SELECT slug FROM products WHERE id = ?', [$b]));
    }

    public function testDefaultVariantSwitchAndDeactivationRules(): void
    {
        $pid = $this->svc->createProduct($this->productInput(), $this->staff);
        $first = (int) $this->db->value('SELECT id FROM product_variants WHERE product_id = ?', [$pid]);
        $second = $this->svc->addVariant($pid, ['sku' => 'TP-BLUE', 'variant_name' => 'Blue', 'price' => '10499', 'opening_stock' => '3'], $this->staff);
        $this->assertSame(0, (int) $this->db->value('SELECT is_default FROM product_variants WHERE id = ?', [$second]));

        try {
            $this->svc->updateVariant($first, ['sku' => 'TP-001', 'price' => '9999', 'variant_active' => '0'], $this->staff);
            $this->fail('Default variant must not be deactivated');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('is_active', $e->errors);
        }

        $this->svc->setDefaultVariant($pid, $second, $this->staff);
        $defaults = $this->db->all('SELECT id FROM product_variants WHERE product_id = ? AND is_default = 1', [$pid]);
        $this->assertSame([$second], array_map('intval', array_column($defaults, 'id')));

        $this->svc->updateVariant($first, ['sku' => 'TP-001', 'price' => '9999', 'variant_active' => '0'], $this->staff);
        $this->assertSame(0, (int) $this->db->value('SELECT is_active FROM product_variants WHERE id = ?', [$first]));
    }

    public function testDatabaseRejectsTwoDefaultVariants(): void
    {
        $pid = $this->svc->createProduct($this->productInput(), $this->staff);
        $this->assertDbRejects(
            fn () => $this->db->insert('product_variants', ['product_id' => $pid, 'sku' => 'DUP', 'name' => 'x', 'price' => '1', 'is_default' => 1]),
            'uq_var_default'
        );
    }

    public function testCategoryHierarchyRules(): void
    {
        $child = $this->svc->saveCategory(null, ['name' => 'Smartphones', 'parent_id' => (string) $this->cat, 'is_active' => '1'], $this->staff);
        foreach ([
            [null, ['name' => 'Deep', 'parent_id' => (string) $child]],                 // grandchild not allowed
            [$this->cat, ['name' => 'Phones', 'parent_id' => (string) $this->cat]],     // own parent
            [$this->cat, ['name' => 'Phones', 'parent_id' => (string) $this->db->insert('categories', ['name' => 'Other', 'slug' => 'other'])]], // has children
            [null, ['name' => 'Bad icon', 'icon' => '<script>']],
        ] as [$id, $in]) {
            try {
                $this->svc->saveCategory($id, $in, $this->staff);
                $this->fail('Expected rejection for ' . json_encode($in));
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors);
            }
        }
    }

    public function testImageUploadReencodesAndRejectsDisguisedFiles(): void
    {
        $pid = $this->svc->createProduct($this->productInput(), $this->staff);
        $png = tempnam(sys_get_temp_dir(), 'img') . '.png';
        $im = imagecreatetruecolor(1600, 800);
        imagefill($im, 0, 0, imagecolorallocate($im, 200, 50, 50));
        imagepng($im, $png);

        $imageId = $this->svc->addImage($pid, ['tmp_name' => $png, 'error' => UPLOAD_ERR_OK, 'size' => filesize($png)], 'Front', $this->staff);
        $path = (string) $this->db->value('SELECT path FROM product_images WHERE id = ?', [$imageId]);
        $abs = App::root() . '/public/' . $path;
        $this->assertMatchesRegularExpression('#^uploads/products/\d{4}/\d{2}/[a-z0-9]{20}\.webp$#', $path);
        $this->assertSame('image/webp', (new \finfo(FILEINFO_MIME_TYPE))->file($abs));
        $this->assertSame(1000, getimagesize($abs)[0], 'resized to max edge 1000px');

        $fake = tempnam(sys_get_temp_dir(), 'img') . '.png';
        file_put_contents($fake, "<?php echo 'pwned'; ?>");
        try {
            $this->svc->addImage($pid, ['tmp_name' => $fake, 'error' => UPLOAD_ERR_OK, 'size' => filesize($fake)], null, $this->staff);
            $this->fail('Disguised PHP file must be rejected');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('image', $e->errors);
        }

        $this->svc->deleteImage($pid, $imageId, $this->staff);
        $this->assertFileDoesNotExist($abs);
        @unlink($png);
        @unlink($fake);
    }
}
