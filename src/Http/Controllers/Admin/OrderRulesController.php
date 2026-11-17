<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Store\OrderRuleService;

final class OrderRulesController extends Controller
{
    public function index(): void
    {
        $svc = new OrderRuleService($this->app);
        echo $this->view('admin/orders/rules', [
            'title'      => 'Order rules',
            'navSection' => 'orders',
            'rules'      => $svc->list(),
            'types'      => OrderRuleService::TYPES,
            'actions'    => OrderRuleService::ACTIONS,
            'flash'      => $this->app->session->flash('success'),
            'error'      => $this->app->session->flash('error'),
            'user'       => $this->app->auth->user(),
        ]);
    }

    public function store(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->app->session->flash('error', 'Security check failed.');
            $this->redirect('admin/orders/rules');
        }
        $id = (int) $this->app->request->post('id', 0);
        (new OrderRuleService($this->app))->save([
            'name'      => (string) $this->app->request->post('name', ''),
            'rule_type' => (string) $this->app->request->post('rule_type', 'unpaid'),
            'action'    => (string) $this->app->request->post('action', 'hold'),
            'threshold' => $this->app->request->post('threshold', 0),
            'active'    => $this->app->request->post('active', '') !== '' ? 1 : 0,
        ], $id);
        $this->app->session->flash('success', $id > 0 ? 'Rule updated.' : 'Rule created.');
        $this->redirect('admin/orders/rules');
    }

    public function delete(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->app->session->flash('error', 'Security check failed.');
            $this->redirect('admin/orders/rules');
        }
        $id = (int) $this->app->request->post('id', 0);
        if ($id > 0) {
            (new OrderRuleService($this->app))->delete($id);
            $this->app->session->flash('success', 'Rule deleted.');
        }
        $this->redirect('admin/orders/rules');
    }
}
