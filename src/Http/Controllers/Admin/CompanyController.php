<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;

final class CompanyController extends Controller
{
    public function index(): void
    {
        $tenant = $this->app->tenant->tenant() ?? [
            'name' => 'Company',
            'plan' => 'standard',
            'contact_email' => '',
            'status' => 'active',
        ];
        echo $this->view('admin/settings/company', [
            'title'      => 'Company',
            'navSection' => 'settings',
            'tenant'     => $tenant,
            'user'       => $this->app->auth->user(),
        ]);
    }
}
