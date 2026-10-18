<?php

declare(strict_types=1);

namespace Waimart\Tests\Integration;

use Waimart\Core\App;
use Waimart\Core\Settings;
use Waimart\Core\ValidationException;
use Waimart\Repositories\OrderRepository;
use Waimart\Services\Brand;
use Waimart\Services\BrandingService;

/** Basemall Stage 1: settings-driven brand, uploads, order prefix, and the renamed Official Store. */
final class BrandingTest extends DbTestCase
{
    use PlacesOrders;

    private const KEYS = ['store_name', 'store_tagline', 'brand_primary', 'brand_secondary', 'order_number_prefix',
        'brand_logo_header', 'brand_logo_icon', 'brand_logo_full', 'brand_favicon', 'brand_icon_180', 'brand_icon_192', 'brand_icon_512', 'brand_og_image'];
    /** @var array<string,?string> */
    private array $saved = [];
    /** @var list<string> */
    private array $tmp = [];
    private int $staff;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::KEYS as $k) {
            $this->saved[$k] = Settings::get($k);
        }
        $this->staff = $this->staffUser();
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            $now = (string) Settings::get($k, '');
            if (str_starts_with($now, 'uploads/brand/')) {
                @unlink(App::root() . '/public/' . $now);
            }
            Settings::set($k, (string) $v);
        }
        foreach ($this->tmp as $f) {
            @unlink($f);
        }
        Settings::flush();
        parent::tearDown();
    }

    private function valid(array $over = []): array
    {
        return $over + ['store_name' => 'Basemall', 'store_tagline' => 'Everything You Need. One Marketplace.', 'order_number_prefix' => 'BM',
            'brand_primary' => '#005B3D', 'brand_secondary' => '#FF6A00'];
    }

    /** A real PNG upload as $_FILES would give it. */
    private function png(int $w, int $h, bool $alpha = false): array
    {
        $im = imagecreatetruecolor($w, $h);
        if ($alpha) {
            imagesavealpha($im, true);
            imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        }
        imagefilledrectangle($im, (int) ($w / 4), (int) ($h / 4), (int) ($w * 3 / 4), (int) ($h * 3 / 4), imagecolorallocate($im, 0, 91, 61));
        $f = tempnam(sys_get_temp_dir(), 'brand') . '.png';
        imagepng($im, $f);
        $this->tmp[] = $f;
        return ['name' => 'x.png', 'tmp_name' => $f, 'error' => UPLOAD_ERR_OK, 'size' => filesize($f)];
    }

    public function testMigrationShipsBasemallAndTheSuppliedLogo(): void
    {
        $this->assertSame('Basemall', Brand::name());
        $this->assertSame('Everything You Need. One Marketplace.', Brand::tagline());
        $this->assertSame(['#005B3D', '#FF6A00', 'BM'], [Brand::primary(), Brand::secondary(), Brand::orderPrefix()]);
        foreach (Brand::ASSETS as $key => $path) {
            $this->assertFileExists(App::root() . '/public/' . $path, $key);
            $this->assertStringContainsString($path, Brand::asset($key));
        }
        [$w, $h] = getimagesize(App::root() . '/public/assets/brand/og-image.png');
        $this->assertSame([1200, 630], [$w, $h]);
        $this->assertSame(['Basemall Official Store', 'basemall-official'], array_values((array) $this->db->one('SELECT name, slug FROM sellers WHERE is_platform = 1')));
        $this->assertSame(1, (int) $this->db->value("SELECT seller_id FROM seller_slug_aliases WHERE slug = 'waimart-official'"));
    }

    public function testSaveValidatesAuditsAndChangesThePalette(): void
    {
        $svc = new BrandingService();
        // #FFD200 as primary is too light for white text; the others are malformed.
        foreach ([[['store_name' => ''], 'store_name'], [['order_number_prefix' => 'B1'], 'order_number_prefix'], [['brand_primary' => '#FFD200'], 'brand_primary'],
                  [['brand_primary' => 'red;}body{'], 'brand_primary'], [['brand_secondary' => 'javascript:'], 'brand_secondary']] as [$over, $field]) {
            try {
                $svc->save($this->valid($over), [], $this->staff);
                $this->fail("rejected: $field");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors);
            }
        }
        $this->assertSame('Basemall', Settings::get('store_name'), 'nothing saved from invalid input');

        $svc->save($this->valid(['store_name' => 'Basemall Kenya', 'brand_primary' => '#1a4d8f', 'brand_secondary' => '#ffd200', 'order_number_prefix' => 'bk']), [], $this->staff);
        Settings::flush();
        $this->assertSame(['Basemall Kenya', '#1A4D8F', 'BK'], [Brand::name(), Brand::primary(), Brand::orderPrefix()]);
        $palette = Brand::palette();
        $this->assertSame('#FFD200', $palette['--wm-orange']);
        $this->assertGreaterThanOrEqual(4.5, Brand::contrast($palette['--wm-action'], '#FFFFFF'), 'buttons stay readable with a yellow accent');
        $this->assertStringNotContainsString(';}', Brand::css());
        $log = (array) $this->db->one("SELECT * FROM audit_logs WHERE action = 'branding.updated' ORDER BY id DESC LIMIT 1");
        $this->assertStringContainsString('Basemall Kenya', (string) $log['after_json']);
        $this->assertStringContainsString('"store_name":"Basemall"', (string) $log['before_json']);
        $this->assertSame('bk-', substr(strtolower((string) $this->placeOrderNumber()), 0, 3), 'new orders use the new prefix');
    }

    private function placeOrderNumber(): string
    {
        $this->setUpFulfilment();
        $v = $this->makeVariant(5)['variant_id'];
        return (string) $this->placeOrder([[$v, 1]], 'cod')['order_number'];
    }

    public function testUploadsMakeEveryIconSizeAndReplaceOldFiles(): void
    {
        $svc = new BrandingService();
        $svc->save($this->valid(), ['logo_icon' => $this->png(800, 800, true), 'logo_header' => $this->png(1200, 300)], $this->staff);
        Settings::flush();
        $sizes = ['brand_favicon' => [32, 32], 'brand_icon_180' => [180, 180], 'brand_icon_192' => [192, 192], 'brand_icon_512' => [512, 512]];
        $first = [];
        foreach ($sizes + ['brand_logo_icon' => null, 'brand_logo_header' => null] as $key => $want) {
            $path = (string) Settings::get($key);
            $this->assertStringStartsWith('uploads/brand/', $path, $key);
            $abs = App::root() . '/public/' . $path;
            $this->assertFileExists($abs);
            $this->assertSame('image/png', (new \finfo(FILEINFO_MIME_TYPE))->file($abs));
            if ($want) {
                $this->assertSame($want, array_slice(getimagesize($abs), 0, 2), $key);
            }
            $first[$key] = $abs;
        }
        [$hw, $hh] = getimagesize($first['brand_logo_header']);
        $this->assertSame([448, 112], [$hw, $hh], 'header logo scaled to 112 px high, aspect kept');

        // Replacing deletes the previous uploads; resetting returns to the supplied files.
        $svc->save($this->valid(), ['logo_header' => $this->png(600, 150)], $this->staff);
        $this->assertFileDoesNotExist($first['brand_logo_header']);
        $svc->save($this->valid(['reset' => ['logo_icon']]), [], $this->staff);
        Settings::flush();
        $this->assertSame('assets/brand/favicon-32.png', Settings::get('brand_favicon'));
        $this->assertFileDoesNotExist($first['brand_favicon']);
        $this->assertFileExists(App::root() . '/public/assets/brand/favicon-32.png', 'shipped files are never deleted');
    }

    public function testBadUploadsAreRefusedAndLeaveNothingBehind(): void
    {
        $txt = tempnam(sys_get_temp_dir(), 'brand');
        file_put_contents($txt, '<?php echo "not an image";');
        $this->tmp[] = $txt;
        $before = glob(App::root() . '/public/uploads/brand/*/*/*') ?: [];
        foreach ([['logo_header', ['name' => 'evil.png', 'tmp_name' => $txt, 'error' => UPLOAD_ERR_OK, 'size' => 30]],
                  ['logo_icon', $this->png(40, 40)]] as [$field, $file]) {
            try {
                // A valid header upload in the same request must not be kept when another upload fails.
                (new BrandingService())->save($this->valid(), ['logo_full' => $this->png(500, 500), $field => $file], $this->staff);
                $this->fail("refused: $field");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors);
            }
        }
        $this->assertSame($before, glob(App::root() . '/public/uploads/brand/*/*/*') ?: [], 'no orphan files');
        Settings::flush();
        $this->assertSame('assets/brand/logo-full.png', Settings::get('brand_logo_full'));
    }

    public function testFallbacksNeverBreakThePage(): void
    {
        Settings::set('store_name', '');
        Settings::set('brand_logo_header', 'uploads/brand/missing.png');
        Settings::set('brand_primary', 'nonsense');
        Settings::set('order_number_prefix', '1');
        Settings::flush();
        $this->assertSame((string) config('app.name'), Brand::name());
        $this->assertStringContainsString('assets/brand/logo-wordmark.png', Brand::asset('brand_logo_header'));
        $this->assertSame(Brand::DEFAULT_PRIMARY, Brand::primary());
        $this->assertSame('BM', Brand::orderPrefix());
        Settings::set('brand_logo_header', '../../config/config.local.php');
        Settings::flush();
        $this->assertStringContainsString('assets/brand/logo-wordmark.png', Brand::asset('brand_logo_header'), 'no path traversal');
    }

    public function testOrdersWithTheOldPrefixStillWork(): void
    {
        $this->setUpFulfilment();
        $v = $this->makeVariant(5)['variant_id'];
        $o = $this->placeOrder([[$v, 1]], 'cod');
        $this->assertMatchesRegularExpression('/^BM-[A-Z0-9]{8}$/', (string) $o['order_number']);
        $this->db->exec("UPDATE orders SET order_number = 'WM-OLDORDR1' WHERE id = ?", [$o['id']]);
        $this->assertSame((int) $o['id'], (int) (new OrderRepository($this->db))->byNumber('WM-OLDORDR1')['id']);
        $this->assertNull((new OrderRepository($this->db))->byNumber('wm-oldordr1'));
        $this->assertNull((new OrderRepository($this->db))->byNumber("WM-OLDORDR1' OR 1=1"));
    }
}
