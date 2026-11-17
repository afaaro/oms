<?php

declare(strict_types=1);

namespace App\Integrations;

final class HttpClient
{
    private bool $httpsOnly;
    private bool $verifySsl;

    public function __construct(bool $httpsOnly = true, bool $verifySsl = true)
    {
        $this->httpsOnly = $httpsOnly;
        $this->verifySsl = $verifySsl;
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|string|null $body
     * @return array{status:int, body:string, json:array<string,mixed>|null, error:?string}
     */
    public function request(string $method, string $url, array $headers = [], array|string|null $body = null): array
    {
        if ($this->httpsOnly && !str_starts_with(strtolower($url), 'https://')) {
            return ['status' => 0, 'body' => '', 'json' => null, 'error' => 'Refused insecure URL (HTTPS required)'];
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => 0, 'body' => '', 'json' => null, 'error' => 'curl_init failed'];
        }

        $method = strtoupper($method);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_HTTPHEADER     => [],
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS | ($this->httpsOnly ? 0 : CURLPROTO_HTTP),
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS | ($this->httpsOnly ? 0 : CURLPROTO_HTTP),
            CURLOPT_USERAGENT      => 'StoreFeeder-OMS/1.0',
        ];

        $headerLines = [];
        foreach ($headers as $k => $v) {
            $headerLines[] = $k . ': ' . $v;
        }

        if ($body !== null) {
            if (is_array($body)) {
                $contentType = $headers['Content-Type'] ?? $headers['content-type'] ?? '';
                if (str_contains(strtolower($contentType), 'application/json')) {
                    $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
                } else {
                    $opts[CURLOPT_POSTFIELDS] = http_build_query($body);
                    if ($contentType === '') {
                        $headerLines[] = 'Content-Type: application/x-www-form-urlencoded';
                    }
                }
            } else {
                $opts[CURLOPT_POSTFIELDS] = $body;
            }
        }

        $opts[CURLOPT_HTTPHEADER] = $headerLines;
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        // curl_close() is a no-op since PHP 8.0 and deprecated in 8.5+

        if ($raw === false) {
            return ['status' => 0, 'body' => '', 'json' => null, 'error' => $err ?: 'request failed'];
        }

        $decoded = json_decode((string) $raw, true);
        return [
            'status' => $status,
            'body'   => (string) $raw,
            'json'   => is_array($decoded) ? $decoded : null,
            'error'  => $err !== '' ? $err : null,
        ];
    }
}
