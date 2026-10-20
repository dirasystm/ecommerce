<?php

declare(strict_types=1);

namespace Waimart\Tests\Feature;

use Waimart\Core\App;
use Waimart\Tests\Support\ZipBuilder;

/**
 * Website updates over HTTP, exactly as the admin page drives them (chunked upload, JSON steps, password approval,
 * the browser's checks, the emergency rollback page and the manual rollback). In testing the updater works on a
 * sandbox project (storage/updater-sandbox), never on the real files.
 */
final class SiteUpdateHttpTest extends FeatureTestCase
{
    private string $sandbox;
    private string $csrf = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->sandbox = App::root() . '/storage/updater-sandbox';
        $this->rm($this->sandbox);
        $this->rm(App::root() . '/storage/updates-test');
        $name = json_decode((string) file_get_contents(App::root() . '/composer.json'), true)['name'];
        foreach (['composer.json' => json_encode(['name' => $name]), 'bootstrap.php' => "<?php\n", 'app/Core/App.php' => "<?php\n// v1\n",
                  'database/migrations/001_foundation.sql' => "-- applied\n", 'config/config.local.php' => "<?php return [];\n"] as $rel => $c) {
            @mkdir(dirname($this->sandbox . '/' . $rel), 0777, true);
            file_put_contents($this->sandbox . '/' . $rel, $c);
        }
        $this->db->pdo()->exec('DROP TABLE IF EXISTS upd_http_notes');
    }

    protected function tearDown(): void
    {
        $this->db->pdo()->exec('DROP TABLE IF EXISTS upd_http_notes');
        $this->db->exec("DELETE FROM schema_migrations WHERE filename LIKE '95_\\_upd%'");
        $this->rm($this->sandbox);
        $this->rm(App::root() . '/storage/updates-test');
        parent::tearDown();
    }

    private function rm(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }

    /** @param array<string,string> $changes */
    private function package(array $changes): string
    {
        $z = (new ZipBuilder())->addDirectory($this->sandbox, 'ecommerce/');
        foreach ($changes as $rel => $c) {
            $z->add('ecommerce/' . $rel, $c);
        }
        return $z->write(sys_get_temp_dir() . '/upd-http-' . bin2hex(random_bytes(3)) . '.zip');
    }

    private function json(string $method, string $path, array $data = [], array $files = []): array
    {
        $r = $this->http($method, $path, $data + ($method === 'POST' ? ['_csrf' => $this->csrf] : []), $files, '/admin/updates');
        $decoded = json_decode($r['body'], true);
        return ['status' => $r['status'], 'json' => is_array($decoded) ? $decoded : null, 'body' => $r['body']];
    }

    /** Uploads in 1 KB chunks through the real endpoints and steps until review. @return array<string,mixed> */
    private function uploadAndPrepare(string $zip): array
    {
        $size = filesize($zip);
        $j = $this->json('POST', '/admin/updates', ['name' => 'ecommerce-v2.zip', 'size' => (string) $size])['json'];
        $data = (string) file_get_contents($zip);
        foreach (str_split($data, 1024) as $i => $part) {
            $tmp = tempnam(sys_get_temp_dir(), 'chk');
            file_put_contents($tmp, $part);
            $r = $this->json('POST', '/admin/updates/' . $j['uuid'] . '/chunk', ['index' => (string) $i], ['chunk' => $tmp]);
            unlink($tmp);
            $this->assertSame(200, $r['status'], $r['body']);
            $j = $r['json'];
        }
        return $this->stepUntil($j['uuid'], ['ready', 'blocked', 'failed', 'nothing']);
    }

    /** @param list<string> $stops */
    private function stepUntil(string $uuid, array $stops): array
    {
        for ($i = 0; $i < 100; $i++) {
            $r = $this->json('POST', "/admin/updates/$uuid/step");
            $this->assertSame(200, $r['status'], $r['body']);
            $this->assertArrayHasKey('progress', $r['json']);
            if (in_array($r['json']['status'], $stops, true)) {
                return $r['json'];
            }
        }
        $this->fail('did not finish');
    }

    private function signIn(string $role): void
    {
        $this->loginStaff($role);
        preg_match('/name="_csrf" value="([^"]+)"/', $this->get('/admin/account')['body'], $m);
        $this->csrf = html_entity_decode($m[1] ?? '');
    }

    public function testOnlySuperAdminsReachUpdates(): void
    {
        foreach (['manager', 'finance', 'support'] as $role) {
            $this->post('/logout', [], '/');
            $this->signIn($role);
            $this->assertSame(403, $this->get('/admin/updates')['status'], $role);
            $this->assertSame(403, $this->json('POST', '/admin/updates', ['name' => 'x.zip', 'size' => '10'])['status'], $role);
        }
        $this->post('/logout', [], '/');
        $this->signIn('super_admin');
        $page = $this->get('/admin/updates')['body'];
        $this->assertStringContainsString('Upload an update', $page);
        $this->assertStringContainsString('data-updater', $page);
        $this->assertStringContainsString('/assets/js/updater.js', $page);
    }

    public function testFullUpdateThroughTheAdminThenEmergencyAndManualRollback(): void
    {
        $this->signIn('super_admin');
        $prepared = $this->uploadAndPrepare($this->package([
            'app/Core/App.php' => "<?php\n// v2\n", 'app/Services/Hello.php' => "<?php\n// new\n",
            'database/migrations/951_upd_notes.sql' => "CREATE TABLE upd_http_notes (id INT PRIMARY KEY, body VARCHAR(100) NULL) ENGINE=InnoDB;\n",
        ]));
        $this->assertSame('ready', $prepared['status']);
        $this->assertSame(['changed_count' => 1, 'added_count' => 2], array_intersect_key($prepared['files'], ['changed_count' => 0, 'added_count' => 0]));
        $this->assertSame('951_upd_notes.sql', $prepared['plan'][0]['file']);
        $this->assertArrayNotHasKey('rescue_hash', $prepared, 'no secrets in what the page receives');

        $bad = $this->json('POST', '/admin/updates/' . $prepared['uuid'] . '/approve', ['password' => 'nope'], []);
        $this->assertSame(303, $bad['status'], 'wrong password: refused');
        $ok = $this->json('POST', '/admin/updates/' . $prepared['uuid'] . '/approve', ['password' => 'Secret123'])['json'];
        $this->assertSame(43, strlen($ok['rescue_token']));
        $at = $this->stepUntil($prepared['uuid'], ['verifying', 'completed', 'rolled_back']);
        $this->assertSame('verifying', $at['status']);
        $this->assertSame("<?php\n// v2\n", file_get_contents($this->sandbox . '/app/Core/App.php'), 'switched');

        // The browser's checks fail (imagine the home page now errors): the page calls the emergency rollback.
        $this->assertSame(403, $this->http('POST', '/update-rescue.php', ['update' => $prepared['uuid'], 'token' => 'guess'])['status']);
        $r = $this->http('POST', '/update-rescue.php', ['update' => $prepared['uuid'], 'token' => $ok['rescue_token'], 'reason' => 'Home page answered 500']);
        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertTrue(json_decode($r['body'], true)['ok']);
        $this->assertSame("<?php\n// v1\n", file_get_contents($this->sandbox . '/app/Core/App.php'));
        $this->assertFileDoesNotExist($this->sandbox . '/app/Services/Hello.php');
        $this->assertNull($this->db->value("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'upd_http_notes'"));
        $this->assertSame('rolled_back', $this->db->value('SELECT status FROM system_updates WHERE uuid = ?', [$prepared['uuid']]));
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'system.update_rolled_back' AND after_json LIKE '%browser%'"));
        $this->assertSame(403, $this->http('POST', '/update-rescue.php', ['update' => $prepared['uuid'], 'token' => $ok['rescue_token']])['status'], 'token spent');

        // Again, this time letting it finish; then the manual rollback from the history.
        $prepared = $this->uploadAndPrepare($this->package(['app/Core/App.php' => "<?php\n// v3\n"]));
        $this->json('POST', '/admin/updates/' . $prepared['uuid'] . '/approve', ['password' => 'Secret123']);
        $this->stepUntil($prepared['uuid'], ['verifying']);
        $this->assertSame(200, $this->get('/health')['status'], 'browser checks pass');
        $done = $this->stepUntil($prepared['uuid'], ['completed', 'rolled_back']);
        $this->assertSame('completed', $done['status']);
        $this->assertSame("<?php\n// v3\n", file_get_contents($this->sandbox . '/app/Core/App.php'));
        $history = $this->get('/admin/updates')['body'];
        $this->assertStringContainsString('Installed', $history);
        $this->assertStringContainsString('Roll back</button>', $history);
        $r = $this->post('/admin/updates/' . $prepared['uuid'] . '/rollback', ['password' => 'Secret123'], '/admin/updates');
        $after = html_entity_decode($this->follow($r)['body']);
        preg_match_all('/<div[^>]*class="[^"]*alert[^"]*"[^>]*>(.*?)<\/div>/s', $after, $alerts);
        $this->assertStringContainsString('Rolled back: 1 files restored', $after, 'alerts: ' . strip_tags(implode(' | ', $alerts[1])));
        $this->assertSame("<?php\n// v1\n", file_get_contents($this->sandbox . '/app/Core/App.php'));
    }

    public function testBlockedUpdateShowsReasonsAndCanBeCancelled(): void
    {
        $this->signIn('super_admin');
        $j = $this->uploadAndPrepare($this->package(['database/migrations/952_upd_risky.sql' => "DELETE FROM settings;\n"]));
        $this->assertSame('blocked', $j['status']);
        $this->assertStringContainsString('Deletes existing rows', implode(' ', $j['blocked']));
        $this->assertSame(303, $this->json('POST', '/admin/updates/' . $j['uuid'] . '/approve', ['password' => 'Secret123'])['status'], 'cannot install');
        $this->assertGreaterThan(5, (int) $this->db->value('SELECT COUNT(*) FROM settings'), 'settings untouched');
        $this->assertContains($this->json('POST', '/admin/updates/' . $j['uuid'] . '/cancel')['status'], [200, 303]);
        $this->assertSame('cancelled', $this->db->value('SELECT status FROM system_updates WHERE uuid = ?', [$j['uuid']]));
    }
}
