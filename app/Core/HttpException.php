<?php

declare(strict_types=1);

namespace Waimart\Core;

class HttpException extends \RuntimeException
{
    /** @param int|null $retryAfter seconds to wait (sent as Retry-After on 429 responses) */
    public function __construct(public readonly int $status, string $message = '', public readonly ?int $retryAfter = null)
    {
        parent::__construct($message, $status);
    }

    public static function notFound(string $message = 'Page not found.'): self
    {
        return new self(404, $message);
    }

    public static function forbidden(string $message = 'You do not have permission to do that.'): self
    {
        return new self(403, $message);
    }

    public static function tooMany(string $message = 'Too many attempts. Please wait and try again.', ?int $retryAfter = null): self
    {
        return new self(429, $message, $retryAfter);
    }
}
