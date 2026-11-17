<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Support\ErrorHandler;
use App\Support\Logger;

final class LogsController extends PlatformController
{
    public function index(): void
    {
        $this->guardPlatform();
        $logger = $this->logger();
        $files = $logger->listFiles(40);
        $requested = basename((string) $this->app->request->get('file', ''));
        if ($requested === '' && $files !== []) {
            $requested = (string) $files[0]['name'];
        }

        $content = $requested !== '' ? $logger->readTail($requested, 120000) : '';

        $deletions = [];
        try {
            $deletions = $this->app->db->query(
                'SELECT id, notification_id, ebay_username, ebay_user_id,
                        orders_redacted, messages_redacted, customers_redacted, processed_at
                 FROM #__store_ebay_account_deletions
                 ORDER BY id DESC
                 LIMIT 50'
            )->rows;
        } catch (\Throwable) {
            $deletions = [];
        }

        echo $this->platformView('platform/logs', [
            'title'       => 'System logs',
            'navSection'  => 'logs',
            'files'       => $files,
            'activeFile'  => $requested,
            'content'     => $content,
            'loggerReady' => $logger->isReady(),
            'loggerError' => $logger->bootError(),
            'logDir'      => $logger->directory(),
            'deletions'   => $deletions,
            'flash'       => $this->app->session->flash('success'),
            'error'       => $this->app->session->flash('error'),
        ]);
    }

    public function test(): void
    {
        $this->guardPlatform();
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('platform/logs');
        }
        $logger = $this->logger();
        $logger->info('Manual log test from platform UI', [
            'user' => (string) (($this->app->auth->user()['email'] ?? '') ?: 'unknown'),
        ], 'oms');
        if ($logger->isReady()) {
            $this->app->session->flash('success', 'Wrote a test line to oms-' . date('Y-m-d') . '.log');
        } else {
            $this->app->session->flash('error', $logger->bootError() ?: 'Logger is not writable.');
        }
        $this->redirect('platform/logs', ['file' => 'oms-' . date('Y-m-d') . '.log']);
    }

    public function delete(): void
    {
        $this->guardPlatform();
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('platform/logs');
        }
        $file = basename((string) $this->app->request->post('file', ''));
        if ($file === '' || !preg_match('/^[a-zA-Z0-9_\-]+\.log$/', $file)) {
            $this->app->session->flash('error', 'Invalid log file.');
            $this->redirect('platform/logs');
        }
        $path = $this->logsDirectory() . '/' . $file;
        if (!is_file($path) || @unlink($path)) {
            $this->app->session->flash('success', 'Deleted ' . $file . '.');
        } else {
            $this->app->session->flash('error', 'Could not delete ' . $file . '.');
        }
        $this->redirect('platform/logs');
    }

    public function clear(): void
    {
        $this->guardPlatform();
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('platform/logs');
        }

        $dir = $this->logsDirectory();
        $deleted = 0;
        $failed = 0;
        if (is_dir($dir)) {
            foreach (glob($dir . '/*.log') ?: [] as $path) {
                if (@unlink($path)) {
                    $deleted++;
                } else {
                    $failed++;
                }
            }
        }

        if ($failed > 0) {
            $this->app->session->flash('error', "Deleted {$deleted} file(s); {$failed} could not be removed.");
        } elseif ($deleted === 0) {
            $this->app->session->flash('success', 'No log files to delete.');
        } else {
            $this->app->session->flash('success', "Deleted {$deleted} log file(s).");
        }
        $this->redirect('platform/logs');
    }

    private function logsDirectory(): string
    {
        $logger = $this->logger();
        if (method_exists($logger, 'directory')) {
            return $logger->directory();
        }
        return rtrim(str_replace('\\', '/', $this->app->basePath), '/') . '/storage/logs';
    }

    private function logger(): Logger
    {
        if ($this->app->logger instanceof Logger) {
            return $this->app->logger;
        }
        $handler = ErrorHandler::instance();
        if ($handler !== null) {
            return $handler->logger();
        }
        return new Logger($this->app->basePath);
    }
}
