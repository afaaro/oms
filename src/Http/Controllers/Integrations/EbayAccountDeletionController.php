<?php

declare(strict_types=1);

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Integrations\Ebay\EbayAccountDeletionService;

/**
 * Public eBay Marketplace Account Deletion endpoint (GET challenge + POST notify).
 */
final class EbayAccountDeletionController extends Controller
{
    public function handle(): void
    {
        $svc = new EbayAccountDeletionService($this->app);
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        if ($method === 'GET') {
            $this->challenge($svc);
            return;
        }

        if ($method === 'POST') {
            $this->notify($svc);
            return;
        }

        http_response_code(405);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    }

    private function challenge(EbayAccountDeletionService $svc): void
    {
        $challenge = trim((string) ($this->app->request->get('challenge_code', '') ?: ($_GET['challenge_code'] ?? '')));
        if ($challenge === '') {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Missing challenge_code'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $token = $svc->verificationToken();
        if ($token === '' || strlen($token) < 32 || strlen($token) > 80) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Verification token not configured (32–80 chars).'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $response = $svc->challengeResponse($challenge);
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        // json_encode avoids BOM issues that break eBay's parser
        echo json_encode(['challengeResponse' => $response], JSON_UNESCAPED_UNICODE);
    }

    private function notify(EbayAccountDeletionService $svc): void
    {
        $raw = (string) file_get_contents('php://input');
        $signature = $svc->signatureHeaderFromRequest();

        if (!$svc->verifySignature($raw, $signature)) {
            http_response_code(412);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Precondition Failed — invalid signature'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            // Signature verified but body empty/invalid — acknowledge so eBay does not mark endpoint down.
            http_response_code(200);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true, 'message' => 'Acknowledged (empty/invalid body).'], JSON_UNESCAPED_UNICODE);
            return;
        }

        try {
            $svc->processNotification($payload);
        } catch (\Throwable $e) {
            // Acknowledge anyway; log for ops follow-up (eBay requires prompt 2xx after verify).
            if ($this->app->logger !== null) {
                $this->app->logger->exception($e, 'ebay_account_deletion');
            } elseif (\App\Support\ErrorHandler::instance() !== null) {
                \App\Support\ErrorHandler::instance()->logger()->exception($e, 'ebay_account_deletion');
            }
        }

        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    }
}
