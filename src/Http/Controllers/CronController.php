<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Store\SyncScheduler;

/**
 * Token-protected trigger for the order and message scheduler.
 */
final class CronController extends Controller
{
    public function sync(): void
    {
        $token = trim((string) $this->app->request->get('token', ''));
        if ($token === '') {
            $token = trim($this->app->request->header('X-Cron-Token'));
        }

        $scheduler = new SyncScheduler($this->app);
        if (!$scheduler->tokenMatches($token)) {
            $this->json(403, ['ok' => false, 'error' => 'Invalid token']);
            return;
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }

        $result = $scheduler->run(null, 'cron', false);
        $code = !empty($result['locked']) ? 409 : 200;
        $this->json($code, [
            'ok'      => (bool) ($result['ok'] ?? false),
            'locked'  => (bool) ($result['locked'] ?? false),
            'error'   => !empty($result['locked']) ? 'already running' : null,
            'tenants' => $result['tenants'] ?? [],
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function json(int $code, array $payload): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
