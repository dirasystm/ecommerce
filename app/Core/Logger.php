<?php

declare(strict_types=1);

namespace Waimart\Core;

/**
 * Daily file logger. Redacts secrets (tokens, passwords, codes) before writing.
 */
final class Logger
{
    private const SENSITIVE_KEYS = '/(password|token|secret|_csrf|_code$|^code$|^pin$|^t$)/i';

    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('WARNING', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    public static function write(string $level, string $message, array $context = []): void
    {
        $dir = App::root() . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $line = sprintf(
            "[%s] %s: %s %s\n",
            date('Y-m-d H:i:s'),
            $level,
            self::redactString($message),
            $context ? json_encode(self::redact($context), JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) : ''
        );
        @file_put_contents($dir . '/app-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    /** @param array<mixed> $data */
    public static function redact(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_string($k) && preg_match(self::SENSITIVE_KEYS, $k)) {
                $data[$k] = '[redacted]';
            } elseif (is_array($v)) {
                $data[$k] = self::redact($v);
            } elseif (is_string($v)) {
                $data[$k] = self::redactString($v);
            }
        }
        return $data;
    }

    /** Strips token-like query parameters from URLs embedded in strings. */
    public static function redactString(string $s): string
    {
        return (string) preg_replace('/([?&](?:t|token|code)=)[^&\s"]+/i', '$1[redacted]', $s);
    }
}
