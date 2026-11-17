<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Support\Migrator;

final class DatabaseController extends PlatformController
{
    public function index(): void
    {
        $this->guardPlatform();
        $migrator = $this->migrator();

        echo $this->platformView('platform/database', [
            'title'      => 'Database',
            'navSection' => 'database',
            'info'       => $migrator->databaseInfo(),
            'migrations' => $migrator->status(),
            'flash'      => $this->app->session->flash('success'),
            'error'      => $this->app->session->flash('error'),
            'lastResult' => $this->app->session->flash('migrate_result'),
        ]);
    }

    public function migrate(): void
    {
        $this->guardPlatform();
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->app->session->flash('error', 'Invalid session token.');
            $this->redirect('platform/database');
        }

        $mode = (string) $this->app->request->post('mode', 'pending');
        $file = trim((string) $this->app->request->post('file', ''));
        $migrator = $this->migrator();

        if ($mode === 'sync') {
            $result = $migrator->syncLog();
        } elseif ($mode === 'file' && $file !== '') {
            $result = $migrator->run(false, $file);
        } elseif ($mode === 'all') {
            $result = $migrator->run(false);
        } else {
            $result = $migrator->run(true);
        }

        $this->app->session->flash($result['ok'] ? 'success' : 'error', $result['message']);
        $this->app->session->flash('migrate_result', $result);
        $this->redirect('platform/database');
    }

    private function migrator(): Migrator
    {
        return new Migrator($this->app->db, $this->app->basePath);
    }
}
