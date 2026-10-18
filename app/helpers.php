<?php

declare(strict_types=1);

use Waimart\Core\App;
use Waimart\Core\Csrf;
use Waimart\Core\Money;
use Waimart\Core\Session;

/** HTML-escape a value for output. */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function config(string $key, mixed $default = null): mixed
{
    return App::config()->get($key, $default);
}

/** Absolute-path URL inside the app, e.g. url('/cart') => /ecommerce/cart */
function url(string $path = '/', array $query = []): string
{
    $base = rtrim((string) config('app.base_path', ''), '/');
    $path = '/' . ltrim($path, '/');
    $qs = $query ? '?' . http_build_query($query) : '';
    return $base . ($path === '/' ? '/' : $path) . $qs;
}

function asset(string $path): string
{
    $file = App::root() . '/public/assets/' . ltrim($path, '/');
    $v = is_file($file) ? '?v=' . filemtime($file) : '';
    return url('/assets/' . ltrim($path, '/')) . $v;
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(Csrf::token()) . '">';
}

function money(string|int|float|null $amount): string
{
    return Money::format((string) ($amount ?? '0'));
}

/** Previously submitted form value (after a validation redirect). */
function old(string $key, mixed $default = ''): mixed
{
    $old = Session::getFlash('_old_input', []);
    return $old[$key] ?? $default;
}

function flash(string $type): ?string
{
    return Session::getFlash($type);
}

/** Public URL for a stored media path (e.g. uploads/products/x.webp), with a placeholder fallback. */
function media(?string $path): string
{
    if ($path === null || $path === '' || str_contains($path, '..')) {
        return asset('img/placeholder-product.svg');
    }
    return url('/' . ltrim($path, '/'));
}

/** Accessible star rating markup, e.g. 4.3 => ★★★★☆ with a screen-reader label. */
function stars(float $rating): string
{
    $full = (int) round($rating);
    return '<span class="wm-stars" aria-hidden="true">' . str_repeat('★', $full) . str_repeat('☆', 5 - $full) . '</span>'
        . '<span class="visually-hidden">Rated ' . e(number_format($rating, 1)) . ' out of 5</span>';
}

/**
 * URL for $path keeping the current query string, with overrides (null removes a key).
 * Used for filters, sorting and pagination so links stay shareable.
 */
function query_url(string $path, array $overrides = []): string
{
    $req = \Waimart\Core\View::shared('request');
    $query = $req instanceof \Waimart\Core\Request ? $req->query : [];
    foreach ($overrides as $k => $v) {
        if ($v === null) {
            unset($query[$k]);
        } else {
            $query[$k] = $v;
        }
    }
    return url($path, $query);
}

/** Is the given path the current page? Used for nav highlighting. */
function is_current(string $path): bool
{
    $req = \Waimart\Core\View::shared('request');
    return $req instanceof \Waimart\Core\Request && $req->path === $path;
}

/** Form value: the re-submitted value after a failed validation, otherwise $default. */
function fv(string $key, mixed $default = ''): mixed
{
    $old = Session::getFlash('_old_input');
    if (is_array($old)) {
        return $old[$key] ?? '';
    }
    return $default ?? '';
}

/** Checkbox state that respects re-submitted forms (an unticked box is absent from POST). */
function fchecked(string $key, bool $default): string
{
    $old = Session::getFlash('_old_input');
    $on = is_array($old) ? !empty($old[$key]) : $default;
    return $on ? 'checked' : '';
}

/** Bootstrap validation class + message for a field. */
function invalid(string $field): string
{
    return error($field) !== null ? ' is-invalid' : '';
}

function field_error(string $field): string
{
    $msg = error($field);
    return $msg === null ? '' : '<div class="invalid-feedback d-block">' . e($msg) . '</div>';
}

/** Validation error for a field from the previous request, if any. */
function error(string $field): ?string
{
    return Session::errors()[$field] ?? null;
}
