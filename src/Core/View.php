<?php

declare(strict_types=1);

namespace App\Core;

final class View
{
    public function __construct(
        private string $viewsPath,
        private App $app,
    ) {}

    /** @param array<string, mixed> $data */
    public function render(string $view, array $data = [], ?string $layout = 'layouts/app'): string
    {
        $content = $this->renderPartial($view, $data);
        if ($layout === null) {
            return $content;
        }
        return $this->renderPartial($layout, array_merge($data, ['content' => $content]));
    }

    /** @param array<string, mixed> $data */
    public function renderPartial(string $view, array $data = []): string
    {
        $file = $this->viewsPath . '/' . str_replace('.', '/', $view) . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("View not found: {$view}");
        }

        extract($data, EXTR_SKIP);
        $app = $this->app;
        $e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $url = fn(string $path = '', array $query = []): string => $this->app->url($path, $query);

        ob_start();
        include $file;
        return (string) ob_get_clean();
    }
}
