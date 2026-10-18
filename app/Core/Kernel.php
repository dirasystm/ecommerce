<?php

declare(strict_types=1);

namespace Waimart\Core;

/**
 * HTTP kernel: session, CSRF, route middleware, controller dispatch and
 * error-to-response conversion. Security headers are added to every response.
 */
final class Kernel
{
    public function __construct(private Router $router)
    {
    }

    public function handle(Request $req): Response
    {
        try {
            Session::start();
            View::share('request', $req);
            $route = $this->router->match($req->method, $req->path);
            $req->params = $route['params'];

            if (in_array($req->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)
                && !in_array('no_csrf', $route['middleware'], true)
                && !Csrf::verify(is_string($req->post['_csrf'] ?? null) ? $req->post['_csrf'] : ($req->server['HTTP_X_CSRF_TOKEN'] ?? null))
            ) {
                throw new HttpException(419, 'Your session expired. Please refresh the page and try again.');
            }

            foreach ($route['middleware'] as $mw) {
                $early = $this->runMiddleware($mw, $req);
                if ($early !== null) {
                    return $this->secure($early);
                }
            }
            $response = $this->dispatch($route['handler'], $req);
        } catch (ValidationException $e) {
            $response = $req->isAjax()
                ? Response::json(['ok' => false, 'message' => $e->getMessage(), 'errors' => $e->errors], 422)
                : (function () use ($req, $e) {
                    Session::flash('error', $e->errors['_form'] ?? $e->getMessage());
                    return Response::back($req, $e->errors);
                })();
        } catch (HttpException $e) {
            $response = $this->errorResponse($req, $e->status, $e->getMessage());
        } catch (\Throwable $e) {
            Logger::error($e->getMessage(), [
                'exception' => get_class($e),
                'file' => $e->getFile() . ':' . $e->getLine(),
                'path' => $req->path,
            ]);
            $msg = App::isDebug() ? $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() : '';
            $response = $this->errorResponse($req, 500, $msg);
        }
        return $this->secure($response);
    }

    private function dispatch(mixed $handler, Request $req): Response
    {
        if (is_array($handler)) {
            [$class, $method] = $handler;
            $result = (new $class())->$method($req);
        } else {
            $result = $handler($req);
        }
        return $result instanceof Response ? $result : Response::html((string) $result);
    }

    /** Returns a Response to short-circuit, or null to continue. */
    private function runMiddleware(string $mw, Request $req): ?Response
    {
        [$name, $arg] = array_pad(explode(':', $mw, 2), 2, null);
        switch ($name) {
            case 'no_csrf':
                return null;
            case 'auth':
                if (!Auth::check()) {
                    Session::set('intended', $req->path . ($req->query ? '?' . http_build_query($req->query) : ''));
                    Session::flash('info', 'Please sign in to continue.');
                    return Response::redirect('/login');
                }
                return null;
            case 'guest':
                return Auth::check() ? Response::redirect('/account') : null;
            case 'staff':
                if (!Auth::check()) {
                    Session::set('intended', $req->path);
                    return Response::redirect('/admin/login');
                }
                if (!Auth::isStaff()) {
                    throw HttpException::forbidden();
                }
                return null;
            case 'can':
                Auth::authorize((string) $arg);
                return null;
            case 'seller':
                // Seller Centre: signed in + member of a seller. Suspended stores are read-only (GET);
                // closed stores are locked out. Staff permissions play no part here, in either direction.
                if (!Auth::check()) {
                    Session::set('intended', $req->path);
                    Session::flash('info', 'Please sign in to the Seller Centre.');
                    return Response::redirect('/login');
                }
                $seller = \Waimart\Services\SellerContext::current();
                if ($seller === null) {
                    Session::flash('info', 'You do not have a seller account yet. Apply to sell on Waimart below.');
                    return Response::redirect('/sell');
                }
                if ($seller['status'] === 'closed') {
                    throw HttpException::forbidden('This store has been closed.');
                }
                if ($seller['status'] !== 'active' && $req->method !== 'GET') {
                    \Waimart\Services\SellerContext::requireActive();
                }
                return null;
            case 'seller_role':
                \Waimart\Services\SellerContext::requireRole(...explode(',', (string) $arg));
                return null;
            case 'throttle':
                // throttle:max,seconds — keyed by IP + path
                [$max, $secs] = array_map('intval', explode(',', (string) $arg));
                (new RateLimiter(App::db()))->enforce('throttle|' . $req->ip() . '|' . $req->path, $max, $secs);
                return null;
            default:
                throw new \LogicException("Unknown middleware: $name");
        }
    }

    private function errorResponse(Request $req, int $status, string $message): Response
    {
        if ($req->isAjax()) {
            return Response::json(['ok' => false, 'message' => $message ?: 'Something went wrong.'], $status);
        }
        $template = in_array($status, [403, 404, 419, 429], true) ? "errors/$status" : 'errors/500';
        try {
            return Response::html(View::render($template, ['message' => $message, 'status' => $status], 'layouts/minimal'), $status);
        } catch (\Throwable) {
            return Response::html('<h1>Error ' . $status . '</h1>', $status);
        }
    }

    private function secure(Response $r): Response
    {
        $r->headers += [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(self)',
            'Content-Security-Policy' => "default-src 'self'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline'; "
                . "script-src 'self'; font-src 'self' data:; frame-ancestors 'none'; form-action 'self'; base-uri 'self'",
        ];
        // HTTPS deployments (secure cookies on) also pin HTTPS in the browser.
        if (config('session.secure', false)) {
            $r->headers += ['Strict-Transport-Security' => 'max-age=31536000; includeSubDomains'];
        }
        // Personalised pages must never be cached by browsers or proxies.
        if (!isset($r->headers['Cache-Control']) && !empty($_SESSION['user_id'])) {
            $r->headers['Cache-Control'] = 'no-store, private';
        }
        return $r;
    }
}
