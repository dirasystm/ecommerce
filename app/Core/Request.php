<?php

declare(strict_types=1);

namespace Waimart\Core;

final class Request
{
    /** @var array<string,string> Route parameters set by the router. */
    public array $params = [];

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $post
     * @param array<string,mixed> $server
     * @param array<string,mixed> $files
     * @param array<string,mixed> $cookies
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $post = [],
        public readonly array $server = [],
        public readonly array $files = [],
        public readonly array $cookies = [],
    ) {
    }

    public static function fromGlobals(string $basePath): self
    {
        $uri = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $uri = rawurldecode($uri);
        $base = rtrim($basePath, '/');
        if ($base !== '' && str_starts_with(strtolower($uri), strtolower($base))) {
            $uri = substr($uri, strlen($base));
        }
        // Requests rewritten through /public should look like the root.
        if (str_starts_with($uri, '/public/')) {
            $uri = substr($uri, 7);
        }
        $path = '/' . trim($uri, '/');

        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $path,
            $_GET,
            $_POST,
            $_SERVER,
            $_FILES,
            $_COOKIE,
        );
    }

    public function input(string $key, mixed $default = null): mixed
    {
        $v = $this->post[$key] ?? $this->query[$key] ?? $default;
        return is_string($v) ? trim($v) : $v;
    }

    /** @return array<string,mixed> */
    public function only(string ...$keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = $this->input($k);
        }
        return $out;
    }

    public function param(string $key, ?string $default = null): ?string
    {
        return $this->params[$key] ?? $default;
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function isAjax(): bool
    {
        return ($this->server['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
            || str_contains((string) ($this->server['HTTP_ACCEPT'] ?? ''), 'application/json');
    }

    public function ip(): string
    {
        // REMOTE_ADDR only: forwarded headers are spoofable unless behind a known proxy.
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function userAgent(): string
    {
        return mb_substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }
}
