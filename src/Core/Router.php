<?php

declare(strict_types=1);

namespace App\Core;

use App\Http\Controllers\Controller;
use InvalidArgumentException;
use RuntimeException;

/**
 * Front-controller router: match method + path → controller action.
 */
final class Router
{
    /** @var list<array{path:string,methods:list<string>,handler:callable|array{0:class-string,1:string},auth:bool}> */
    private array $routes = [];

    /** @param callable|array{0:class-string,1:string} $handler */
    public function get(string $path, callable|array $handler, bool $auth = false): void
    {
        $this->add(['GET'], $path, $handler, $auth);
    }

    /** @param callable|array{0:class-string,1:string} $handler */
    public function post(string $path, callable|array $handler, bool $auth = false): void
    {
        $this->add(['POST'], $path, $handler, $auth);
    }

    /** @param callable|array{0:class-string,1:string} $handler */
    public function put(string $path, callable|array $handler, bool $auth = false): void
    {
        $this->add(['PUT'], $path, $handler, $auth);
    }

    /** @param callable|array{0:class-string,1:string} $handler */
    public function patch(string $path, callable|array $handler, bool $auth = false): void
    {
        $this->add(['PATCH'], $path, $handler, $auth);
    }

    /** @param callable|array{0:class-string,1:string} $handler */
    public function delete(string $path, callable|array $handler, bool $auth = false): void
    {
        $this->add(['DELETE'], $path, $handler, $auth);
    }

    /**
     * @param list<string> $methods
     * @param callable|array{0:class-string,1:string} $handler
     */
    public function match(array $methods, string $path, callable|array $handler, bool $auth = false): void
    {
        $this->add($methods, $path, $handler, $auth);
    }

    /**
     * @param list<string> $methods
     * @param callable|array{0:class-string,1:string} $handler
     */
    private function add(array $methods, string $path, callable|array $handler, bool $auth): void
    {
        $methods = array_values(array_unique(array_map(
            static fn (string $m): string => strtoupper(trim($m)),
            $methods
        )));
        if ($methods === [] || in_array('', $methods, true)) {
            throw new InvalidArgumentException('At least one valid HTTP method is required.');
        }

        $this->routes[] = [
            'path'    => $this->normalizePath($path),
            'methods' => $methods,
            'handler' => $handler,
            'auth'    => $auth,
        ];
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        $path = trim($path, '/');

        // Strip accidental front-controller segments from the matched path
        foreach (['index.php'] as $script) {
            if (strcasecmp($path, $script) === 0) {
                return '';
            }
            $prefix = $script . '/';
            if (str_starts_with(strtolower($path), $prefix)) {
                $path = substr($path, strlen($prefix));
            }
        }

        return trim($path, '/');
    }

    public function dispatch(App $app): void
    {
        $path = $this->normalizePath($app->request->route());
        if ($path === '') {
            $path = $this->normalizePath((string) $app->config->get('app.default_home', 'admin/orders'));
        }

        $method = strtoupper($app->request->method());
        $route = $this->matchRoute($path, $method);

        if ($route === null) {
            http_response_code(404);
            echo $app->view->render('admin/error', [
                'title'      => 'Not found',
                'navSection' => 'home',
                'message'    => "No route for '{$path}'.",
            ]);
            return;
        }

        $app->security->assertCsrfIfRequired($path, $method);

        if ($route['auth']) {
            $isApi = str_starts_with($path, 'api/');
            // API tokens must not authenticate browser/admin routes.
            if (!$isApi && (int) $app->session->get('api_token_id', 0) > 0) {
                $app->auth->logout();
            }

            // Revalidate API-token sessions on every protected API request.
            if ($isApi && !$app->auth->validateApiTokenSession()) {
                // Invalid token session has been cleared.
            }

            if (!$app->auth->check()) {
                if ($isApi && $app->auth->attemptApiToken($app->request->bearerToken())) {
                    // Authenticated via API token.
                } elseif ($isApi) {
                    http_response_code(401);
                    header('Content-Type: application/json; charset=utf-8');
                    header('WWW-Authenticate: Bearer');
                    echo json_encode([
                        'error'   => 'Unauthorized',
                        'message' => 'Valid API token required (Authorization: Bearer or X-Api-Key).',
                    ], JSON_UNESCAPED_UNICODE);
                    return;
                } else {
                    $app->session->set('redirect_after_login', $path);
                    $app->redirect((string) $app->config->get('app.login_route', 'login'));
                    return;
                }
            }
        }

        $this->runHandler($app, $route['handler']);
    }

    /**
     * @return array{path:string,methods:list<string>,handler:callable|array{0:class-string,1:string},auth:bool}|null
     */
    private function matchRoute(string $path, string $method): ?array
    {
        foreach ($this->routes as $def) {
            $methodOk = in_array($method, $def['methods'], true)
                || ($method === 'HEAD' && in_array('GET', $def['methods'], true));
            if (!$methodOk) {
                continue;
            }

            // Exact path only — no prefix fallback (that mapped
            // admin/settings/appearance → admin Home when the route was missing).
            if ($path === $def['path']) {
                return $def;
            }
        }

        return null;
    }

    /** @param callable|array{0:class-string,1:string} $handler */
    private function runHandler(App $app, callable|array $handler): void
    {
        if (is_array($handler)) {
            [$class, $action] = $handler;
            if (!class_exists($class)) {
                throw new RuntimeException("Controller not found: {$class}");
            }
            if (!is_subclass_of($class, Controller::class)) {
                throw new RuntimeException("Invalid controller class: {$class}");
            }
            if (!method_exists($class, $action)) {
                throw new RuntimeException("Action not found: {$class}::{$action}");
            }
            /** @var Controller $controller */
            $controller = new $class($app);
            $controller->{$action}();
            return;
        }

        $handler($app);
    }
}
