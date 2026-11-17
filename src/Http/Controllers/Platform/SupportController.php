<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

final class SupportController extends PlatformController
{
    public function index(): void
    {
        $this->guardPlatform();
        $status = (string) $this->app->request->get('status', 'open');
        echo $this->platformView('platform/support/index', [
            'title'      => 'Support',
            'navSection' => 'support',
            'status'     => $status,
        ]);
    }

    public function create(): void
    {
        $this->guardPlatform();
        $tenants = $this->app->db->query(
            'SELECT id, name FROM #__store_tenants ORDER BY name'
        )->rows;
        echo $this->platformView('platform/support/form', [
            'title'      => 'New Ticket',
            'navSection' => 'support',
            'tenants'    => $tenants,
            'error'      => $this->app->session->flash('error'),
        ]);
    }

    public function store(): void
    {
        $this->guardPlatform();
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('platform/support/new');
        }

        $tenantId = (int) $this->app->request->post('tenant_id', 0);
        $subject = trim((string) $this->app->request->post('subject', ''));
        if ($tenantId <= 0 || $subject === '') {
            $this->app->session->flash('error', 'Tenant and subject are required.');
            $this->redirect('platform/support/new');
        }

        $category = trim((string) $this->app->request->post('category', 'general'));
        $priority = (string) $this->app->request->post('priority', 'normal');
        if (!in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
            $priority = 'normal';
        }

        $ins = $this->app->db->query(
            'INSERT INTO #__support_tickets
             (tenant_id, subject, body, status, priority, impact, category, created_by_user_id, assigned_staff_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $tenantId,
                $subject,
                trim((string) $this->app->request->post('body', '')),
                'open',
                $priority,
                '',
                $category,
                $this->app->auth->id(),
                $this->app->auth->id(),
            ]
        );
        $this->redirect('platform/support/view', ['id' => (int) $ins->insert_id]);
    }

    public function show(): void
    {
        $this->guardPlatform();
        $id = (int) $this->app->request->get('id', 0);
        $ticket = $this->app->db->query(
            'SELECT t.*, n.name AS tenant_name
             FROM #__support_tickets t
             INNER JOIN #__store_tenants n ON n.id = t.tenant_id
             WHERE t.id = ? LIMIT 1',
            [$id]
        )->row;
        if ($ticket === []) {
            http_response_code(404);
            echo 'Ticket not found';
            return;
        }

        $rows = $this->app->db->query(
            'SELECT r.*, u.display_name, u.email
             FROM #__support_ticket_replies r
             LEFT JOIN #__users u ON u.id = r.user_id
             WHERE r.ticket_id = ?
             ORDER BY r.id ASC',
            [$id]
        )->rows;

        $messages = [];
        foreach ($rows as $r) {
            $messages[] = [
                'author_name' => (string) (($r['display_name'] ?? '') !== '' ? $r['display_name'] : ($r['email'] ?? 'Staff')),
                'created_at'  => (string) ($r['created_at'] ?? ''),
                'body'        => (string) ($r['body'] ?? ''),
                'is_internal' => !empty($r['is_internal']),
            ];
        }

        // Opening description as first message
        array_unshift($messages, [
            'author_name' => 'Ticket',
            'created_at'  => (string) ($ticket['created_at'] ?? ''),
            'body'        => (string) ($ticket['body'] ?? ''),
            'is_internal' => false,
        ]);

        echo $this->platformView('platform/support/view', [
            'title'      => 'Ticket #' . $id,
            'navSection' => 'support',
            'ticket'     => $ticket,
            'messages'   => $messages,
        ]);
    }

    public function update(): void
    {
        $this->guardPlatform();
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('platform/support');
        }
        $id = (int) $this->app->request->post('id', 0);
        $status = (string) $this->app->request->post('status', 'open');
        $priority = (string) $this->app->request->post('priority', 'normal');
        if (!in_array($status, ['open', 'pending', 'resolved', 'closed'], true)) {
            $status = 'open';
        }
        if (!in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
            $priority = 'normal';
        }
        $this->app->db->query(
            'UPDATE #__support_tickets SET status = ?, priority = ?, assigned_staff_id = ? WHERE id = ?',
            [$status, $priority, $this->app->auth->id(), $id]
        );
        $this->redirect('platform/support/view', ['id' => $id]);
    }

    public function reply(): void
    {
        $this->guardPlatform();
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('platform/support');
        }
        $id = (int) $this->app->request->post('id', 0);
        $body = trim((string) $this->app->request->post('body', ''));
        $isInternal = $this->app->request->post('is_internal', '') !== '' ? 1 : 0;
        if ($body !== '') {
            $this->app->db->query(
                'INSERT INTO #__support_ticket_replies (ticket_id, user_id, is_staff, is_internal, body)
                 VALUES (?, ?, 1, ?, ?)',
                [$id, $this->app->auth->id(), $isInternal, $body]
            );
            $this->app->db->query(
                'UPDATE #__support_tickets SET status = IF(status = \'closed\', status, \'pending\'), assigned_staff_id = ? WHERE id = ?',
                [$this->app->auth->id(), $id]
            );
        }
        $this->redirect('platform/support/view', ['id' => $id]);
    }
}
