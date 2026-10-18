<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\ValidationException;

/**
 * Private storage for seller verification documents, OUTSIDE the web root (storage/private/seller-docs).
 * Files get random names with no extension; type is checked from the content, never the file name.
 */
final class SellerDocumentStore
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const ALLOWED = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

    public static function dir(): string
    {
        return App::root() . '/storage/private/seller-docs';
    }

    /**
     * Validates and stores an upload.
     * @param array{name?:string,tmp_name?:string,error?:int,size?:int} $file one entry from $_FILES
     * @return array{stored_name:string,mime_type:string,size_bytes:int,sha256:string,original_name:string}
     */
    public function store(array $file): array
    {
        $tmp = (string) ($file['tmp_name'] ?? '');
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new ValidationException(['document' => 'The file is too large (maximum 5 MB).']);
        }
        if ($error !== UPLOAD_ERR_OK || $tmp === '' || !is_file($tmp)) {
            throw new ValidationException(['document' => 'Choose a file to upload.']);
        }
        if (PHP_SAPI !== 'cli' && !is_uploaded_file($tmp)) {
            throw new ValidationException(['document' => 'Invalid upload.']);
        }
        $size = (int) filesize($tmp);
        if ($size < 1 || $size > self::MAX_BYTES) {
            throw new ValidationException(['document' => 'The file must be between 1 byte and 5 MB.']);
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!isset(self::ALLOWED[$mime])) {
            throw new ValidationException(['document' => 'Upload a PDF, JPEG or PNG file.']);
        }
        // Content checks beyond the MIME sniff: real PDF header / decodable image.
        $head = (string) file_get_contents($tmp, false, null, 0, 1024);
        if ($mime === 'application/pdf' && !str_starts_with($head, '%PDF-')) {
            throw new ValidationException(['document' => 'The PDF file appears to be damaged.']);
        }
        if ($mime !== 'application/pdf' && @getimagesize($tmp) === false) {
            throw new ValidationException(['document' => 'The image could not be read.']);
        }
        if (preg_match('/<\?php|<script/i', $head)) {
            throw new ValidationException(['document' => 'This file type is not allowed.']);
        }

        $name = bin2hex(random_bytes(20));
        $path = $this->path($name);
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0770, true) && !is_dir(dirname($path))) {
            throw new \RuntimeException('Cannot create the document directory.');
        }
        if (!copy($tmp, $path)) {
            throw new \RuntimeException('Failed to store the document.');
        }
        return [
            'stored_name' => $name,
            'mime_type' => $mime,
            'size_bytes' => $size,
            'sha256' => hash_file('sha256', $path),
            'original_name' => self::safeName((string) ($file['name'] ?? 'document'), self::ALLOWED[$mime]),
        ];
    }

    /** Absolute path for a stored name; anything but 40 hex characters is refused (no traversal possible). */
    public function path(string $storedName): string
    {
        if (!preg_match('/^[a-f0-9]{40}$/', $storedName)) {
            throw new \InvalidArgumentException('Invalid document reference.');
        }
        return self::dir() . '/' . substr($storedName, 0, 2) . '/' . $storedName;
    }

    public function read(string $storedName): ?string
    {
        $path = $this->path($storedName);
        return is_file($path) ? (string) file_get_contents($path) : null;
    }

    public function delete(string $storedName): void
    {
        $path = $this->path($storedName);
        if (is_file($path)) {
            unlink($path);
            @rmdir(dirname($path));   // only succeeds once the shard folder is empty
        }
    }

    /**
     * Sends a stored document as a download (never rendered inline), with no caching.
     * @param array<string,mixed> $doc a seller_documents row the caller has already authorised
     */
    public static function download(array $doc): \Waimart\Core\Response
    {
        $mime = (string) $doc['mime_type'];
        $content = isset(self::ALLOWED[$mime]) ? (new self())->read((string) $doc['stored_name']) : null;
        if ($content === null) {
            throw \Waimart\Core\HttpException::notFound('This document is no longer available.');
        }
        $name = self::safeName((string) $doc['original_name'], self::ALLOWED[$mime]);
        return (new \Waimart\Core\Response($content, 200, ['Content-Type' => $mime]))
            ->withHeader('Content-Disposition', 'attachment; filename="' . $name . '"')
            ->withHeader('Content-Length', (string) strlen($content))
            ->withHeader('Cache-Control', 'no-store, private')
            ->withHeader('X-Robots-Tag', 'noindex');
    }

    /** Display name only (never used on disk): letters, digits, dot, dash, underscore; correct extension. */
    public static function safeName(string $name, string $ext): string
    {
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '_', pathinfo($name, PATHINFO_FILENAME)) ?: 'document';
        return mb_substr(trim($base, '._') ?: 'document', 0, 100) . '.' . $ext;
    }
}
