<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Integrations\ConnectorRegistry;
use App\Integrations\Ebay\EbayConnector;
use App\Integrations\SupportCaseSync;
use App\Store\SupportCaseRepository;

final class DisputesController extends Controller
{
    public function index(): void
    {
        $tab = (string) $this->app->request->get('tab', 'overview');
        if ($tab === 'cases') {
            $this->casesIndex();
            return;
        }

        echo $this->view('admin/orders/disputes', [
            'title'      => 'Disputes overview',
            'navSection' => 'orders',
            'tab'        => 'overview',
            'flash'      => $this->app->session->flash('success'),
            'error'      => $this->app->session->flash('error'),
            'user'       => $this->app->auth->user(),
        ]);
    }

    private function casesIndex(): void
    {
        $tenantId = $this->app->tenant->id();
        $channelId = (int) $this->app->request->get('channel_id', 0);
        $kind = trim((string) $this->app->request->get('kind', ''));
        $status = trim((string) $this->app->request->get('status', ''));
        $repo = new SupportCaseRepository($this->app->db);
        $cases = $repo->listForTenant($tenantId, $channelId, $status, $kind, 200);
        $channels = $this->app->db->query(
            "SELECT id, name FROM #__store_channels
             WHERE tenant_id=? AND type IN ('marketplace','webstore') AND active=1 ORDER BY name",
            [$tenantId]
        )->rows;

        echo $this->view('admin/orders/cases', [
            'title'      => 'Support cases',
            'navSection' => 'orders',
            'tab'        => 'cases',
            'cases'      => $cases,
            'channels'   => $channels,
            'channelId'  => $channelId,
            'kind'       => $kind,
            'status'     => $status,
            'flash'      => $this->app->session->flash('success'),
            'error'      => $this->app->session->flash('error'),
            'user'       => $this->app->auth->user(),
        ]);
    }

    public function showCase(): void
    {
        $id = (int) $this->app->request->get('id', 0);
        $case = (new SupportCaseRepository($this->app->db))->findById($this->app->tenant->id(), $id);
        if ($case === null) {
            http_response_code(404);
            echo $this->view('admin/error', [
                'title' => 'Not found',
                'navSection' => 'orders',
                'message' => 'Support case not found.',
                'user' => $this->app->auth->user(),
            ]);
            return;
        }
        $detail = [];
        $raw = $case['detail_json'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $detail = $decoded;
            }
        }
        echo $this->view('admin/orders/case_view', [
            'title'      => 'Case ' . ($case['external_id'] ?? $id),
            'navSection' => 'orders',
            'case'       => $case,
            'detail'     => $detail,
            'flash'      => $this->app->session->flash('success'),
            'error'      => $this->app->session->flash('error'),
            'user'       => $this->app->auth->user(),
        ]);
    }

    public function syncCases(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/disputes', ['tab' => 'cases']);
        }
        $days = max(1, min(180, (int) $this->app->request->post('days', 90)));
        $result = (new SupportCaseSync($this->app))->syncAll($this->app->tenant->id(), $days);
        $msg = sprintf(
            'Case sync: %d channel(s), %d created, %d updated, %d cases, %d inquiries.',
            $result['channels'],
            $result['created'],
            $result['updated'],
            $result['cases'],
            $result['inquiries']
        );
        if ($result['errors'] !== []) {
            $msg .= ' ' . implode('; ', array_slice($result['errors'], 0, 3));
            $this->app->session->flash('error', $msg);
        } else {
            $this->app->session->flash('success', $msg);
        }
        $this->redirect('admin/disputes', ['tab' => 'cases']);
    }

    public function replyCase(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/disputes', ['tab' => 'cases']);
        }
        $id = (int) $this->app->request->post('id', 0);
        $body = trim((string) $this->app->request->post('body', ''));
        $tenantId = $this->app->tenant->id();
        $case = (new SupportCaseRepository($this->app->db))->findById($tenantId, $id);
        if ($case === null) {
            $this->app->session->flash('error', 'Case not found.');
            $this->redirect('admin/disputes', ['tab' => 'cases']);
        }
        if ($body === '') {
            $this->app->session->flash('error', 'Reply cannot be empty.');
            $this->redirect('admin/disputes/case', ['id' => $id]);
        }
        if ((string) ($case['kind'] ?? '') !== 'inquiry') {
            $this->app->session->flash('error', 'Text reply is supported for eBay inquiries first; use refund for cases.');
            $this->redirect('admin/disputes/case', ['id' => $id]);
        }

        $channel = $this->app->db->query(
            'SELECT * FROM #__store_channels WHERE id=? AND tenant_id=? LIMIT 1',
            [(int) $case['channel_id'], $tenantId]
        )->row;
        if ($channel === []) {
            $this->app->session->flash('error', 'Channel not found.');
            $this->redirect('admin/disputes/case', ['id' => $id]);
        }
        $settings = $this->decodeSettings($channel['settings'] ?? null);
        $connector = (new ConnectorRegistry($this->app))->forChannel($channel, $settings);
        if (!$connector instanceof EbayConnector) {
            $this->app->session->flash('error', 'Only eBay inquiry reply is supported.');
            $this->redirect('admin/disputes/case', ['id' => $id]);
        }
        $sent = $connector->replySupportInquiry($settings, (string) $case['external_id'], $body);
        if (!empty($sent['settings'])) {
            $this->app->db->query(
                'UPDATE #__store_channels SET settings=? WHERE id=? AND tenant_id=?',
                [json_encode($sent['settings'], JSON_UNESCAPED_UNICODE), (int) $channel['id'], $tenantId]
            );
        }
        $this->app->session->flash($sent['ok'] ? 'success' : 'error', $sent['message']);
        $this->redirect('admin/disputes/case', ['id' => $id]);
    }

    public function refundCase(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/disputes', ['tab' => 'cases']);
        }
        $id = (int) $this->app->request->post('id', 0);
        $comments = trim((string) $this->app->request->post('comments', ''));
        $tenantId = $this->app->tenant->id();
        $case = (new SupportCaseRepository($this->app->db))->findById($tenantId, $id);
        if ($case === null) {
            $this->app->session->flash('error', 'Case not found.');
            $this->redirect('admin/disputes', ['tab' => 'cases']);
        }
        $channel = $this->app->db->query(
            'SELECT * FROM #__store_channels WHERE id=? AND tenant_id=? LIMIT 1',
            [(int) $case['channel_id'], $tenantId]
        )->row;
        if ($channel === []) {
            $this->app->session->flash('error', 'Channel not found.');
            $this->redirect('admin/disputes/case', ['id' => $id]);
        }
        $settings = $this->decodeSettings($channel['settings'] ?? null);
        $connector = (new ConnectorRegistry($this->app))->forChannel($channel, $settings);
        if (!$connector instanceof EbayConnector) {
            $this->app->session->flash('error', 'Channel refund not supported.');
            $this->redirect('admin/disputes/case', ['id' => $id]);
        }
        $kind = (string) ($case['kind'] ?? 'case');
        $sent = $kind === 'inquiry'
            ? $connector->issueSupportInquiryRefund($settings, (string) $case['external_id'], $comments)
            : $connector->issueSupportCaseRefund($settings, (string) $case['external_id'], $comments);
        if (!empty($sent['settings'])) {
            $this->app->db->query(
                'UPDATE #__store_channels SET settings=? WHERE id=? AND tenant_id=?',
                [json_encode($sent['settings'], JSON_UNESCAPED_UNICODE), (int) $channel['id'], $tenantId]
            );
        }
        $this->app->session->flash($sent['ok'] ? 'success' : 'error', $sent['message']);
        $this->redirect('admin/disputes/case', ['id' => $id]);
    }

    /** @return array<string,string> */
    private function decodeSettings(mixed $raw): array
    {
        if (is_array($raw)) {
            $out = [];
            foreach ($raw as $k => $v) {
                $out[(string) $k] = is_scalar($v) || $v === null ? (string) $v : json_encode($v);
            }
            return $out;
        }
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $k => $v) {
            $out[(string) $k] = is_scalar($v) || $v === null ? (string) $v : json_encode($v);
        }
        return $out;
    }
}
