<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\ValidationException;

/** A reservation is not in the state an operation requires (e.g. consuming an expired one). */
final class ReservationStateException extends ValidationException
{
    public function __construct(string $message)
    {
        parent::__construct(['_form' => $message], $message);
    }
}
