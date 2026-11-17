<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;

abstract class PlatformController extends Controller
{
    protected function guardPlatform(): void
    {
        if (!$this->app->auth->check()) {
            $this->redirect('login');
        }
        if (!$this->app->auth->isPlatform()) {
            http_response_code(403);
            echo $this->view('admin/error', [
                'title' => 'Forbidden',
                'navSection' => 'home',
                'message' => 'Platform staff only. Sign in with a StoreFeeder company account.',
            ], 'layouts/guest');
            exit;
        }
    }

    /** @param array<string, mixed> $data */
    protected function platformView(string $name, array $data = []): string
    {
        $data['user'] = $this->app->auth->user();
        $data['navSection'] = $data['navSection'] ?? 'home';
        return $this->view($name, $data, 'layouts/platform');
    }
}
