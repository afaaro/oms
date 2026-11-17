<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\PlatformHealth;

/**
 * Public uptime probe — no auth. Returns minimal JSON only.
 */
final class HealthController extends Controller
{
    public function ping(): void
    {
        $ping = (new PlatformHealth($this->app))->ping();
        http_response_code($ping['ok'] ? 200 : 503);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($ping, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
