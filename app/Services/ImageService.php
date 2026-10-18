<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Crypto;
use Waimart\Core\ValidationException;

/**
 * Safe image uploads: the original file is never stored. Images are validated by content,
 * decoded with GD and re-encoded to WebP (stripping metadata and any embedded payloads),
 * resized to a maximum edge, and saved under a random name.
 */
final class ImageService
{
    private const MAX_BYTES = 5 * 1024 * 1024;
    private const MAX_DIMENSION = 6000;
    private const OUTPUT_EDGE = 1000;
    private const ALLOWED = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * @param array{name?:string,tmp_name?:string,error?:int,size?:int} $file one entry from $_FILES
     * @return string public relative path, e.g. uploads/products/2026/10/abc.webp
     */
    public function storeProductImage(array $file): string
    {
        return $this->storeImage($file, 'products', self::OUTPUT_EDGE);
    }

    /**
     * @param array{name?:string,tmp_name?:string,error?:int,size?:int} $file
     * @param 'products'|'banners'|'stores' $folder
     */
    public function storeImage(array $file, string $folder, int $maxEdge): string
    {
        if (!in_array($folder, ['products', 'banners', 'stores'], true)) {
            throw new \InvalidArgumentException('Unknown upload folder.');
        }
        $src = $this->decode($file);

        [$w, $h] = [imagesx($src), imagesy($src)];
        $scale = min(1, $maxEdge / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        // White background so transparent PNGs look right on product cards.
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        $rel = "uploads/$folder/" . date('Y/m') . '/' . strtolower(Crypto::code(20)) . '.webp';
        $abs = App::root() . '/public/' . $rel;
        if (!is_dir(dirname($abs)) && !mkdir(dirname($abs), 0775, true) && !is_dir(dirname($abs))) {
            throw new \RuntimeException('Cannot create upload directory.');
        }
        if (!imagewebp($dst, $abs, 82)) {
            throw new \RuntimeException('Failed to save image.');
        }
        return $rel;
    }

    /**
     * Validates an upload by content (size, real type, dimensions) and decodes it with GD. The caller re-encodes it,
     * so the original bytes are never stored. Transparency is kept (callers decide the background).
     * @param array{name?:string,tmp_name?:string,error?:int,size?:int} $file
     */
    public function decode(array $file, string $field = 'image'): \GdImage
    {
        $tmp = (string) ($file['tmp_name'] ?? '');
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new ValidationException([$field => 'Image is too large (maximum 5 MB).']);
        }
        if ($error !== UPLOAD_ERR_OK || $tmp === '' || !is_file($tmp)) {
            throw new ValidationException([$field => 'Choose an image to upload.']);
        }
        // is_uploaded_file guards against path tricks; tests may pass a local file explicitly.
        if (PHP_SAPI !== 'cli' && !is_uploaded_file($tmp)) {
            throw new ValidationException([$field => 'Invalid upload.']);
        }
        if (filesize($tmp) > self::MAX_BYTES) {
            throw new ValidationException([$field => 'Image is too large (maximum 5 MB).']);
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!in_array($mime, self::ALLOWED, true)) {
            throw new ValidationException([$field => 'Only JPEG, PNG or WebP images are allowed.']);
        }
        $info = @getimagesize($tmp);
        if ($info === false || $info[0] < 1 || $info[1] < 1 || $info[0] > self::MAX_DIMENSION || $info[1] > self::MAX_DIMENSION) {
            throw new ValidationException([$field => 'The image could not be read or is too large in dimensions.']);
        }

        $src = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($tmp),
            'image/png'  => @imagecreatefrompng($tmp),
            'image/webp' => @imagecreatefromwebp($tmp),
        };
        if (!$src) {
            throw new ValidationException([$field => 'The image file is damaged or unsupported.']);
        }
        return $src;
    }

    /** Deletes a previously stored upload (only inside public/uploads: products, banners, stores, brand). */
    public function delete(string $relPath): void
    {
        if (!preg_match('#^uploads/(products|banners|stores|brand)/[\w/-]+\.(webp|svg|png)$#', $relPath) || str_contains($relPath, '..')) {
            return;
        }
        $abs = App::root() . '/public/' . $relPath;
        if (is_file($abs)) {
            @unlink($abs);
        }
    }
}
