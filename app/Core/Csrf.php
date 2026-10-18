<?php

declare(strict_types=1);

namespace Waimart\Core;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = Crypto::token(32);
        }
        return $_SESSION['_csrf'];
    }

    public static function verify(?string $token): bool
    {
        $known = $_SESSION['_csrf'] ?? '';
        return $known !== '' && is_string($token) && hash_equals($known, $token);
    }
}
