<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Support\PlatformHealth;

final class HealthController extends PlatformController
{
    public function index(): void
    {
        $this->guardPlatform();
        $report = (new PlatformHealth($this->app))->report();

        echo $this->platformView('platform/health', [
            'title'      => 'Health',
            'navSection' => 'health',
            'report'     => $report,
        ]);
    }

    /** Staff JSON dump of the full report. */
    public function json(): void
    {
        $this->guardPlatform();
        $report = (new PlatformHealth($this->app))->report();
        http_response_code($report['ok'] ? 200 : 503);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
}
