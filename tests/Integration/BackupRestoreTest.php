<?php

declare(strict_types=1);

namespace Waimart\Tests\Integration;

use Waimart\Core\Backup;

/** Backup → wipe → restore round trip on the test database, plus the file copy used for uploads (Phase 13). */
final class BackupRestoreTest extends DbTestCase
{
    public function testDumpAndImportRoundTripRestoresDataExactly(): void
    {
        $v = $this->makeVariant(7, '1234.50');
        $this->makeOrder($v['variant_id'], 2);
        $name = (string) config('db.database');
        $before = $this->checksums($name);
        $file = tempnam(sys_get_temp_dir(), 'wmt') . '.sql';
        try {
            Backup::dump($name, $file);
            Backup::emptyDatabase($this->db, $name);
            $this->assertSame([], Backup::tables($this->db, $name), 'database really emptied');

            Backup::import($name, $file);
            $this->assertSame($before, $this->checksums($name), 'every table restored byte-for-byte');
            $this->assertSame(7, (int) $this->db->value('SELECT on_hand FROM inventory WHERE variant_id = ?', [$v['variant_id']]));
        } finally {
            @unlink($file);
        }
    }

    public function testImportRejectsMissingFileAndUnsafeNames(): void
    {
        $this->expectException(\RuntimeException::class);
        Backup::import((string) config('db.database'), sys_get_temp_dir() . '/does-not-exist.sql');
    }

    public function testUnsafeDatabaseNameIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Backup::dump('waimart; DROP DATABASE x', sys_get_temp_dir() . '/x.sql');
    }

    public function testCopyTreeCopiesNestedFiles(): void
    {
        $src = sys_get_temp_dir() . '/wm-src-' . bin2hex(random_bytes(4));
        $dst = sys_get_temp_dir() . '/wm-dst-' . bin2hex(random_bytes(4));
        mkdir("$src/products/2026", 0775, true);
        file_put_contents("$src/products/2026/a.webp", 'A');
        file_put_contents("$src/.htaccess", 'deny');
        try {
            $this->assertSame(2, Backup::copyTree($src, $dst));
            $this->assertSame('A', file_get_contents("$dst/products/2026/a.webp"));
            $this->assertSame('deny', file_get_contents("$dst/.htaccess"));
        } finally {
            Backup::removeTree($src);
            Backup::removeTree($dst);
        }
        $this->assertDirectoryDoesNotExist($dst);
    }

    /** @return array<string,string> */
    private function checksums(string $database): array
    {
        $out = [];
        foreach (Backup::tables($this->db, $database) as $t) {
            $out[$t] = (string) $this->db->one("CHECKSUM TABLE `$database`.`$t`")['Checksum'];
        }
        return $out;
    }
}
