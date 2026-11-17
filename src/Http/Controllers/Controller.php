<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\App;

abstract class Controller
{
    public function __construct(protected App $app) {}

    protected function view(string $name, array $data = [], ?string $layout = 'layouts/app'): string
    {
        return $this->app->view->render($name, $data, $layout);
    }

    protected function redirect(string $path, array $query = []): void
    {
        $this->app->redirect($path, $query);
    }

    protected function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
