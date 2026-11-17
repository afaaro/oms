<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Store\ReplyTemplateService;

final class ReplyTemplatesController extends Controller
{
    public function index(): void
    {
        $svc = new ReplyTemplateService($this->app);
        echo $this->view('admin/messages/templates', [
            'title'      => 'Reply templates',
            'navSection' => 'messages',
            'templates'  => $svc->list(),
            'flash'      => $this->app->session->flash('success'),
            'error'      => $this->app->session->flash('error'),
            'user'       => $this->app->auth->user(),
        ]);
    }

    public function store(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->app->session->flash('error', 'Security check failed.');
            $this->redirect('admin/messages/templates');
        }
        $id = (int) $this->app->request->post('id', 0);
        $body = trim((string) $this->app->request->post('body', ''));
        if ($body === '') {
            $this->app->session->flash('error', 'Template body is required.');
            $this->redirect('admin/messages/templates');
        }
        (new ReplyTemplateService($this->app))->save([
            'name'         => (string) $this->app->request->post('name', ''),
            'body'         => $body,
            'channel_type' => (string) $this->app->request->post('channel_type', ''),
        ], $id);
        $this->app->session->flash('success', $id > 0 ? 'Template updated.' : 'Template created.');
        $this->redirect('admin/messages/templates');
    }

    public function delete(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->app->session->flash('error', 'Security check failed.');
            $this->redirect('admin/messages/templates');
        }
        $id = (int) $this->app->request->post('id', 0);
        if ($id > 0) {
            (new ReplyTemplateService($this->app))->delete($id);
            $this->app->session->flash('success', 'Template deleted.');
        }
        $this->redirect('admin/messages/templates');
    }
}
