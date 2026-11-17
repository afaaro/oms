<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\App;
use App\Core\Router;
use App\Http\Controllers\Admin\AppearanceController;

/**
 * Discovers UI themes from /themes/{name}/ with per-theme assets.
 *
 * themes/{name}/
 *   theme.json
 *   assets/css/theme.css   (required)
 *   assets/js/*.js         (optional)
 *   assets/img/*           (optional)
 */
final class Theme
{
    public const COOKIE = 'sf_theme';
    public const SESSION = 'ui_theme';
    public const DEFAULT = 'classic';

    /** @var array<string, array{key:string,label:string,description:string,swatch:list<string>,order:int,dir:string}>|null */
    private static ?array $cache = null;

    public static function rootPath(App $app): string
    {
        return $app->basePath . '/themes';
    }

    /** Always register appearance routes (safe if routes.php is outdated on the host). */
    public static function registerRoutes(Router $router): void
    {
        $router->get('admin/settings/appearance', [AppearanceController::class, 'index'], true);
        $router->post('admin/settings/appearance', [AppearanceController::class, 'save'], true);
    }

    /**
     * @return array<string, array{key:string,label:string,description:string,swatch:list<string>,order:int,dir:string}>
     */
    public static function all(?App $app = null): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $base = $app !== null
            ? self::rootPath($app)
            : dirname(__DIR__, 2) . '/themes';

        $out = [];
        if (!is_dir($base)) {
            return self::$cache = [];
        }

        foreach (scandir($base) ?: [] as $dir) {
            if ($dir === '.' || $dir === '..' || !is_dir($base . '/' . $dir)) {
                continue;
            }
            if (!preg_match('/^[a-z0-9_-]+$/', $dir)) {
                continue;
            }

            $jsonPath = $base . '/' . $dir . '/theme.json';
            $cssPath = self::resolveCssFile($base . '/' . $dir);
            if (!is_file($jsonPath) || $cssPath === null) {
                continue;
            }

            $meta = json_decode((string) file_get_contents($jsonPath), true);
            if (!is_array($meta)) {
                continue;
            }

            $swatch = $meta['swatch'] ?? ['#00539d', '#006fd1', '#f8991d'];
            if (!is_array($swatch)) {
                $swatch = ['#00539d', '#006fd1', '#f8991d'];
            }
            $swatch = array_values(array_map('strval', array_slice($swatch, 0, 3)));
            while (count($swatch) < 3) {
                $swatch[] = '#cccccc';
            }

            $out[$dir] = [
                'key'         => $dir,
                'label'       => (string) ($meta['label'] ?? ucfirst($dir)),
                'description' => (string) ($meta['description'] ?? ''),
                'swatch'      => $swatch,
                'order'       => (int) ($meta['order'] ?? 100),
                'dir'         => 'themes/' . $dir,
            ];
        }

        uasort($out, static function (array $a, array $b): int {
            return ($a['order'] <=> $b['order']) ?: strcmp($a['label'], $b['label']);
        });

        return self::$cache = $out;
    }

    public static function isValid(string $key, ?App $app = null): bool
    {
        return isset(self::all($app)[$key]);
    }

    public static function current(App $app): string
    {
        $fromSession = strtolower(trim((string) $app->session->get(self::SESSION, '')));
        if (self::isValid($fromSession, $app)) {
            return $fromSession;
        }

        $fromCookie = strtolower(trim((string) ($_COOKIE[self::COOKIE] ?? '')));
        if (self::isValid($fromCookie, $app)) {
            $app->session->set(self::SESSION, $fromCookie);
            return $fromCookie;
        }

        $all = self::all($app);
        return isset($all[self::DEFAULT]) ? self::DEFAULT : (array_key_first($all) ?: self::DEFAULT);
    }

    /** Public URL base for a theme folder, e.g. https://host/themes/ocean */
    public static function baseUrl(App $app, ?string $key = null): string
    {
        $key = self::normalizeKey($app, $key);
        return rtrim($app->assetBaseUrl(), '/') . '/themes/' . $key;
    }

    /** Public URL for a file inside the theme, e.g. assets/css/theme.css */
    public static function assetUrl(App $app, string $relative, ?string $key = null): string
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        return self::baseUrl($app, $key) . '/' . $relative;
    }

    public static function cssUrl(App $app, ?string $key = null): string
    {
        $key = self::normalizeKey($app, $key);
        $dir = self::rootPath($app) . '/' . $key;
        if (is_file($dir . '/assets/css/theme.css')) {
            return self::assetUrl($app, 'assets/css/theme.css', $key);
        }
        // Legacy fallback
        return self::assetUrl($app, 'theme.css', $key);
    }

    /**
     * Extra CSS files under themes/{name}/assets/css/ (theme.css first, then others A–Z).
     *
     * @return list<string>
     */
    public static function styleUrls(App $app, ?string $key = null): array
    {
        $key = self::normalizeKey($app, $key);
        $cssDir = self::rootPath($app) . '/' . $key . '/assets/css';
        $urls = [];

        if (is_file($cssDir . '/theme.css')) {
            $urls[] = self::assetUrl($app, 'assets/css/theme.css', $key);
        } elseif (is_file(self::rootPath($app) . '/' . $key . '/theme.css')) {
            $urls[] = self::assetUrl($app, 'theme.css', $key);
        }

        if (is_dir($cssDir)) {
            foreach (scandir($cssDir) ?: [] as $file) {
                if ($file === '.' || $file === '..' || $file === 'theme.css') {
                    continue;
                }
                if (str_ends_with(strtolower($file), '.css')) {
                    $urls[] = self::assetUrl($app, 'assets/css/' . $file, $key);
                }
            }
        }

        return $urls;
    }

    /**
     * Optional JS under themes/{name}/assets/js/*.js (A–Z, skips .gitkeep).
     *
     * @return list<string>
     */
    public static function scriptUrls(App $app, ?string $key = null): array
    {
        $key = self::normalizeKey($app, $key);
        $jsDir = self::rootPath($app) . '/' . $key . '/assets/js';
        if (!is_dir($jsDir)) {
            return [];
        }

        $urls = [];
        foreach (scandir($jsDir) ?: [] as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            if (str_ends_with(strtolower($file), '.js')) {
                $urls[] = self::assetUrl($app, 'assets/js/' . $file, $key);
            }
        }
        return $urls;
    }

    public static function set(App $app, string $key): string
    {
        $key = strtolower(trim($key));
        if (!self::isValid($key, $app)) {
            $key = self::DEFAULT;
            if (!self::isValid($key, $app)) {
                $all = self::all($app);
                $key = (string) (array_key_first($all) ?: self::DEFAULT);
            }
        }

        $app->session->set(self::SESSION, $key);

        $secure = $app->security->isHttps();
        setcookie(self::COOKIE, $key, [
            'expires'  => time() + 60 * 60 * 24 * 400,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::COOKIE] = $key;

        return $key;
    }

    private static function normalizeKey(App $app, ?string $key): string
    {
        $key = strtolower(trim((string) ($key ?? self::current($app))));
        if (!self::isValid($key, $app)) {
            $key = self::current($app);
        }
        return $key;
    }

    private static function resolveCssFile(string $themeDir): ?string
    {
        $candidates = [
            $themeDir . '/assets/css/theme.css',
            $themeDir . '/theme.css',
        ];
        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }
        return null;
    }
}
