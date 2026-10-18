<?php

declare(strict_types=1);

namespace Waimart\Tests\Http;

use PHPUnit\Framework\TestCase;

/**
 * Black-box tests through Apache (http://localhost/ecommerce). Skipped when Apache is not running.
 * Note: these hit the MAIN database configured for the web server, read-only.
 */
abstract class HttpTestCase extends TestCase
{
    protected string $base = 'http://localhost/ecommerce';

    private static ?bool $reachable = null;

    protected function setUp(): void
    {
        // Probe once per run: /health is itself rate-limited (30/min), so don't hit it per test.
        if (self::$reachable === null) {
            $ch = curl_init($this->base . '/');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_NOBODY => true]);
            self::$reachable = curl_exec($ch) !== false;
        }
        if (!self::$reachable) {
            $this->markTestSkipped('Apache is not reachable at ' . $this->base);
        }
    }

    /** @return array{status:int,headers:array<string,string>,body:string} */
    protected function request(string $method, string $path, array $post = [], array $headers = []): array
    {
        $ch = curl_init($this->base . $path);
        $respHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$respHeaders) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        if ($post) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $body = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        return ['status' => $status, 'headers' => $respHeaders, 'body' => $body];
    }
}
