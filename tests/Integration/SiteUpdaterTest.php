<?php

declare(strict_types=1);

namespace Waimart\Tests\Integration;

use Waimart\Services\SiteUpdater;
use Waimart\Services\UpdateRescue;
use Waimart\Tests\Support\ZipBuilder;

/**
 * The website updater, end to end, on a sandbox project (a temporary folder) and the test database: only changed and
 * new files are applied, protected files are never touched, only additive database changes run, existing rows are
 * untouched, and every failure (or a manual rollback) puts the website back exactly as it was.
 */
final class SiteUpdaterTest extends DbTestCase
{
    private string $root;
    private string $storage;
    private int $admin;
    private string $composer = '';

    protected function setUp(): void
    {
        parent::setUp();
        $base = sys_get_temp_dir() . '/bmupd-' . bin2hex(random_bytes(4));
        $this->root = $base . '/site';
        $this->storage = $base . '/updates';
        mkdir($this->storage, 0777, true);
        $this->composer = (string) json_encode(['name' => json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true)['name']]);
        $this->write($this->root, [
            'composer.json' => $this->composer, 'bootstrap.php' => "<?php\n// v1\n", 'app/Core/App.php' => "<?php\n// app v1\n",
            'app/Services/Same.php' => "<?php\n// unchanged\n", 'public/assets/css/site.css' => "body{color:#000}\n",
            'config/config.local.php' => "<?php return ['secret' => 'live'];\n", 'storage/logs/app.log' => "live log\n",
            'database/migrations/001_foundation.sql' => "-- already applied on this site\n",
        ]);
        $this->admin = $this->db->insert('users', ['email' => 'root@example.com', 'password_hash' => password_hash('Secret123', PASSWORD_DEFAULT),
            'first_name' => 'Root', 'last_name' => 'Admin', 'is_staff' => 1]);
        $this->db->pdo()->exec('DROP TABLE IF EXISTS upd_test_widgets');
        $this->db->pdo()->exec('DROP TABLE IF EXISTS upd_test_base');
        $this->db->pdo()->exec('CREATE TABLE upd_test_base (id INT PRIMARY KEY, name VARCHAR(40) NOT NULL) ENGINE=InnoDB');
        $this->db->pdo()->exec("INSERT INTO upd_test_base VALUES (1, 'existing one'), (2, 'existing two')");
    }

    protected function tearDown(): void
    {
        $this->db->pdo()->exec('DROP TABLE IF EXISTS upd_test_widgets');
        $this->db->pdo()->exec('DROP TABLE IF EXISTS upd_test_base');
        $this->db->exec("DELETE FROM schema_migrations WHERE filename LIKE '9__\\_upd\\_%'");
        $this->rm(dirname($this->root));
        parent::tearDown();
    }

    /** @param array<string,string> $files */
    private function write(string $dir, array $files): void
    {
        foreach ($files as $rel => $content) {
            @mkdir(dirname($dir . '/' . $rel), 0777, true);
            file_put_contents($dir . '/' . $rel, $content);
        }
    }

    private function rm(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }

    /** A package = the live sandbox files with $changes applied, zipped (optionally inside a top folder). @param array<string,string> $changes */
    private function package(array $changes, string $prefix = '', ?string $composer = null): string
    {
        $z = new ZipBuilder();
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $files[str_replace('\\', '/', substr($f->getPathname(), strlen($this->root) + 1))] = (string) file_get_contents($f->getPathname());
        }
        $files = array_merge($files, $changes);
        if ($composer !== null) {
            $files['composer.json'] = $composer;
        }
        foreach ($files as $rel => $content) {
            $z->add($prefix . $rel, $content);
        }
        return $z->write(dirname($this->root) . '/package-' . bin2hex(random_bytes(3)) . '.zip');
    }

    private function updater(): SiteUpdater
    {
        return new SiteUpdater($this->db, $this->root, $this->storage);
    }

    /** Uploads in small chunks and prepares; returns the journal at the first stopping point. @return array<string,mixed> */
    private function upload(string $zip, int $chunk = 700): array
    {
        $u = $this->updater();
        $data = (string) file_get_contents($zip);
        $j = $u->begin('site-v2.zip', strlen($data), $this->admin);
        foreach (str_split($data, $chunk) as $i => $part) {
            $j = $u->appendChunk($j['uuid'], $i, $part);
        }
        $this->assertSame('uploaded', $j['status']);
        return $this->run_($j['uuid'], ['ready', 'blocked', 'failed', 'nothing']);
    }

    /** @param list<string> $stops @return array<string,mixed> */
    private function run_(string $uuid, array $stops): array
    {
        $u = $this->updater();
        $seen = [];
        for ($i = 0; $i < 200; $i++) {
            $j = $u->step($uuid);
            $seen[] = $j['progress']['percent'];
            if (in_array($j['status'], $stops, true)) {
                $j['_percents'] = $seen;
                return $j;
            }
        }
        $this->fail('the update did not finish');
    }

    private function install(string $uuid): array
    {
        $this->updater()->approve($uuid, 'Secret123', $this->admin);
        return $this->run_($uuid, ['completed', 'rolled_back', 'failed']);
    }

    public function testOnlyChangedAndNewFilesAndAdditiveSchemaAreInstalledThenRolledBack(): void
    {
        $migration = "CREATE TABLE upd_test_widgets (id INT PRIMARY KEY, label VARCHAR(40) NOT NULL) ENGINE=InnoDB;\n"
            . "ALTER TABLE upd_test_base ADD COLUMN colour VARCHAR(20) NULL, ADD COLUMN size INT NOT NULL DEFAULT 1, ADD INDEX ix_upd_colour (colour);\n"
            . "INSERT INTO upd_test_widgets VALUES (1, 'shipped');\n";
        $zip = $this->package([
            'app/Core/App.php' => "<?php\n// app v2\n",                                  // changed
            'app/Services/NewThing.php' => "<?php\n// brand new\n",                      // added, in an existing folder
            'public/assets/img/new/logo.txt' => "new folder\n",                          // added, in new folders
            'database/migrations/901_upd_widgets.sql' => $migration,                     // added
            'config/config.local.php' => "<?php return ['secret' => 'HACKED'];\n",       // protected: ignored
            'storage/logs/app.log' => "overwritten\n",                                   // protected: ignored
            '__MACOSX/._junk' => 'x', 'Thumbs.db' => 'x',                                // clutter: ignored
        ], 'ecommerce-v2/');
        $j = $this->upload($zip);
        $this->assertSame('ready', $j['status'], json_encode($j['blocked'] ?? []));
        $this->assertSame(['app/Core/App.php'], $j['files']['changed']);
        sort($j['files']['added']);
        $this->assertSame(['app/Services/NewThing.php', 'database/migrations/901_upd_widgets.sql', 'public/assets/img/new/logo.txt'], $j['files']['added']);
        $this->assertSame(5, $j['files']['unchanged']);
        $this->assertSame(4, $j['skipped'], 'protected and clutter files never read');
        $this->assertSame('901_upd_widgets.sql', $j['plan'][0]['file']);
        $this->assertSame("<?php\n// app v1\n", file_get_contents($this->root . '/app/Core/App.php'), 'preparing changes nothing');
        $sorted = $j['_percents'];
        sort($sorted);
        $this->assertSame($sorted, $j['_percents'], 'progress only moves forward');
        $this->assertSame(100, end($j['_percents']));

        $this->expectPasswordRefused($j['uuid']);
        $done = $this->install($j['uuid']);
        $this->assertSame('completed', $done['status'], (string) ($done['failure'] ?? ''));
        $this->assertSame(100, $done['progress']['percent']);
        $this->assertSame("<?php\n// app v2\n", file_get_contents($this->root . '/app/Core/App.php'));
        $this->assertSame("new folder\n", file_get_contents($this->root . '/public/assets/img/new/logo.txt'));
        $this->assertSame("<?php return ['secret' => 'live'];\n", file_get_contents($this->root . '/config/config.local.php'), 'protected file untouched');
        $this->assertSame("live log\n", file_get_contents($this->root . '/storage/logs/app.log'));
        $this->assertSame([], glob($this->root . '/app/Core/*.bmupd*') ?: [], 'no temporary files left');
        $this->assertSame([['id' => 1, 'name' => 'existing one', 'colour' => null, 'size' => 1], ['id' => 2, 'name' => 'existing two', 'colour' => null, 'size' => 1]],
            array_map(fn ($r) => array_map(fn ($v) => is_numeric($v) ? (int) $v : $v, $r), $this->db->all('SELECT id, name, colour, size FROM upd_test_base ORDER BY id')), 'existing rows untouched');
        $this->assertSame('shipped', $this->db->value('SELECT label FROM upd_test_widgets WHERE id = 1'));
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM schema_migrations WHERE filename = '901_upd_widgets.sql'"));
        $row = $this->db->one('SELECT * FROM system_updates WHERE uuid = ?', [$j['uuid']]);
        $this->assertSame(['completed', 1, 3, 1], [$row['status'], (int) $row['files_changed'], (int) $row['files_added'], (int) $row['db_changes']]);
        $this->assertFileExists($this->updater()->dir($j['uuid']) . '/database-before.sql', 'database backed up first');
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'system.update_completed'"));

        // Data written by the new version, then a manual rollback: back exactly to before, existing rows untouched.
        $this->db->exec("UPDATE upd_test_base SET colour = 'red' WHERE id = 1");
        $this->assertFalse($this->tryRollback($j['uuid'], 'wrong'), 'password required');
        $r = $this->updater()->rollback($j['uuid'], 'Secret123', $this->admin);
        $this->assertTrue($r['ok'], json_encode($r['errors']));
        $this->assertSame("<?php\n// app v1\n", file_get_contents($this->root . '/app/Core/App.php'));
        $this->assertFileDoesNotExist($this->root . '/app/Services/NewThing.php');
        $this->assertDirectoryDoesNotExist($this->root . '/public/assets/img', 'folders the update created are removed');
        $this->assertNull($this->db->value("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'upd_test_widgets'"));
        $this->assertSame(['id', 'name'], array_column($this->db->all('SHOW COLUMNS FROM upd_test_base'), 'Field'));
        $this->assertSame(['existing one', 'existing two'], array_column($this->db->all('SELECT name FROM upd_test_base ORDER BY id'), 'name'));
        $this->assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM schema_migrations WHERE filename = '901_upd_widgets.sql'"));
        $this->assertSame('rolled_back', $this->db->value('SELECT status FROM system_updates WHERE uuid = ?', [$j['uuid']]));
    }

    private function expectPasswordRefused(string $uuid): void
    {
        try {
            $this->updater()->approve($uuid, 'not-my-password', $this->admin);
            $this->fail('password required');
        } catch (\Waimart\Core\ValidationException $e) {
            $this->assertStringContainsString('incorrect', json_encode($e->errors));
        }
    }

    private function tryRollback(string $uuid, string $password): bool
    {
        try {
            $this->updater()->rollback($uuid, $password, $this->admin);
            return true;
        } catch (\Waimart\Core\ValidationException) {
            return false;
        }
    }

    public function testUnsafeDatabaseChangesAndBrokenCodeAreBlockedBeforeAnythingChanges(): void
    {
        $j = $this->upload($this->package([
            'app/Core/App.php' => "<?php\n// v2\n",
            'database/migrations/902_upd_risky.sql' => "ALTER TABLE upd_test_base ADD COLUMN note TEXT NULL;\nUPDATE upd_test_base SET name = 'oops';\nALTER TABLE upd_test_base ADD COLUMN must INT NOT NULL;\n",
            'app/Services/Broken.php' => "<?php\nfunction ( {\n",
        ]));
        $this->assertSame('blocked', $j['status']);
        $reasons = implode("\n", $j['blocked']);
        $this->assertStringContainsString('902_upd_risky.sql: Changes existing rows (UPDATE).', $reasons);
        $this->assertStringContainsString('has no DEFAULT', $reasons);
        $this->assertStringContainsString('PHP syntax error in app/Services/Broken.php', $reasons);
        $this->assertSame("<?php\n// app v1\n", file_get_contents($this->root . '/app/Core/App.php'));
        $this->assertSame(['id', 'name'], array_column($this->db->all('SHOW COLUMNS FROM upd_test_base'), 'Field'));
        try {
            $this->updater()->approve($j['uuid'], 'Secret123', $this->admin);
            $this->fail('a blocked update cannot be installed');
        } catch (\Waimart\Core\ValidationException $e) {
            $this->assertStringContainsString('not ready', $e->getMessage());
        }
        $this->updater()->cancel($j['uuid'], $this->admin);
        $this->assertNull($this->updater()->active(), 'a new update can start');
    }

    public function testWrongProjectAndUploadMistakesAreRefused(): void
    {
        $j = $this->upload($this->package(['app/Core/App.php' => "<?php\n"], '', '{"name":"someone/else"}'));
        $this->assertSame('failed', $j['status']);
        $this->assertStringContainsString('different project', $j['progress']['message']);
        $this->assertStringContainsString('Nothing on the website was changed', $j['progress']['message']);

        $u = $this->updater();
        $j = $u->begin('v3.zip', 10, $this->admin);
        $this->expectFailure(fn () => $u->begin('v4.zip', 10, $this->admin), 'Another update is in progress');
        $this->expectFailure(fn () => $u->appendChunk($j['uuid'], 1, 'abc'), 'out of order');
        $this->expectFailure(fn () => $u->appendChunk($j['uuid'], 0, str_repeat('x', 11)), 'too large');
        $this->expectFailure(fn () => $u->begin('notes.txt', 10, $this->admin), 'Another update');
        $u->cancel($j['uuid'], $this->admin);
        $this->expectFailure(fn () => $u->begin('notes.txt', 10, $this->admin), '.zip');
    }

    private function expectFailure(callable $fn, string $needle): void
    {
        try {
            $fn();
            $this->fail("expected: $needle");
        } catch (\Waimart\Core\ValidationException $e) {
            $this->assertStringContainsString($needle, $e->getMessage() . json_encode($e->errors));
        }
    }

    public function testAFailureMidInstallRollsEverythingBackAutomatically(): void
    {
        // The second statement fails at run time (the table already exists): the first one's table must go again.
        $j = $this->upload($this->package([
            'app/Core/App.php' => "<?php\n// v2\n",
            'app/Fresh/Thing.php' => "<?php\n// new folder\n",
            'database/migrations/903_upd_fails.sql' => "CREATE TABLE upd_test_widgets (id INT PRIMARY KEY) ENGINE=InnoDB;\nCREATE TABLE upd_test_base (id INT PRIMARY KEY) ENGINE=InnoDB;\n",
        ]));
        $this->assertSame('ready', $j['status']);
        $done = $this->install($j['uuid']);
        $this->assertSame('rolled_back', $done['status']);
        $this->assertStringContainsString('rolled back automatically', $done['progress']['message']);
        $this->assertSame("<?php\n// app v1\n", file_get_contents($this->root . '/app/Core/App.php'));
        $this->assertDirectoryDoesNotExist($this->root . '/app/Fresh');
        $this->assertSame([], glob($this->root . '/app/Core/*.bmupd*') ?: [], 'temporary copies removed');
        $this->assertNull($this->db->value("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'upd_test_widgets'"));
        $this->assertSame(2, (int) $this->db->value('SELECT COUNT(*) FROM upd_test_base'), 'the existing table and its rows are untouched');
        $this->assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM schema_migrations WHERE filename = '903_upd_fails.sql'"));
        $this->assertSame('rolled_back', $this->db->value('SELECT status FROM system_updates WHERE uuid = ?', [$j['uuid']]));
    }

    public function testEmergencyRollbackWorksWithoutTheRestOfTheWebsite(): void
    {
        $j = $this->upload($this->package([
            'app/Core/App.php' => "<?php\n// v2 that turns out to be broken at run time\n",
            'database/migrations/904_upd_add.sql' => "ALTER TABLE upd_test_base ADD COLUMN flag TINYINT NULL;\n",
        ]));
        $approved = $this->updater()->approve($j['uuid'], 'Secret123', $this->admin);
        $this->run_($j['uuid'], ['verifying']);   // files switched and database changed; the browser's checks now fail
        $this->assertSame("<?php\n// v2 that turns out to be broken at run time\n", file_get_contents($this->root . '/app/Core/App.php'));
        $journal = $this->updater()->journal($j['uuid']);
        $this->assertFalse(SiteUpdater::rescueAllowed($journal, 'not-the-token'));
        $this->assertTrue(SiteUpdater::rescueAllowed($journal, $approved['rescue_token']));

        $r = UpdateRescue::rollback($this->updater()->dir($j['uuid']) . '/journal.json');
        $this->assertTrue($r['ok'], json_encode($r['errors']));
        $this->assertSame("<?php\n// app v1\n", file_get_contents($this->root . '/app/Core/App.php'));
        $this->assertSame(['id', 'name'], array_column($this->db->all('SHOW COLUMNS FROM upd_test_base'), 'Field'));
        $this->assertSame('rolled_back', $this->db->value('SELECT status FROM system_updates WHERE uuid = ?', [$j['uuid']]));
        $again = UpdateRescue::rollback($this->updater()->dir($j['uuid']) . '/journal.json');
        $this->assertSame("<?php\n// app v1\n", file_get_contents($this->root . '/app/Core/App.php'), 'running it twice is harmless');
        $this->assertFalse(SiteUpdater::rescueAllowed($this->updater()->journal($j['uuid']), $approved['rescue_token']), 'the token is spent');
        $this->assertIsArray($again);
    }

    public function testFilesOnlyUpdateBacksUpAndRollsBack(): void
    {
        $j = $this->upload($this->package(['app/Core/App.php' => "<?php\n// v2\n", 'public/assets/css/site.css' => "body{color:#005B3D}\n"]));
        $this->assertSame([], $j['plan'], 'no database changes');
        $done = $this->install($j['uuid']);
        $this->assertSame('completed', $done['status'], (string) ($done['failure'] ?? ''));
        $this->assertFileDoesNotExist($this->updater()->dir($j['uuid']) . '/database-before.sql', 'no database backup needed');
        $this->assertSame("<?php\n// app v1\n", file_get_contents($this->updater()->dir($j['uuid']) . '/backup/app/Core/App.php'), 'original kept');
        $r = $this->updater()->rollback($j['uuid'], 'Secret123', $this->admin);
        $this->assertSame([true, 2], [$r['ok'], $r['files_restored']], json_encode($r['errors']));
        $this->assertSame("<?php\n// app v1\n", file_get_contents($this->root . '/app/Core/App.php'));
        $this->assertSame("body{color:#000}\n", file_get_contents($this->root . '/public/assets/css/site.css'));
    }

    public function testAnUnchangedPackageHasNothingToInstall(): void
    {
        $j = $this->upload($this->package([]));
        $this->assertSame('nothing', $j['status']);
        $this->assertNull($this->updater()->active());
    }
}
