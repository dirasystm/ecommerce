<?php

declare(strict_types=1);

namespace Waimart\Core;

/**
 * Decimal-safe money arithmetic using bcmath. Amounts are strings with 2 dp.
 * Floats are never used for money.
 */
final class Money
{
    private const SCALE = 2;

    public static function normalize(string|int $amount): string
    {
        $amount = trim((string) $amount);
        if (!preg_match('/^-?\d+(\.\d+)?$/', $amount)) {
            throw new \InvalidArgumentException('Invalid money amount.');
        }
        return self::round($amount);
    }

    public static function add(string ...$amounts): string
    {
        $sum = '0';
        foreach ($amounts as $a) {
            $sum = bcadd($sum, self::normalize($a), self::SCALE);
        }
        return $sum;
    }

    public static function sub(string $a, string $b): string
    {
        return bcsub(self::normalize($a), self::normalize($b), self::SCALE);
    }

    /** Multiplies by an integer quantity. */
    public static function mul(string $amount, int $qty): string
    {
        return bcmul(self::normalize($amount), (string) $qty, self::SCALE);
    }

    /** Percentage of an amount, rounded half-up to 2 dp. */
    public static function percent(string $amount, string $percent): string
    {
        $raw = bcdiv(bcmul(self::normalize($amount), $percent, 6), '100', 6);
        return self::round($raw);
    }

    public static function cmp(string $a, string $b): int
    {
        return bccomp(self::normalize($a), self::normalize($b), self::SCALE);
    }

    public static function max(string $a, string $b): string
    {
        return self::cmp($a, $b) >= 0 ? self::normalize($a) : self::normalize($b);
    }

    public static function min(string $a, string $b): string
    {
        return self::cmp($a, $b) <= 0 ? self::normalize($a) : self::normalize($b);
    }

    public static function isZero(string $a): bool
    {
        return self::cmp($a, '0') === 0;
    }

    /** Round half away from zero to 2 dp. */
    public static function round(string $value): string
    {
        $neg = str_starts_with($value, '-');
        $offset = $neg ? '-0.005' : '0.005';
        $r = bcadd($value, $offset, self::SCALE);
        return $r === '-0.00' ? '0.00' : $r;
    }

    /** "KSh 1,234.50" — whole shillings shown without decimals. */
    public static function format(string $amount): string
    {
        $n = self::normalize($amount);
        $neg = str_starts_with($n, '-');
        [$int, $dec] = explode('.', ltrim($n, '-'));
        $int = strrev(implode(',', str_split(strrev($int), 3)));
        $out = 'KSh ' . $int . ($dec === '00' ? '' : '.' . $dec);
        return $neg ? '-' . $out : $out;
    }
}
