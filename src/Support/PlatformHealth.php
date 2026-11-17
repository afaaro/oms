<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\App;
use Throwable;

/**
 * Collects platform / uptime health checks for staff UI and public ping.
 */
final class PlatformHealth
{
    public function __construct(private App $app) {}

    /**
     * Full report for platform staff.
     *
     * @return array{
     *   ok:bool,
     *   status:string,
     *   checked_at:string,
     *   checks:list<array{key:string,label:string,ok:bool,level:string,detail:string}>,
     *   counts:array<string,int>,
     *   php:array<string,string>,
     *   database:array<string,mixed>
     * }
     */
    public function report(): array
    {
        $checks = [];
        $database = [];
        $counts = [
            'tenants'  => 0,
            'channels' => 0,
            'orders'   => 0,
            'users'    => 0,
        ];

        $checks[] = $this->checkPhp();
        $checks[] = $this->checkExtensions();

        $dbCheck = $this->checkDatabase($database);
        $checks[] = $dbCheck;

        if ($dbCheck['ok']) {
            $migrator = new Migrator($this->app->db, $this->app->basePath);
            try {
                $info = $migrator->databaseInfo();
                $database = array_merge($database, $info);
                $pending = (int) ($info['pending'] ?? 0);
                $checks[] = [
                    'key'    => 'migrations',
                    'label'  => 'Schema migrations',
                    'ok'     => $pending === 0,
                    'level'  => $pending === 0 ? 'ok' : 'warn',
                    'detail' => $pending === 0
                        ? ((int) ($info['applied'] ?? 0) . ' applied, up to date')
                        : ($pending . ' pending / ' . (int) ($info['applied'] ?? 0) . ' applied'),
                ];
            } catch (Throwable $e) {
                $checks[] = [
                    'key'    => 'migrations',
                    'label'  => 'Schema migrations',
                    'ok'     => false,
                    'level'  => 'error',
                    'detail' => $e->getMessage(),
                ];
            }

            $counts = $this->safeCounts();
        } else {
            $checks[] = [
                'key'    => 'migrations',
                'label'  => 'Schema migrations',
                'ok'     => false,
                'level'  => 'error',
                'detail' => 'Skipped — database unavailable',
            ];
        }

        $checks[] = $this->checkStorage('storage/logs', 'Logs directory');
        $checks[] = $this->checkStorage('storage', 'Storage directory');
        $checks[] = $this->checkLogger();
        $checks[] = $this->checkDebugMode();

        $hasError = false;
        $hasWarn = false;
        foreach ($checks as $c) {
            $level = (string) ($c['level'] ?? (empty($c['ok']) ? 'error' : 'ok'));
            if ($level === 'error') {
                $hasError = true;
            } elseif ($level === 'warn') {
                $hasWarn = true;
            }
        }

        $status = $hasError ? 'unhealthy' : ($hasWarn ? 'degraded' : 'healthy');

        return [
            'ok'         => !$hasError,
            'status'     => $status,
            'checked_at' => gmdate('c'),
            'checks'     => $checks,
            'counts'     => $counts,
            'php'        => [
                'version'     => PHP_VERSION,
                'sapi'        => PHP_SAPI,
                'memory_limit'=> (string) ini_get('memory_limit'),
                'timezone'    => date_default_timezone_get(),
            ],
            'database'   => $database,
        ];
    }

    /**
     * Minimal public probe for uptime monitors.
     *
     * @return array{ok:bool, status:string, checked_at:string, database:bool}
     */
    public function ping(): array
    {
        $dbOk = false;
        try {
            $this->app->db->query('SELECT 1 AS ok');
            $dbOk = true;
        } catch (Throwable) {
            $dbOk = false;
        }

        return [
            'ok'         => $dbOk,
            'status'     => $dbOk ? 'healthy' : 'unhealthy',
            'checked_at' => gmdate('c'),
            'database'   => $dbOk,
        ];
    }

    /** @return array{key:string,label:string,ok:bool,level:string,detail:string} */
    private function checkPhp(): array
    {
        $ok = version_compare(PHP_VERSION, '8.1.0', '>=');
        return [
            'key'    => 'php',
            'label'  => 'PHP runtime',
            'ok'     => $ok,
            'level'  => $ok ? 'ok' : 'error',
            'detail' => 'PHP ' . PHP_VERSION . ($ok ? '' : ' (8.1+ required)'),
        ];
    }

    /** @return array{key:string,label:string,ok:bool,level:string,detail:string} */
    private function checkExtensions(): array
    {
        $need = ['pdo', 'pdo_mysql', 'json', 'mbstring', 'curl'];
        $missing = [];
        foreach ($need as $ext) {
            if (!extension_loaded($ext)) {
                $missing[] = $ext;
            }
        }
        $ok = $missing === [];
        return [
            'key'    => 'extensions',
            'label'  => 'PHP extensions',
            'ok'     => $ok,
            'level'  => $ok ? 'ok' : 'error',
            'detail' => $ok ? implode(', ', $need) : ('Missing: ' . implode(', ', $missing)),
        ];
    }

    /**
     * @param array<string, mixed> $database
     * @return array{key:string,label:string,ok:bool,level:string,detail:string}
     */
    private function checkDatabase(array &$database): array
    {
        try {
            $row = $this->app->db->query('SELECT DATABASE() AS db, VERSION() AS ver')->row;
            $database['name'] = (string) ($row['db'] ?? '');
            $database['version'] = (string) ($row['ver'] ?? '');
            return [
                'key'    => 'database',
                'label'  => 'Database connection',
                'ok'     => true,
                'level'  => 'ok',
                'detail' => trim(($database['name'] ?: 'connected') . ' · MySQL ' . ($database['version'] ?: '')),
            ];
        } catch (Throwable $e) {
            return [
                'key'    => 'database',
                'label'  => 'Database connection',
                'ok'     => false,
                'level'  => 'error',
                'detail' => $e->getMessage(),
            ];
        }
    }

    /** @return array{key:string,label:string,ok:bool,level:string,detail:string} */
    private function checkStorage(string $relative, string $label): array
    {
        $path = rtrim(str_replace('\\', '/', $this->app->basePath), '/') . '/' . ltrim($relative, '/');
        if (!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path)) {
            return [
                'key'    => 'storage_' . preg_replace('/[^a-z0-9]+/', '_', strtolower($relative)),
                'label'  => $label,
                'ok'     => false,
                'level'  => 'error',
                'detail' => 'Missing and not creatable: ' . $relative,
            ];
        }
        $writable = is_writable($path);
        return [
            'key'    => 'storage_' . preg_replace('/[^a-z0-9]+/', '_', strtolower($relative)),
            'label'  => $label,
            'ok'     => $writable,
            'level'  => $writable ? 'ok' : 'error',
            'detail' => $writable ? ('Writable · ' . $relative) : ('Not writable · ' . $relative),
        ];
    }

    /** @return array{key:string,label:string,ok:bool,level:string,detail:string} */
    private function checkLogger(): array
    {
        $logger = new Logger($this->app->basePath);
        $ok = $logger->isReady();
        return [
            'key'    => 'logger',
            'label'  => 'File logger',
            'ok'     => $ok,
            'level'  => $ok ? 'ok' : 'warn',
            'detail' => $ok ? ('Ready · ' . $logger->directory()) : ($logger->bootError() ?: 'Not ready'),
        ];
    }

    /** @return array{key:string,label:string,ok:bool,level:string,detail:string} */
    private function checkDebugMode(): array
    {
        $debug = (bool) $this->app->config->get('app.debug', false);
        return [
            'key'    => 'debug',
            'label'  => 'Debug mode',
            'ok'     => true,
            'level'  => $debug ? 'warn' : 'ok',
            'detail' => $debug ? 'Enabled (verbose errors — disable on live)' : 'Disabled',
        ];
    }

    /** @return array{tenants:int, channels:int, orders:int, users:int} */
    private function safeCounts(): array
    {
        $count = function (string $sql): int {
            try {
                return (int) ($this->app->db->query($sql)->row['c'] ?? 0);
            } catch (Throwable) {
                return 0;
            }
        };

        return [
            'tenants'  => $count('SELECT COUNT(*) AS c FROM #__store_tenants'),
            'channels' => $count('SELECT COUNT(*) AS c FROM #__store_channels'),
            'orders'   => $count('SELECT COUNT(*) AS c FROM #__store_orders'),
            'users'    => $count('SELECT COUNT(*) AS c FROM #__store_users'),
        ];
    }
}
