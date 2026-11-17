<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Theme;

final class AppearanceController extends Controller
{
    public function index(): void
    {
        echo $this->view('admin/settings/appearance', [
            'title'        => 'Appearance',
            'navSection'   => 'settings',
            'themes'       => Theme::all($this->app),
            'currentTheme' => Theme::current($this->app),
            'flash'        => $this->app->session->flash('success'),
            'user'         => $this->app->auth->user(),
        ]);
    }

    public function save(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/settings/appearance');
        }

        $theme = Theme::set($this->app, (string) $this->app->request->post('theme', Theme::DEFAULT));
        $label = Theme::all($this->app)[$theme]['label'] ?? $theme;
        $this->app->session->flash('success', 'Theme set to ' . $label . '.');

        $back = trim((string) $this->app->request->post('return', ''), '/');
        if (
            $back !== ''
            && !str_contains($back, '://')
            && !str_contains($back, '..')
            && !str_starts_with($back, 'admin/settings/appearance')
        ) {
            $this->redirect($back);
        }

        $this->redirect('admin/settings/appearance');
    }
}
