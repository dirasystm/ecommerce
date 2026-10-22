<?php

declare(strict_types=1);

namespace Waimart\Services\Marketing;

use Waimart\Core\Crypto;
use Waimart\Core\Settings;

/**
 * Which promotional link brought this visitor: the first and the latest link clicked within the attribution window,
 * kept in a signed first-party cookie (link ids and times only, nothing personal, never in URLs).
 */
final class Attribution
{
    public const COOKIE = 'bm_att';
    /** @var array{f:?array{0:int,1:int},l:?array{0:int,1:int}}|null */
    private static ?array $state = null;

    /** Records a click on a link (first touch is kept until it falls out of the window). */
    public static function touch(int $linkId): void
    {
        $s = self::state();
        $now = time();
        if ($s['f'] === null) {
            $s['f'] = [$linkId, $now];
        }
        $s['l'] = [$linkId, $now];
        self::$state = $s;
        self::write($s);
    }

    /** @return array{first:?int,last:?int} link ids still inside the window */
    public static function current(): array
    {
        $s = self::state();
        return ['first' => $s['f'][0] ?? null, 'last' => $s['l'][0] ?? null];
    }

    /** @return array{f:?array{0:int,1:int},l:?array{0:int,1:int}} */
    private static function state(): array
    {
        if (self::$state !== null) {
            return self::$state;
        }
        $empty = ['f' => null, 'l' => null];
        $raw = (string) ($_COOKIE[self::COOKIE] ?? '');
        $parts = explode('.', $raw);
        if (count($parts) !== 2 || !hash_equals(self::sign($parts[0]), $parts[1])) {
            return self::$state = $empty;
        }
        $data = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/')), true);
        if (!is_array($data)) {
            return self::$state = $empty;
        }
        $window = max(1, Settings::int('attribution_window_days', 30)) * 86400;
        $ok = fn ($t) => is_array($t) && count($t) === 2 && is_int($t[0]) && is_int($t[1]) && $t[1] > time() - $window ? $t : null;
        return self::$state = ['f' => $ok($data['f'] ?? null), 'l' => $ok($data['l'] ?? null)];
    }

    private static function write(array $s): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }
        $payload = rtrim(strtr(base64_encode((string) json_encode($s)), '+/', '-_'), '=');
        setcookie(self::COOKIE, $payload . '.' . self::sign($payload), [
            'expires' => time() + max(1, Settings::int('attribution_window_days', 30)) * 86400,
            'path' => rtrim((string) config('app.base_path', ''), '/') . '/',
            'secure' => (bool) config('session.secure', false), 'httponly' => true, 'samesite' => 'Lax',
        ]);
    }

    private static function sign(string $payload): string
    {
        return substr(Crypto::hash('attribution|' . $payload . '|' . (string) config('app.key')), 0, 24);
    }

    /** Tests only. */
    public static function reset(): void
    {
        self::$state = null;
    }
}
