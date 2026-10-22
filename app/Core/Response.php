<?php

declare(strict_types=1);

namespace Waimart\Core;

final class Response
{
    /** @param array<string,string> $headers */
    public function __construct(
        public string $body = '',
        public int $status = 200,
        public array $headers = [],
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self(
            (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8']
        );
    }

    /** Redirect to an internal path (base path added) or an absolute URL. */
    public static function redirect(string $to, int $status = 303): self
    {
        $location = preg_match('#^https?://#i', $to) || str_starts_with($to, (string) config('app.base_path', '') . '/')
            ? $to
            : url($to);
        return new self('', $status, ['Location' => $location]);
    }

    /** Redirect back with flashed errors and old input (passwords excluded). */
    public static function back(Request $req, array $errors = [], string $fallback = '/'): self
    {
        $old = array_filter(
            $req->post,
            // Secrets and payout account details are never kept in the session for re-display.
            fn ($k) => !in_array($k, ['password', 'password_confirmation', 'current_password', '_csrf', 'payout_phone', 'account_number'], true),
            ARRAY_FILTER_USE_KEY
        );
        Session::flash('_old_input', $old);
        if ($errors) {
            Session::flash('_errors', $errors);
        }
        $ref = (string) ($req->server['HTTP_REFERER'] ?? '');
        $host = (string) ($req->server['HTTP_HOST'] ?? '');
        $safe = $ref !== '' && parse_url($ref, PHP_URL_HOST) === parse_url('http://' . $host, PHP_URL_HOST);
        return $safe ? new self('', 303, ['Location' => $ref]) : self::redirect($fallback);
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            header_remove('X-Powered-By');      // do not advertise the PHP version (audit A-1)
            http_response_code($this->status);
            foreach ($this->headers as $k => $v) {
                header($k . ': ' . $v);
            }
        }
        echo $this->body;
    }
}
