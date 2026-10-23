<?php

declare(strict_types=1);

namespace Waimart\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Waimart\Core\ZipReader;
use Waimart\Tests\Support\ZipBuilder;

/** The updater's zip reader: real archives read exactly, unsafe archives refused. */
final class ZipReaderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/zrt-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    private function refused(callable $fn, string $needle): void
    {
        try {
            $fn();
            $this->fail("expected refusal: $needle");
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());
        }
    }

    public function testReadsStoredAndDeflatedEntriesExactly(): void
    {
        $big = str_repeat("Ecommerce line\n", 5000) . random_bytes(300);
        $zip = (new ZipBuilder())->add('app/a.php', "<?php echo 'a';\n")->add('docs/b.txt', $big, false)->add('empty.txt', '')
            ->write($this->dir . '/p.zip');
        $r = new ZipReader($zip);
        $names = array_column($r->entries(), 'name');
        $this->assertSame(['app/a.php', 'docs/b.txt', 'empty.txt'], $names);
        $this->assertSame("<?php echo 'a';\n", $r->read(0));
        $this->assertSame($big, $r->read(1));
        $this->assertSame('', $r->read(2));
    }

    public function testRefusesUnsafeArchives(): void
    {
        foreach (['../evil.php', 'a/../../evil.php', '/etc/passwd', 'C:/Windows/x.php', '..\\evil.php'] as $bad) {
            $zip = (new ZipBuilder())->add($bad, 'x')->write($this->dir . '/bad.zip');
            $this->refused(fn () => new ZipReader($zip), 'Unsafe path');
        }
        $zip = (new ZipBuilder())->add('link', '/etc/passwd', false, 0120777)->write($this->dir . '/link.zip');
        $this->refused(fn () => new ZipReader($zip), 'Symbolic links');
        $zip = (new ZipBuilder())->add('bomb.txt', str_repeat("\0", 5 * 1024 * 1024))->write($this->dir . '/bomb.zip');
        $this->refused(fn () => new ZipReader($zip), 'zip bomb');
        file_put_contents($this->dir . '/not.zip', 'hello, not a zip');
        $this->refused(fn () => new ZipReader($this->dir . '/not.zip'), 'not a .zip');

        // Damaged data: flip a byte inside a stored entry's data, the checksum catches it.
        $zip = (new ZipBuilder())->add('a.txt', 'abcdefghij', false)->write($this->dir . '/crc.zip');
        $raw = (string) file_get_contents($zip);
        $raw[30 + 5 + 2] = 'X';
        file_put_contents($zip, $raw);
        $this->refused(fn () => (new ZipReader($zip))->read(0), 'checksum mismatch');
    }

    public function testReadsArchivesMadeByWindows(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Windows only');
        }
        $src = $this->dir . '/src';
        mkdir($src . '/sub', 0777, true);
        file_put_contents($src . '/one.txt', str_repeat('Kenya ', 2000));
        file_put_contents($src . '/sub/two.php', "<?php\nreturn 2;\n");
        $zip = $this->dir . '/win.zip';
        exec('powershell -NoProfile -Command "Compress-Archive -Path ' . escapeshellarg($src . '\\*') . ' -DestinationPath ' . escapeshellarg($zip) . '"', $o, $code);
        $this->assertSame(0, $code, 'Compress-Archive ran');
        $r = new ZipReader($zip);
        $files = [];
        foreach ($r->entries() as $i => $e) {
            if (!$e['dir']) {
                $files[$e['name']] = $r->read($i);
            }
        }
        ksort($files);
        $this->assertSame(['one.txt' => str_repeat('Kenya ', 2000), 'sub/two.php' => "<?php\nreturn 2;\n"], $files);
        unlink($src . '/one.txt');
        unlink($src . '/sub/two.php');
        rmdir($src . '/sub');
        rmdir($src);
    }
}
