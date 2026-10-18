<?php

declare(strict_types=1);

namespace Waimart\Core;

/**
 * Plain-PHP templates in app/Views. Values must be escaped with e() in templates.
 */
final class View
{
    /** @var array<string,mixed> Data shared with every template (e.g. cart count). */
    private static array $shared = [];

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    public static function shared(string $key, mixed $default = null): mixed
    {
        return self::$shared[$key] ?? $default;
    }

    /** @param array<string,mixed> $data */
    public static function render(string $template, array $data = [], ?string $layout = 'layouts/main'): string
    {
        $content = self::renderFile($template, $data);
        if ($layout === null) {
            return $content;
        }
        return self::renderFile($layout, array_merge($data, ['content' => $content]));
    }

    /** @param array<string,mixed> $data */
    public static function partial(string $template, array $data = []): string
    {
        return self::renderFile($template, $data);
    }

    /** @param array<string,mixed> $__data */
    private static function renderFile(string $__template, array $__data): string
    {
        $__file = App::root() . '/app/Views/' . $__template . '.php';
        if (!is_file($__file)) {
            throw new \RuntimeException("View not found: $__template");
        }
        extract(array_merge(self::$shared, $__data), EXTR_SKIP);
        ob_start();
        try {
            include $__file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }
}
