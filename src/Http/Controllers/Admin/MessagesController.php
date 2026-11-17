<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Integrations\ConnectorRegistry;
use App\Integrations\ConversationSync;
use App\Integrations\Ebay\EbayConnector;
use App\Integrations\MessageSync;
use App\Store\ConversationRepository;
use App\Store\ReplyTemplateService;

final class MessagesController extends Controller
{
    public function index(): void
    {
        $folder = (string) $this->app->request->get('folder', 'message');
        if (!in_array($folder, ['mail', 'message'], true)) {
            $folder = 'message';
        }

        if ($folder === 'mail') {
            $this->mailIndex();
            return;
        }

        $this->conversationsIndex();
    }

    private function conversationsIndex(): void
    {
        $tenantId = $this->app->tenant->id();
        $repo = new ConversationRepository($this->app->db);
        $channelId = (int) $this->app->request->get('channel_id', 0);
        $rows = $repo->listForTenant($tenantId, $channelId > 0 ? $channelId : null, 150);
        $unreadConv = $repo->countUnread($tenantId);
        $unreadMail = $this->mailUnreadCount($tenantId);

        $channels = $this->app->db->query(
            "SELECT id, name FROM #__store_channels
             WHERE tenant_id = ? AND type IN ('marketplace','webstore') AND active = 1
             ORDER BY name",
            [$tenantId]
        )->rows;

        echo $this->view('admin/messages/conversations', [
            'title'      => 'Messages',
            'navSection' => 'messages',
            'rows'       => $rows,
            'channels'   => $channels,
            'channelId'  => $channelId,
            'unread'     => ['mail' => $unreadMail, 'message' => $unreadConv],
            'folder'     => 'message',
            'flash'      => $this->app->session->flash('success'),
            'error'      => $this->app->session->flash('error'),
            'user'       => $this->app->auth->user(),
        ]);
    }

    private function mailIndex(): void
    {
        $status = trim((string) $this->app->request->get('status', ''));
        $provider = trim((string) $this->app->request->get('provider', ''));
        $params = [$this->app->tenant->id()];
        $where = "m.tenant_id = ? AND m.folder = 'mail'";
        if ($status !== '') {
            $where .= ' AND m.status = ?';
            $params[] = $status;
        }
        if ($provider !== '') {
            $where .= ' AND m.provider = ?';
            $params[] = $provider;
        }

        $rows = $this->app->db->query(
            "SELECT m.*, c.name AS channel_name
             FROM #__store_buyer_messages m
             LEFT JOIN #__store_channels c ON c.id = m.channel_id
             WHERE {$where}
             ORDER BY COALESCE(m.received_at, m.created_at) DESC
             LIMIT 200",
            $params
        )->rows;

        $tenantId = $this->app->tenant->id();
        $unreadMail = $this->mailUnreadCount($tenantId);
        $unreadConv = (new ConversationRepository($this->app->db))->countUnread($tenantId);

        echo $this->view('admin/messages/index', [
            'title'      => 'Mail inbox',
            'navSection' => 'messages',
            'messages'   => $rows,
            'unread'     => ['mail' => $unreadMail, 'message' => $unreadConv],
            'folder'     => 'mail',
            'status'     => $status,
            'provider'   => $provider,
            'flash'      => $this->app->session->flash('success'),
            'error'      => $this->app->session->flash('error'),
            'user'       => $this->app->auth->user(),
        ]);
    }

    public function conversation(): void
    {
        $tenantId = $this->app->tenant->id();
        $id = (int) $this->app->request->get('id', 0);
        $repo = new ConversationRepository($this->app->db);
        $conv = $repo->findForTenant($tenantId, $id);
        if ($conv === null) {
            http_response_code(404);
            echo $this->view('admin/error', [
                'title' => 'Not found',
                'navSection' => 'messages',
                'message' => 'Conversation not found.',
                'user' => $this->app->auth->user(),
            ]);
            return;
        }

        $channelId = (int) $conv['channel_id'];
        $externalId = (string) $conv['external_id'];
        $convType = (string) ($conv['conversation_type'] ?? 'FROM_MEMBERS');

        // Clear unread as soon as the thread is opened (before refresh/sync can race).
        if ((int) ($conv['unread_count'] ?? 0) > 0) {
            $repo->markReadLocally($channelId, $externalId, $tenantId);
            $conv['unread_count'] = 0;
            $this->markConversationReadOnChannel($conv);
        }

        $messages = $repo->messagesFor($channelId, $externalId, $tenantId);
        $autoFetchError = null;

        if ($messages === []) {
            try {
                $this->refreshThread($conv);
                $messages = $repo->messagesFor($channelId, $externalId, $tenantId);
                $conv = $repo->findForTenant($tenantId, $id) ?? $conv;
                $conv['unread_count'] = 0;
                $repo->markReadLocally($channelId, $externalId, $tenantId);
            } catch (\Throwable $e) {
                $autoFetchError = $e->getMessage();
            }
        }

        $sellerUsername = $this->sellerUsername($conv);
        $buyerUsername = $this->resolveConversationBuyer($sellerUsername, $conv, $messages);
        $orders = $this->conversationOrders($conv, $messages, $buyerUsername);
        $listing = $this->conversationListing($conv);
        $users = $this->app->db->query(
            'SELECT u.id, u.display_name, u.email
             FROM #__users u
             INNER JOIN #__store_tenant_users tu ON tu.user_id = u.id
             WHERE tu.tenant_id=?
             ORDER BY u.display_name, u.email
             LIMIT 200',
            [$tenantId]
        )->rows;

        echo $this->view('admin/messages/conversation', [
            'title'          => 'Conversation',
            'navSection'     => 'messages',
            'conversation'   => $conv,
            'messages'       => $messages,
            'sellerUsername' => $sellerUsername,
            'buyerUsername'  => $buyerUsername,
            'orders'         => $orders,
            'listing'        => $listing,
            'templates'      => (new ReplyTemplateService($this->app))->list('ebay'),
            'users'          => $users,
            'autoFetchError' => $autoFetchError,
            'flash'          => $this->app->session->flash('success'),
            'error'          => $this->app->session->flash('error'),
            'user'           => $this->app->auth->user(),
        ]);
    }

    public function show(): void
    {
        // Legacy mail message detail (GetMyMessages rows).
        $id = (int) $this->app->request->get('id', 0);
        $row = $this->app->db->query(
            'SELECT m.*, c.name AS channel_name
             FROM #__store_buyer_messages m
             LEFT JOIN #__store_channels c ON c.id = m.channel_id
             WHERE m.id = ? AND m.tenant_id = ? LIMIT 1',
            [$id, $this->app->tenant->id()]
        )->row;
        if ($row === []) {
            http_response_code(404);
            echo $this->view('admin/error', [
                'title' => 'Not found',
                'navSection' => 'messages',
                'message' => 'Message not found.',
                'user' => $this->app->auth->user(),
            ]);
            return;
        }
        if (($row['status'] ?? '') === 'unread') {
            $this->app->db->query(
                "UPDATE #__store_buyer_messages SET status = 'open' WHERE id = ?",
                [$id]
            );
            $row['status'] = 'open';
        }
        $canMailReply = $this->mailReplySupported($row);
        echo $this->view('admin/messages/show', [
            'title'         => 'Message',
            'navSection'    => 'messages',
            'message'       => $row,
            'orders'        => $this->buyerOrders($row),
            'listing'       => $this->messageListing($row),
            'templates'     => (new ReplyTemplateService($this->app))->list((string) ($row['provider'] ?? '')),
            'canMailReply'  => $canMailReply,
            'flash'         => $this->app->session->flash('success'),
            'error'         => $this->app->session->flash('error'),
            'user'          => $this->app->auth->user(),
        ]);
    }

    public function mailReply(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/messages', ['folder' => 'mail']);
        }
        $id = (int) $this->app->request->post('id', 0);
        $body = trim((string) $this->app->request->post('body', ''));
        $tenantId = $this->app->tenant->id();
        $row = $this->app->db->query(
            'SELECT * FROM #__store_buyer_messages WHERE id=? AND tenant_id=? LIMIT 1',
            [$id, $tenantId]
        )->row;
        if ($row === []) {
            $this->app->session->flash('error', 'Message not found.');
            $this->redirect('admin/messages', ['folder' => 'mail']);
        }
        if ($body === '') {
            $this->app->session->flash('error', 'Reply cannot be empty.');
            $this->redirect('admin/messages/view', ['id' => $id]);
        }
        if (!$this->mailReplySupported($row)) {
            $this->app->session->flash('error', 'This mail row has no send API mapping. Use Conversations for FROM_MEMBERS threads, or open the linked inquiry under Disputes → Cases.');
            $this->redirect('admin/messages/view', ['id' => $id]);
        }

        $channel = $this->app->db->query(
            'SELECT * FROM #__store_channels WHERE id=? AND tenant_id=? LIMIT 1',
            [(int) $row['channel_id'], $tenantId]
        )->row;
        if ($channel === []) {
            $this->app->session->flash('error', 'Channel not found.');
            $this->redirect('admin/messages/view', ['id' => $id]);
        }
        $settings = $this->decodeSettings($channel['settings'] ?? null);
        $connector = (new ConnectorRegistry($this->app))->forChannel($channel, $settings);
        if (!$connector instanceof EbayConnector) {
            $this->app->session->flash('error', 'Mail reply is only supported for eBay inquiry rows right now.');
            $this->redirect('admin/messages/view', ['id' => $id]);
        }

        $inquiryId = $this->inquiryIdFromMail($row);
        $sent = $connector->replySupportInquiry($settings, $inquiryId, $body);
        $this->persistChannelSettings((int) $channel['id'], $tenantId, $sent['settings']);
        if (!$sent['ok']) {
            $this->app->session->flash('error', $sent['message']);
            $this->redirect('admin/messages/view', ['id' => $id]);
        }
        $this->app->db->query(
            "UPDATE #__store_buyer_messages SET status='replied' WHERE id=? AND tenant_id=?",
            [$id, $tenantId]
        );
        $this->app->session->flash('success', 'Inquiry reply sent.');
        $this->redirect('admin/messages/view', ['id' => $id]);
    }

    public function assignConversation(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/messages');
        }
        $id = (int) $this->app->request->post('id', 0);
        $userId = (int) $this->app->request->post('assigned_user_id', 0);
        $dueAt = trim((string) $this->app->request->post('due_at', ''));
        $priority = (int) $this->app->request->post('priority', 0);
        (new ConversationRepository($this->app->db))->assign(
            $this->app->tenant->id(),
            $id,
            $userId > 0 ? $userId : null,
            $dueAt !== '' ? $dueAt : null,
            $priority
        );
        $this->app->session->flash('success', 'Assignment updated.');
        $this->redirect('admin/messages/conversation', ['id' => $id]);
    }

    public function refresh(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/messages');
        }
        $id = (int) $this->app->request->post('id', 0);
        $repo = new ConversationRepository($this->app->db);
        $conv = $repo->findForTenant($this->app->tenant->id(), $id);
        if ($conv === null) {
            $this->app->session->flash('error', 'Conversation not found.');
            $this->redirect('admin/messages');
        }
        try {
            $result = $this->refreshThread($conv);
            $this->app->session->flash('success', 'Refreshed ' . $result['messages'] . ' message(s).');
        } catch (\Throwable $e) {
            $this->app->session->flash('error', 'Refresh failed: ' . $e->getMessage());
        }
        $this->redirect('admin/messages/conversation', ['id' => $id]);
    }

    public function reply(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/messages');
        }
        $id = (int) $this->app->request->post('id', 0);
        $body = trim((string) $this->app->request->post('body', ''));
        $tenantId = $this->app->tenant->id();
        $repo = new ConversationRepository($this->app->db);
        $conv = $repo->findForTenant($tenantId, $id);
        if ($conv === null) {
            $this->app->session->flash('error', 'Conversation not found.');
            $this->redirect('admin/messages');
        }
        if ($body === '') {
            $this->app->session->flash('error', 'Reply cannot be empty.');
            $this->redirect('admin/messages/conversation', ['id' => $id]);
        }

        $channel = $this->app->db->query(
            'SELECT * FROM #__store_channels WHERE id = ? AND tenant_id = ? LIMIT 1',
            [(int) $conv['channel_id'], $tenantId]
        )->row;
        if ($channel === []) {
            $this->app->session->flash('error', 'Channel not found.');
            $this->redirect('admin/messages/conversation', ['id' => $id]);
        }
        $settings = $this->decodeSettings($channel['settings'] ?? null);
        $connector = (new ConnectorRegistry($this->app))->forChannel($channel, $settings);
        if (!$connector instanceof EbayConnector) {
            $this->app->session->flash('error', 'Replies are only supported for eBay conversations right now.');
            $this->redirect('admin/messages/conversation', ['id' => $id]);
        }

        $sent = $connector->sendConversationMessage($settings, (string) $conv['external_id'], $body);
        $this->persistChannelSettings((int) $channel['id'], $tenantId, $sent['settings']);
        if (!$sent['ok']) {
            $this->app->session->flash('error', $sent['message']);
            $this->redirect('admin/messages/conversation', ['id' => $id]);
        }

        $payload = is_array($sent['payload'] ?? null) ? $sent['payload'] : [];
        $messageId = trim((string) ($payload['messageId'] ?? $payload['message_id'] ?? ''));
        if ($messageId === '') {
            $messageId = 'local-' . bin2hex(random_bytes(6));
        }
        $seller = $this->sellerUsername($conv);
        $repo->upsertMessage($tenantId, (int) $conv['channel_id'], (string) $conv['external_id'], [
            'messageId'         => $messageId,
            'messageBody'       => $body,
            'senderUsername'    => $seller !== '' ? $seller : 'You',
            'recipientUsername' => '',
            'createdDate'       => date('c'),
            'readStatus'        => true,
        ]);

        try {
            $this->refreshThread($conv);
        } catch (\Throwable) {
            // Local cache already has the outbound reply.
        }

        $this->app->session->flash('success', 'Reply sent.');
        $this->redirect('admin/messages/conversation', ['id' => $id]);
    }

    public function sync(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/messages');
        }
        $folder = (string) $this->app->request->post('folder', 'message');
        if ($folder === 'mail') {
            $result = (new MessageSync($this->app))->syncAll($this->app->tenant->id(), 14);
            $msg = 'Synced mail from ' . $result['channels'] . ' channel(s): '
                . $result['imported'] . ' new, ' . $result['updated'] . ' updated.';
            if ($result['errors'] !== []) {
                $msg .= ' ' . implode('; ', array_slice($result['errors'], 0, 3));
                $this->app->session->flash('error', $msg);
            } else {
                $this->app->session->flash('success', $msg);
            }
            $this->redirect('admin/messages', ['folder' => 'mail']);
        }

        $result = (new ConversationSync($this->app))->syncAll($this->app->tenant->id(), 5, 10);
        $msg = sprintf(
            'Synced conversations: %d new, %d updated, %d message(s) across %d channel(s).',
            $result['created'],
            $result['updated'],
            $result['messages'],
            $result['channels']
        );
        if ($result['errors'] !== []) {
            $msg .= ' ' . implode('; ', array_slice($result['errors'], 0, 3));
            $this->app->session->flash('error', $msg);
        } else {
            $this->app->session->flash('success', $msg);
        }
        $this->redirect('admin/messages', ['folder' => 'message']);
    }

    public function refund(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/messages');
        }
        $messageId = (int) $this->app->request->post('message_id', 0);
        $orderId = (int) $this->app->request->post('order_id', 0);
        $tenantId = $this->app->tenant->id();
        $order = $this->app->db->query(
            'SELECT id, external_order_id, status FROM #__store_orders WHERE id = ? AND tenant_id = ? LIMIT 1',
            [$orderId, $tenantId]
        )->row;
        if ($order === []) {
            $this->app->session->flash('error', 'Order not found.');
            $this->redirect('admin/messages');
        }
        if ((string) ($order['status'] ?? '') === 'refunded') {
            $this->app->session->flash('success', 'Order ' . $order['external_order_id'] . ' is already refunded.');
            $this->redirectBackFromRefund($messageId);
        }
        $result = (new \App\Store\OrderChannelActionService($this->app))->issueRefund($orderId);
        if ($messageId > 0) {
            $this->app->db->query(
                "UPDATE #__store_buyer_messages SET status = 'replied' WHERE id = ? AND tenant_id = ?",
                [$messageId, $tenantId]
            );
        }
        $this->app->session->flash($result['ok'] ? 'success' : 'error', $result['message']);
        $this->redirectBackFromRefund($messageId);
    }

    public function updateStatus(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/messages');
        }
        $id = (int) $this->app->request->post('id', 0);
        $status = (string) $this->app->request->post('status', 'open');
        if (!in_array($status, ['unread', 'open', 'replied', 'closed'], true)) {
            $status = 'open';
        }
        $this->app->db->query(
            'UPDATE #__store_buyer_messages SET status = ? WHERE id = ? AND tenant_id = ?',
            [$status, $id, $this->app->tenant->id()]
        );
        $this->app->session->flash('success', 'Message marked ' . $status . '.');
        $this->redirect('admin/messages/view', ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $conv
     * @return array{messages:int}
     */
    private function refreshThread(array $conv): array
    {
        $tenantId = $this->app->tenant->id();
        $channel = $this->app->db->query(
            'SELECT * FROM #__store_channels WHERE id = ? AND tenant_id = ? LIMIT 1',
            [(int) $conv['channel_id'], $tenantId]
        )->row;
        if ($channel === []) {
            throw new \RuntimeException('Channel not found.');
        }
        $settings = $this->decodeSettings($channel['settings'] ?? null);
        $connector = (new ConnectorRegistry($this->app))->forChannel($channel, $settings);
        if (!$connector instanceof EbayConnector) {
            throw new \RuntimeException('Conversation refresh is only available for eBay.');
        }
        $sync = new ConversationSync($this->app);
        $repo = new ConversationRepository($this->app->db);
        $result = $sync->syncThread(
            $connector,
            $repo,
            $tenantId,
            (int) $conv['channel_id'],
            $settings,
            (string) $conv['external_id'],
            (string) ($conv['conversation_type'] ?? 'FROM_MEMBERS'),
        );
        $this->persistChannelSettings((int) $channel['id'], $tenantId, $result['settings']);
        return ['messages' => $result['messages']];
    }

    private function redirectBackFromRefund(int $messageId): void
    {
        $convId = (int) $this->app->request->post('conversation_id', 0);
        if ($convId > 0) {
            $this->redirect('admin/messages/conversation', ['id' => $convId]);
        }
        if ($messageId > 0) {
            $this->redirect('admin/messages/view', ['id' => $messageId]);
        }
        $this->redirect('admin/messages');
    }

    private function mailUnreadCount(int $tenantId): int
    {
        return (int) $this->app->db->query(
            "SELECT COUNT(*) AS c FROM #__store_buyer_messages
             WHERE tenant_id = ? AND folder = 'mail' AND status = 'unread'",
            [$tenantId]
        )->row['c'];
    }

    /** @param array<string, mixed> $conv */
    private function sellerUsername(array $conv): string
    {
        $raw = $conv['channel_settings'] ?? null;
        $settings = $this->decodeSettings($raw);
        foreach (['ebay_username', 'linked_user', 'username'] as $key) {
            $v = trim((string) ($settings[$key] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }
        return '';
    }

    /**
     * @param array<string, mixed> $conversation
     * @param list<array<string, mixed>> $messages
     */
    private function resolveConversationBuyer(string $sellerUsername, array $conversation, array $messages): string
    {
        $candidates = [trim((string) ($conversation['latest_sender'] ?? ''))];
        foreach ($messages as $m) {
            $candidates[] = trim((string) ($m['sender'] ?? ''));
            $candidates[] = trim((string) ($m['recipient'] ?? ''));
        }
        foreach ($candidates as $name) {
            if ($name === '') {
                continue;
            }
            if ($sellerUsername !== '' && strcasecmp($name, $sellerUsername) === 0) {
                continue;
            }
            if ($this->isSystemSender($name)) {
                continue;
            }
            return $name;
        }
        return '';
    }

    /**
     * @param array<string, mixed> $conversation
     * @param list<array<string, mixed>> $messages
     * @return list<array<string, mixed>>
     */
    private function conversationOrders(array $conversation, array $messages, string $buyerUsername): array
    {
        $tenantId = $this->app->tenant->id();
        $channelId = (int) ($conversation['channel_id'] ?? 0);
        $byId = [];
        $select = 'SELECT id, external_order_id, status, total, currency, buyer_username, buyer_email, shipping_name,
                          shipping_address, shipping_method, tracking_number, carrier_code, created_at, channel_id
                   FROM #__store_orders';

        $push = function (array $rows) use (&$byId): void {
            foreach ($rows as $row) {
                $byId[(int) $row['id']] = $row;
            }
        };

        $referenceId = trim((string) ($conversation['reference_id'] ?? ''));
        $referenceType = strtoupper(trim((string) ($conversation['reference_type'] ?? '')));
        if ($referenceId !== '') {
            if (in_array($referenceType, ['ORDER', 'TRANSACTION', 'PURCHASE'], true) || str_contains($referenceId, '-')) {
                $params = [$tenantId, $referenceId];
                $sql = $select . ' WHERE tenant_id = ? AND external_order_id = ?';
                if ($channelId > 0) {
                    $sql .= ' AND channel_id = ?';
                    $params[] = $channelId;
                }
                $sql .= ' LIMIT 5';
                $push($this->app->db->query($sql, $params)->rows);
            }
            if (preg_match('/^\d{6,14}$/', $referenceId)) {
                $params = [$tenantId, $referenceId, $referenceId];
                $sql = $select . ' o WHERE o.tenant_id = ?
                    AND EXISTS (
                        SELECT 1 FROM #__store_listings l
                        WHERE l.channel_id = o.channel_id AND l.tenant_id = o.tenant_id
                          AND (l.external_id = ? OR l.sku = ?)
                    )';
                if ($channelId > 0) {
                    $sql .= ' AND o.channel_id = ?';
                    $params[] = $channelId;
                }
                $sql .= ' ORDER BY o.id DESC LIMIT 5';
                $push($this->app->db->query($sql, $params)->rows);
            }
        }

        $blob = $referenceId . "\n" . (string) ($conversation['latest_body'] ?? '');
        foreach ($messages as $m) {
            $blob .= "\n" . (string) ($m['body'] ?? '');
        }
        foreach ($this->extractTrackingCandidates($blob) as $tracking) {
            $params = [$tenantId, $tracking, '%' . $tracking . '%'];
            $sql = $select . " WHERE tenant_id = ? AND tracking_number <> ''
                AND (tracking_number = ? OR tracking_number LIKE ?)";
            if ($channelId > 0) {
                $sql .= ' AND channel_id = ?';
                $params[] = $channelId;
            }
            $sql .= ' ORDER BY id DESC LIMIT 3';
            $push($this->app->db->query($sql, $params)->rows);
        }

        if ($buyerUsername !== '' && !$this->isSystemSender($buyerUsername)) {
            $params = [$tenantId, $buyerUsername];
            $sql = $select . ' WHERE tenant_id = ? AND buyer_username = ?';
            if ($channelId > 0) {
                $sql .= ' AND channel_id = ?';
                $params[] = $channelId;
            }
            $sql .= ' ORDER BY id DESC LIMIT 8';
            $push($this->app->db->query($sql, $params)->rows);
        }

        $orders = array_values($byId);
        usort($orders, static fn (array $a, array $b): int => (int) $b['id'] <=> (int) $a['id']);
        $orders = array_slice($orders, 0, 7);
        foreach ($orders as &$o) {
            $o['items'] = $this->app->db->query(
                'SELECT sku, name, qty, unit_price, line_total FROM #__store_order_items WHERE order_id = ? ORDER BY id',
                [(int) $o['id']]
            )->rows;
        }
        unset($o);
        return $orders;
    }

    /**
     * @param array<string, mixed> $conversation
     * @return array{item_id:string,url:string}|null
     */
    private function conversationListing(array $conversation): ?array
    {
        $itemId = trim((string) ($conversation['reference_id'] ?? ''));
        if (!preg_match('/^\d{6,14}$/', $itemId)) {
            return null;
        }
        return [
            'item_id' => $itemId,
            'url'     => 'https://www.ebay.co.uk/itm/' . rawurlencode($itemId),
        ];
    }

    /** @param array<string, mixed> $message @return list<array<string, mixed>> */
    private function buyerOrders(array $message): array
    {
        return $this->conversationOrders([
            'channel_id'   => (int) ($message['channel_id'] ?? 0),
            'reference_id' => (string) ($message['order_external_id'] ?? ''),
            'latest_body'  => (string) ($message['body'] ?? ''),
        ], [['body' => (string) ($message['body'] ?? ''), 'sender' => (string) ($message['buyer_name'] ?? '')]],
            (string) ($message['buyer_name'] ?? ''));
    }

    /** @param array<string, mixed> $message @return array{item_id:string,url:string}|null */
    private function messageListing(array $message): ?array
    {
        $direct = trim((string) ($message['order_external_id'] ?? ''));
        if (preg_match('/^\d{6,14}$/', $direct)) {
            return ['item_id' => $direct, 'url' => 'https://www.ebay.co.uk/itm/' . rawurlencode($direct)];
        }
        return null;
    }

    /** @return list<string> */
    private function extractTrackingCandidates(string $text): array
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $out = [];
        if (preg_match_all('/\b([A-Z]{2}\d{8,22}[A-Z]{2})\b/i', $text, $m)) {
            foreach ($m[1] as $code) {
                $out[strtoupper((string) $code)] = strtoupper((string) $code);
            }
        }
        if (preg_match_all('/\b(\d{12,22})\b/', $text, $m2)) {
            foreach ($m2[1] as $code) {
                $out[(string) $code] = (string) $code;
            }
        }
        return array_values($out);
    }

    private function isSystemSender(string $name): bool
    {
        $n = strtolower(trim($name));
        if ($n === '' || $n === 'ebay buyer') {
            return true;
        }
        foreach ([
            'ebay', 'duzky', 'royalmail', 'royal mail', 'evri', 'yodel', 'dpd', 'hermes',
            'inpost', 'parcelforce', 'ups', 'dhl', 'fedex', 'paypal', 'inmoment',
            'noreply', 'no-reply', 'mailer-daemon', 'notification', 'tracking', 'delivery',
        ] as $needle) {
            if (str_contains($n, $needle)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $conv */
    private function markConversationReadOnChannel(array $conv): void
    {
        try {
            $tenantId = $this->app->tenant->id();
            $channel = $this->app->db->query(
                'SELECT * FROM #__store_channels WHERE id=? AND tenant_id=? LIMIT 1',
                [(int) $conv['channel_id'], $tenantId]
            )->row;
            if ($channel === []) {
                return;
            }
            $settings = $this->decodeSettings($channel['settings'] ?? null);
            $connector = (new ConnectorRegistry($this->app))->forChannel($channel, $settings);
            if (!$connector instanceof EbayConnector) {
                return;
            }
            $res = $connector->markConversationRead(
                $settings,
                (string) $conv['external_id'],
                (string) ($conv['conversation_type'] ?? 'FROM_MEMBERS')
            );
            if (!empty($res['settings'])) {
                $this->persistChannelSettings((int) $channel['id'], $tenantId, $res['settings']);
            }
        } catch (\Throwable) {
            // Best-effort channel mark-read; local unread already cleared.
        }
    }

    /** @param array<string,mixed> $row */
    private function mailReplySupported(array $row): bool
    {
        return $this->inquiryIdFromMail($row) !== '';
    }

    /** @param array<string,mixed> $row */
    private function inquiryIdFromMail(array $row): string
    {
        $external = trim((string) ($row['external_id'] ?? ''));
        if (str_starts_with($external, 'ebay-inq-')) {
            return substr($external, strlen('ebay-inq-'));
        }
        $thread = trim((string) ($row['thread_id'] ?? ''));
        $provider = strtolower((string) ($row['provider'] ?? ''));
        $subject = strtolower((string) ($row['subject'] ?? ''));
        if ($provider === 'ebay' && $thread !== '' && str_contains($subject, 'inquiry')) {
            return $thread;
        }
        return '';
    }

    /** @return array<string, string> */
    private function decodeSettings(mixed $raw): array
    {
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? array_map('strval', $decoded) : [];
        }
        return is_array($raw) ? array_map('strval', $raw) : [];
    }

    /** @param array<string, mixed> $settings */
    private function persistChannelSettings(int $channelId, int $tenantId, array $settings): void
    {
        $this->app->db->query(
            'UPDATE #__store_channels SET settings = ? WHERE id = ? AND tenant_id = ?',
            [json_encode($settings, JSON_UNESCAPED_UNICODE), $channelId, $tenantId]
        );
    }
}
