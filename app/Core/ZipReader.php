<?php

declare(strict_types=1);

namespace Waimart\Core;

/**
 * Reads .zip archives without the zip extension (it is often disabled on shared hosting): stored and deflated
 * entries, verified by CRC-32. Built for the website updater, so it refuses anything unsafe rather than guessing:
 * paths that leave the destination (../, absolute paths, drive letters), symbolic links, encrypted entries, ZIP64
 * archives, and archives or entries big enough to be a "zip bomb".
 */
final class ZipReader
{
    public const MAX_ENTRIES = 50000;
    public const MAX_TOTAL_BYTES = 1024 * 1024 * 1024;   // 1 GB unpacked
    public const MAX_RATIO = 200;                        // unpacked : packed, for entries over 1 MB

    /** @var resource */
    private $fh;
    /** @var list<array{name:string,method:int,crc:int,packed:int,size:int,offset:int,dir:bool}> */
    private array $entries = [];

    public function __construct(private string $path)
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            throw new \RuntimeException('The package could not be opened.');
        }
        $this->fh = $fh;
        $this->readCentralDirectory();
    }

    public function __destruct()
    {
        if (is_resource($this->fh)) {
            fclose($this->fh);
        }
    }

    /** @return list<array{name:string,method:int,crc:int,packed:int,size:int,offset:int,dir:bool}> */
    public function entries(): array
    {
        return $this->entries;
    }

    /** The unpacked bytes of one entry (by its index in entries()), checked against its CRC-32. */
    public function read(int $index): string
    {
        $e = $this->entries[$index] ?? throw new \OutOfRangeException("No entry $index");
        if ($e['dir']) {
            return '';
        }
        fseek($this->fh, $e['offset']);
        $local = fread($this->fh, 30);
        if (strlen((string) $local) !== 30 || substr($local, 0, 4) !== "PK\x03\x04") {
            throw new \RuntimeException("The package is damaged ({$e['name']}).");
        }
        $h = unpack('vnameLen/vextraLen', substr($local, 26, 4));
        fseek($this->fh, $e['offset'] + 30 + $h['nameLen'] + $h['extraLen']);
        $packed = $e['packed'] > 0 ? (string) fread($this->fh, $e['packed']) : '';
        if (strlen($packed) !== $e['packed']) {
            throw new \RuntimeException("The package is incomplete ({$e['name']}).");
        }
        $data = match ($e['method']) {
            0 => $packed,
            8 => @gzinflate($packed, $e['size'] + 1),
            default => throw new \RuntimeException("Unsupported compression in {$e['name']} (method {$e['method']}). Use a standard .zip (deflate)."),
        };
        if ($data === false || strlen($data) !== $e['size']) {
            throw new \RuntimeException("The package is damaged ({$e['name']}): wrong size after unpacking.");
        }
        if ((crc32($data) & 0xFFFFFFFF) !== $e['crc']) {
            throw new \RuntimeException("The package is damaged ({$e['name']}): checksum mismatch.");
        }
        return $data;
    }

    /** Normalises an entry name to a safe relative path, or throws if it could escape the destination. */
    public static function safePath(string $name): string
    {
        $p = str_replace('\\', '/', $name);
        if ($p === '' || str_starts_with($p, '/') || preg_match('#^[A-Za-z]:#', $p) || str_contains($p, "\0")) {
            throw new \RuntimeException("Unsafe path in the package: $name");
        }
        $parts = [];
        foreach (explode('/', $p) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                throw new \RuntimeException("Unsafe path in the package: $name");
            }
            $parts[] = $seg;
        }
        if (!$parts) {
            throw new \RuntimeException("Unsafe path in the package: $name");
        }
        return implode('/', $parts) . (str_ends_with($p, '/') ? '/' : '');
    }

    private function readCentralDirectory(): void
    {
        $size = (int) (fstat($this->fh)['size'] ?? 0);   // from the open file: filesize() may be a stale cached value
        if ($size < 22) {
            throw new \RuntimeException('This is not a .zip file.');
        }
        // End-of-central-directory record: within the last 64 KB + 22 bytes (it may be followed by a comment).
        $tail = min($size, 65557);
        fseek($this->fh, $size - $tail);
        $buf = (string) fread($this->fh, $tail);
        $pos = strrpos($buf, "PK\x05\x06");
        if ($pos === false) {
            throw new \RuntimeException('This is not a .zip file (or it is damaged).');
        }
        $rec = substr($buf, $pos + 4, 16);
        if (strlen($rec) !== 16) {
            throw new \RuntimeException('This is not a .zip file (or it is damaged).');
        }
        $eocd = unpack('vdisk/vcdDisk/vdiskEntries/ventries/VcdSize/VcdOffset', $rec);
        if ($eocd['entries'] === 0xFFFF || $eocd['cdOffset'] === 0xFFFFFFFF || $eocd['disk'] !== 0) {
            throw new \RuntimeException('Large (ZIP64) or multi-part archives are not supported. Zip the project normally (it is far below 4 GB).');
        }
        if ($eocd['entries'] > self::MAX_ENTRIES) {
            throw new \RuntimeException('The package has too many files.');
        }
        fseek($this->fh, $eocd['cdOffset']);
        $cd = (string) fread($this->fh, $eocd['cdSize']);
        $at = 0;
        $total = 0;
        for ($i = 0; $i < $eocd['entries']; $i++) {
            if (substr($cd, $at, 4) !== "PK\x01\x02" || strlen($cd) < $at + 46) {
                throw new \RuntimeException('The package is damaged (central directory).');
            }
            $h = unpack('vmadeBy/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vpacked/Vsize/vnameLen/vextraLen/vcommentLen/vdisk/vintAttr/VextAttr/Voffset', substr($cd, $at + 4, 42));
            $name = substr($cd, $at + 46, $h['nameLen']);
            $at += 46 + $h['nameLen'] + $h['extraLen'] + $h['commentLen'];
            if ($h['packed'] === 0xFFFFFFFF || $h['size'] === 0xFFFFFFFF || $h['offset'] === 0xFFFFFFFF) {
                throw new \RuntimeException('Large (ZIP64) entries are not supported.');
            }
            if ($h['flags'] & 0x1) {
                throw new \RuntimeException("Encrypted files are not supported ($name).");
            }
            $unixMode = ($h['madeBy'] >> 8) === 3 ? ($h['extAttr'] >> 16) & 0xFFFF : 0;
            if (($unixMode & 0xF000) === 0xA000) {
                throw new \RuntimeException("Symbolic links are not allowed in the package ($name).");
            }
            $isDir = str_ends_with(str_replace('\\', '/', $name), '/') || ($h['extAttr'] & 0x10) === 0x10;
            $path = self::safePath($name);
            if (!$isDir && $h['size'] > 1024 * 1024 && $h['packed'] > 0 && $h['size'] / $h['packed'] > self::MAX_RATIO) {
                throw new \RuntimeException("Refused: $name unpacks suspiciously large (possible zip bomb).");
            }
            $total += $h['size'];
            if ($total > self::MAX_TOTAL_BYTES) {
                throw new \RuntimeException('Refused: the package unpacks to more than 1 GB.');
            }
            $this->entries[] = ['name' => rtrim($path, '/'), 'method' => $h['method'], 'crc' => $h['crc'], 'packed' => $h['packed'],
                                'size' => $h['size'], 'offset' => $h['offset'], 'dir' => $isDir];
        }
    }
}
