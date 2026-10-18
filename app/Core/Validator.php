<?php

declare(strict_types=1);

namespace Waimart\Core;

/**
 * Rule-string validator: ['email' => 'required|email|max:190'].
 * Returns field => first error message.
 */
final class Validator
{
    /**
     * @param array<string,mixed> $data
     * @param array<string,string> $rules
     * @param array<string,string> $labels Friendly field names
     * @return array<string,string>
     */
    public static function check(array $data, array $rules, array $labels = []): array
    {
        $errors = [];
        foreach ($rules as $field => $ruleString) {
            $value = $data[$field] ?? null;
            if (is_string($value)) {
                $value = trim($value);
            }
            $label = $labels[$field] ?? ucfirst(str_replace('_', ' ', $field));
            $ruleList = explode('|', $ruleString);
            $empty = $value === null || $value === '' || $value === [];

            if (in_array('nullable', $ruleList, true) && $empty) {
                continue;
            }
            foreach ($ruleList as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                $msg = self::apply($name, $arg, $value, $empty, $label, $data);
                if ($msg !== null) {
                    $errors[$field] = $msg;
                    break;
                }
            }
        }
        return $errors;
    }

    /** Throws ValidationException when invalid. */
    public static function validate(array $data, array $rules, array $labels = []): void
    {
        $errors = self::check($data, $rules, $labels);
        if ($errors) {
            throw new ValidationException($errors);
        }
    }

    private static function apply(string $rule, ?string $arg, mixed $v, bool $empty, string $label, array $data): ?string
    {
        if ($rule === 'required') {
            return $empty ? "$label is required." : null;
        }
        if ($empty || $rule === 'nullable') {
            return null;
        }
        $s = is_scalar($v) ? (string) $v : '';
        return match ($rule) {
            'email'   => filter_var($s, FILTER_VALIDATE_EMAIL) && mb_strlen($s) <= 190 ? null : "Enter a valid email address.",
            'phone'   => self::normalizePhone($s) !== null ? null : "Enter a valid Kenyan phone number, e.g. 0712 345 678.",
            'min'     => mb_strlen($s) >= (int) $arg ? null : "$label must be at least $arg characters.",
            'max'     => mb_strlen($s) <= (int) $arg ? null : "$label must not exceed $arg characters.",
            'int'     => preg_match('/^-?\d+$/', $s) ? null : "$label must be a whole number.",
            'gte'     => is_numeric($s) && bccomp($s, (string) $arg, 2) >= 0 ? null : "$label must be at least $arg.",
            'lte'     => is_numeric($s) && bccomp($s, (string) $arg, 2) <= 0 ? null : "$label must not exceed $arg.",
            'decimal' => preg_match('/^\d{1,10}(\.\d{1,2})?$/', $s) ? null : "$label must be an amount like 1500 or 1500.50.",
            'in'      => in_array($s, explode(',', (string) $arg), true) ? null : "Select a valid $label.",
            'same'    => $s === (string) ($data[$arg] ?? '') ? null : "$label does not match.",
            'password'=> mb_strlen($s) >= 8 && preg_match('/[A-Za-z]/', $s) && preg_match('/\d/', $s)
                            ? null : "Password must be at least 8 characters and include a letter and a number.",
            'slug'    => preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $s) ? null : "$label may contain only lowercase letters, numbers and hyphens.",
            'url'     => filter_var($s, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $s) ? null : "$label must be a valid http(s) URL.",
            'date'    => strtotime($s) !== false ? null : "$label must be a valid date.",
            'mpesa_code' => preg_match('/^[A-Z0-9]{10}$/', strtoupper($s)) ? null : "Enter the 10-character M-Pesa transaction code, e.g. SGH7K2L9XQ.",
            default   => throw new \InvalidArgumentException("Unknown validation rule: $rule"),
        };
    }

    /**
     * Normalises Kenyan mobile numbers to 2547XXXXXXXX / 2541XXXXXXXX.
     * Accepts 07.., 01.., +2547.., 2547.., 7.., 1.. with spaces or dashes.
     */
    public static function normalizePhone(string $raw): ?string
    {
        $d = preg_replace('/[\s\-().]/', '', $raw) ?? '';
        $d = ltrim($d, '+');
        if (preg_match('/^0([17]\d{8})$/', $d, $m)) {
            return '254' . $m[1];
        }
        if (preg_match('/^254([17]\d{8})$/', $d, $m)) {
            return '254' . $m[1];
        }
        if (preg_match('/^([17]\d{8})$/', $d, $m)) {
            return '254' . $m[1];
        }
        return null;
    }

    /** 254712345678 => 0712 345 678 */
    public static function displayPhone(string $normalized): string
    {
        if (!preg_match('/^254(\d{3})(\d{3})(\d{3})$/', $normalized, $m)) {
            return $normalized;
        }
        return "0{$m[1]} {$m[2]} {$m[3]}";
    }
}
