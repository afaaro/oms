<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

abstract class ApiController extends Controller
{
    protected function ok(mixed $data = null, array $meta = [], int $status = 200): void
    {
        $payload = ['ok' => true];
        if ($data !== null) {
            $payload['data'] = $data;
        }
        if ($meta !== []) {
            $payload['meta'] = $meta;
        }
        $this->respond($payload, $status);
    }

    protected function fail(string $message, int $status = 400, array $extra = []): void
    {
        $this->respond(array_merge(['ok' => false, 'error' => $message], $extra), $status);
    }

    /** @param array<string, mixed> $payload */
    protected function respond(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    protected function tenantId(): int
    {
        return $this->app->tenant->id();
    }

    protected function page(): int
    {
        return max(1, (int) $this->app->request->get('page', 1));
    }

    protected function perPage(int $default = 25, int $max = 100): int
    {
        $n = (int) $this->app->request->get('per_page', $default);
        return max(1, min($max, $n > 0 ? $n : $default));
    }

    protected function offset(): int
    {
        return ($this->page() - 1) * $this->perPage();
    }

    /** @return array{page:int,per_page:int,total:int,pages:int} */
    protected function paginationMeta(int $total): array
    {
        $per = $this->perPage();
        return [
            'page'     => $this->page(),
            'per_page' => $per,
            'total'    => $total,
            'pages'    => max(1, (int) ceil($total / $per)),
        ];
    }

    protected function requireScope(string $scope): bool
    {
        if ($this->app->auth->apiTokenAllowsScope($scope)) {
            return true;
        }

        $this->fail('Your API token does not have the required permission.', 403);

        return false;
    }
}
