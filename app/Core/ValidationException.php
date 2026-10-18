<?php

declare(strict_types=1);

namespace Waimart\Core;

/** Thrown by services when user input is invalid; the kernel redirects back with errors. */
class ValidationException extends \RuntimeException
{
    /** @param array<string,string> $errors field => message */
    public function __construct(public readonly array $errors, string $message = 'Please correct the highlighted fields.')
    {
        parent::__construct($message);
    }

    public static function withMessage(string $message, string $field = '_form'): self
    {
        return new self([$field => $message], $message);
    }
}
