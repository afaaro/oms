<?php

declare(strict_types=1);

namespace App\Core;

use App\Auth\Auth;
use App\Support\Logger;
use App\Support\TenantContext;

final class App
{
    public string $basePath;
    public string $publicPath;
    public Config $config;
    public Database $db;
    public Request $request;
    public Session $session;
    public Security $security;
    public View $view;
    public Auth $auth;
    public Router $router;
    public TenantContext $tenant;
    public ?Logger $logger = null;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim(str_replace('\\', '/', $basePath), '/');
        $this->publicPath = $this->basePath;

        $this->config = new Config($this->basePath . '/config');
        date_default_timezone_set((string) $this->config->get('app.timezone', 'UTC'));

        $this->db = new Database((array) $this->config->get('database', []));
        $this->request = new Request();
        $this->security = new Security($this);

        $forceSecureCookie = (bool) $this->config->get('app.security.cookie_secure', false)
            || $this->security->isHttps();
        $lifetime = (int) $this->config->get('app.session.lifetime', 1800);

        $this->session = new Session(
            (string) $this->config->get('app.session.name', 'sf_session'),
            $forceSecureCookie,
            $lifetime
        );
        $this->auth = new Auth($this->db, $this->session);
        $this->tenant = new TenantContext($this->db, $this->session);
        $this->view = new View($this->basePath . '/resources/views', $this);
        $this->router = new Router();
    }

    public function url(string $path = '', array $query = []): string
    {
        $base = $this->baseUrl();
        $path = trim($path, '/');
        $mode = $this->urlMode();

        if ($mode === 'pathinfo') {
            $url = $base . '/index.php' . ($path !== '' ? '/' . $path : '');
            if ($query !== []) {
                $url .= '?' . http_build_query($query);
            }
            return $url;
        }

        if ($mode === 'query') {
            $params = $query;
            if ($path !== '') {
                $params = array_merge(['route' => $path], $params);
            }
            $url = $base . '/index.php';
            if ($params !== []) {
                $url .= '?' . http_build_query($params);
            }
            return $url;
        }

        $url = $path === '' ? $base . '/' : $base . '/' . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }
        return $url;
    }

    public function baseUrl(): string
    {
        $configured = trim((string) $this->config->get('app.url', ''));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        $scheme = $this->security->isHttps() ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
        if ($dir === '/' || $dir === '\\' || $dir === '.') {
            $dir = '';
        }

        return $scheme . '://' . $host . $dir;
    }

    public function assetBaseUrl(): string
    {
        return $this->baseUrl();
    }

    /** pretty | pathinfo | query */
    public function urlMode(): string
    {
        $mode = strtolower(trim((string) ($this->config->get('app.url_mode', 'pretty') ?: 'pretty')));
        return in_array($mode, ['pretty', 'pathinfo', 'query'], true) ? $mode : 'pretty';
    }

    public function redirect(string $path, array $query = []): void
    {
        header('Location: ' . $this->url($path, $query));
        exit;
    }

    public function run(): void
    {
        if ((int) ($_SERVER['REDIRECT_STATUS'] ?? 0) === 404) {
            http_response_code(200);
        }

        $this->security->enforceHttps();
        $this->security->sendHeaders();

        $routes = require $this->basePath . '/bootstrap/routes.php';
        $routes($this->router);
        \App\Support\Theme::registerRoutes($this->router);
        \App\Http\Controllers\Admin\ProductsController::registerRoutes($this->router);
        \App\Http\Controllers\Admin\AttributesController::registerRoutes($this->router);
        $this->router->dispatch($this);
    }
}
