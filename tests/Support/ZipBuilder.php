<?php

declare(strict_types=1);

namespace Waimart\Tests\Support;

/** Writes .zip files for tests (deflate or stored), including deliberately unsafe ones. */
final class ZipBuilder
{
    /** @var list<array{name:string,data:string,deflate:bool,unixMode:int}> */
    private array $files = [];

    public function add(string $name, string $data, bool $deflate = true, int $unixMode = 0100644): self
    {
        $this->files[] = ['name' => $name, 'data' => $data, 'deflate' => $deflate, 'unixMode' => $unixMode];
        return $this;
    }

    /** Adds every file under $dir (relative names, forward slashes), optionally inside a top folder. */
    public function addDirectory(string $dir, string $prefix = ''): self
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile()) {
                $rel = str_replace('\\', '/', substr($f->getPathname(), strlen(rtrim($dir, '/\\')) + 1));
                $this->add($prefix . $rel, (string) file_get_contents($f->getPathname()));
            }
        }
        return $this;
    }

    public function write(string $path): string
    {
        $out = '';
        $cd = '';
        foreach ($this->files as $f) {
            $body = $f['deflate'] ? (string) gzdeflate($f['data'], 6) : $f['data'];
            $method = $f['deflate'] ? 8 : 0;
            $crc = crc32($f['data']) & 0xFFFFFFFF;
            $offset = strlen($out);
            $out .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, $method, 0, 0x5821, $crc, strlen($body), strlen($f['data']), strlen($f['name']), 0) . $f['name'] . $body;
            $cd .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, (3 << 8) | 20, 20, 0, $method, 0, 0x5821, $crc, strlen($body), strlen($f['data']),
                strlen($f['name']), 0, 0, 0, 0, ($f['unixMode'] << 16), $offset) . $f['name'];
        }
        $out .= $cd . pack('VvvvvVVv', 0x06054b50, 0, 0, count($this->files), count($this->files), strlen($cd), strlen($out), 0);
        file_put_contents($path, $out);
        return $path;
    }
}
