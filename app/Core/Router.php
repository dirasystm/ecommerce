<?php

declare(strict_types=1);

namespace Waimart\Core;

/**
 * Small regex router. Patterns use {name} placeholders, optionally typed:
 * {id:\d+}. Handlers are [ControllerClass, 'method'] or closures.
 */
final class Router
{
    /** {name} or {name:regex}; the regex may contain {n} / {n,m} quantifiers. */
    private const PLACEHOLDER = '#\{(\w+)(?::((?:[^{}]|\{\d+(?:,\d*)?\})+))?\}#';

    /** @var list<array{method:string,regex:string,keys:list<string>,handler:mixed,middleware:list<string>,name:?string,pattern:string}> */
    private array $routes = [];
    /** @var array<string,string> */
    private array $named = [];
    private string $prefix = '';
    /** @var list<string> */
    private array $groupMiddleware = [];

    public function get(string $pattern, mixed $handler, array $middleware = [], ?string $name = null): void
    {
        $this->add('GET', $pattern, $handler, $middleware, $name);
    }

    public function post(string $pattern, mixed $handler, array $middleware = [], ?string $name = null): void
    {
        $this->add('POST', $pattern, $handler, $middleware, $name);
    }

    /** @param array{prefix?:string,middleware?:list<string>} $opts */
    public function group(array $opts, callable $routes): void
    {
        $prevPrefix = $this->prefix;
        $prevMw = $this->groupMiddleware;
        $this->prefix .= rtrim($opts['prefix'] ?? '', '/');
        $this->groupMiddleware = array_merge($this->groupMiddleware, $opts['middleware'] ?? []);
        $routes($this);
        $this->prefix = $prevPrefix;
        $this->groupMiddleware = $prevMw;
    }

    private function add(string $method, string $pattern, mixed $handler, array $middleware, ?string $name): void
    {
        $pattern = '/' . trim($this->prefix . '/' . trim($pattern, '/'), '/');
        $keys = [];
        $regex = preg_replace_callback(self::PLACEHOLDER, function ($m) use (&$keys) {
            $keys[] = $m[1];
            return '(' . ($m[2] ?? '[^/]+') . ')';
        }, $pattern);
        $this->routes[] = [
            'method' => $method,
            'regex' => '#^' . $regex . '$#u',
            'keys' => $keys,
            'handler' => $handler,
            'middleware' => array_merge($this->groupMiddleware, $middleware),
            'name' => $name,
            'pattern' => $pattern,
        ];
        if ($name !== null) {
            $this->named[$name] = $pattern;
        }
    }

    /**
     * @return array{handler:mixed,middleware:list<string>,params:array<string,string>}
     * @throws HttpException 404 / 405
     */
    public function match(string $method, string $path): array
    {
        $allowed = false;
        foreach ($this->routes as $r) {
            if (!preg_match($r['regex'], $path, $m)) {
                continue;
            }
            if ($r['method'] !== $method && !($method === 'HEAD' && $r['method'] === 'GET')) {
                $allowed = true;
                continue;
            }
            array_shift($m);
            $params = $r['keys'] ? array_combine($r['keys'], $m) : [];
            return ['handler' => $r['handler'], 'middleware' => $r['middleware'], 'params' => $params];
        }
        if ($allowed) {
            throw new HttpException(405, 'Method not allowed.');
        }
        throw HttpException::notFound();
    }

    /**
     * Route table (for security tests that sweep every route).
     * @return list<array{method:string,pattern:string,middleware:list<string>}>
     */
    public function all(): array
    {
        return array_map(fn ($r) => ['method' => $r['method'], 'pattern' => $r['pattern'], 'middleware' => $r['middleware']], $this->routes);
    }

    /** Builds a path for a named route: route('product', ['slug' => 'x']) */
    public function path(string $name, array $params = []): string
    {
        if (!isset($this->named[$name])) {
            throw new \InvalidArgumentException("Unknown route: $name");
        }
        return preg_replace_callback(self::PLACEHOLDER, fn ($m) => rawurlencode((string) ($params[$m[1]] ?? '')), $this->named[$name]);
    }
}
