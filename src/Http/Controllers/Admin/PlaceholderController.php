<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;

/** Soft landing for SF menu items not built yet. */
final class PlaceholderController extends Controller
{
    public function show(): void
    {
        $route = $this->app->request->route();
        $title = ucwords(str_replace(['admin/', '-', '/'], ['', ' ', ' · '], $route));
        $section = 'home';
        if (str_starts_with($route, 'admin/orders') || str_contains($route, 'disputes')) {
            $section = 'orders';
        } elseif (str_starts_with($route, 'admin/product') || str_contains($route, 'purchase') || str_contains($route, 'supplier')) {
            $section = 'products';
        } elseif (str_starts_with($route, 'admin/warehouse')) {
            $section = 'warehouse';
        } elseif (str_starts_with($route, 'admin/settings')) {
            $section = 'settings';
        }

        echo $this->view('admin/placeholder', [
            'title'      => $title,
            'navSection' => $section,
            'route'      => $route,
            'user'       => $this->app->auth->user(),
        ]);
    }
}
