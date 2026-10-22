<?php

declare(strict_types=1);

namespace Waimart\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Waimart\Core\Config;

/** config.live.php applies on the live server only, never to tests, and the app key comes from config.local.php. */
final class ConfigLiveTest extends TestCase
{
    private string $dir;
    /** @var array<string,string|false> */
    private array $env = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cfg-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir . '/config.php', "<?php return ['app' => ['env' => 'production', 'debug' => false, 'key' => ''], 'db' => ['database' => 'shop', 'test_database' => 'shop_test']];");
        file_put_contents($this->dir . '/config.local.php', "<?php return ['app' => ['env' => 'development', 'debug' => true, 'key' => 'K'], 'db' => ['database' => 'local_db']];");
        file_put_contents($this->dir . '/config.live.php', "<?php return ['app' => ['env' => 'production', 'debug' => false], 'db' => ['database' => 'live_db', 'test_database' => '']];");
        foreach (['WAIMART_LIVE', 'WAIMART_ENV'] as $k) {
            $this->env[$k] = getenv($k);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->env as $k => $v) {
            putenv($v === false ? $k : "$k=$v");
        }
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    public function testLiveSettingsApplyOnlyOnTheLiveServer(): void
    {
        putenv('WAIMART_ENV');
        putenv('WAIMART_LIVE=0');
        $local = Config::load($this->dir);
        $this->assertSame(['local_db', 'development', true], [$local->get('db.database'), $local->get('app.env'), $local->get('app.debug')]);

        putenv('WAIMART_LIVE=1');
        $live = Config::load($this->dir);
        $this->assertSame(['live_db', 'production', false, 'K'], [$live->get('db.database'), $live->get('app.env'), $live->get('app.debug'), $live->get('app.key')],
            'live database, errors hidden, same app key');

        putenv('WAIMART_ENV=testing');
        $this->assertSame('shop_test', Config::load($this->dir)->get('db.database'), 'tests never use the live settings');
    }

    public function testTheOperatingSystemDecidesByDefault(): void
    {
        putenv('WAIMART_LIVE');
        $this->assertSame(PHP_OS_FAMILY !== 'Windows', Config::isLive());
    }
}
