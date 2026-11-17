<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Global error / exception / shutdown handler for the OMS.
 */
final class ErrorHandler
{
    private static ?self $instance = null;
    private Logger $logger;
    private bool $debug;
    private bool $registered = false;

    public function __construct(Logger $logger, bool $debug = false)
    {
        $this->logger = $logger;
        $this->debug = $debug;
    }

    public static function register(Logger $logger, bool $debug = false): self
    {
        $handler = new self($logger, $debug);
        $handler->boot();
        self::$instance = $handler;
        return $handler;
    }

    public static function instance(): ?self
    {
        return self::$instance;
    }

    public function logger(): Logger
    {
        return $this->logger;
    }

    public function boot(): void
    {
        if ($this->registered) {
            return;
        }
        $this->registered = true;

        error_reporting(E_ALL);
        ini_set('display_errors', $this->debug ? '1' : '0');
        ini_set('log_errors', '1');

        set_error_handler([$this, 'handleError']);
        set_exception_handler([$this, 'handleException']);
        register_shutdown_function([$this, 'handleShutdown']);

        if (!$this->logger->isReady() && $this->logger->bootError() !== '') {
            error_log('[StoreFeeder] Logger not ready: ' . $this->logger->bootError());
        }
    }

    public function handleError(int $severity, string $message, string $file = '', int $line = 0): bool
    {
        // Respect @-suppressed errors
        if (!(error_reporting() & $severity)) {
            return false;
        }

        $level = match (true) {
            ($severity & (E_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR | E_CORE_ERROR | E_COMPILE_ERROR)) !== 0 => 'ERROR',
            ($severity & (E_WARNING | E_USER_WARNING | E_CORE_WARNING | E_COMPILE_WARNING)) !== 0 => 'WARNING',
            default => 'INFO',
        };

        $this->logger->write($level, $message, [
            'severity' => $severity,
            'file'     => $file . ':' . $line,
        ], 'php');

        // Promote serious errors to exceptions so request fails loudly
        if (($severity & (E_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR)) !== 0) {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        }

        return true;
    }

    public function handleException(\Throwable $e): void
    {
        $this->logger->exception($e, 'oms');

        if (!headers_sent()) {
            http_response_code(500);
        }

        if ($this->isJsonRequest()) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode([
                'ok'      => false,
                'error'   => $this->debug ? $e->getMessage() : 'Application error.',
                'code'    => 500,
                'debug'   => $this->debug ? [
                    'exception' => $e::class,
                    'file'      => $e->getFile(),
                    'line'      => $e->getLine(),
                ] : null,
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        if ($this->debug) {
            if (!headers_sent()) {
                header('Content-Type: text/html; charset=utf-8');
            }
            echo $this->renderDebugPage($e);
            return;
        }

        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }
        echo $this->renderSafePage();
    }

    public function handleShutdown(): void
    {
        $err = error_get_last();
        if ($err === null) {
            return;
        }
        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (!in_array((int) $err['type'], $fatalTypes, true)) {
            return;
        }

        $e = new \ErrorException(
            (string) ($err['message'] ?? 'Fatal error'),
            0,
            (int) $err['type'],
            (string) ($err['file'] ?? ''),
            (int) ($err['line'] ?? 0)
        );
        // Avoid double HTML if exception handler already ran
        $this->logger->exception($e, 'php');
        if (!headers_sent()) {
            http_response_code(500);
            if ($this->isJsonRequest()) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => 'Application error.', 'code' => 500], JSON_UNESCAPED_UNICODE);
            } elseif ($this->debug) {
                header('Content-Type: text/html; charset=utf-8');
                echo $this->renderDebugPage($e);
            } else {
                header('Content-Type: text/html; charset=utf-8');
                echo $this->renderSafePage();
            }
        }
    }

    private function isJsonRequest(): bool
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        if (str_contains($uri, '/api/')) {
            return true;
        }
        $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
        return str_contains($accept, 'application/json');
    }

    private function renderSafePage(): string
    {
        $loggerNote = '';
        if (!$this->logger->isReady() && $this->logger->bootError() !== '') {
            $loggerNote = '<p style="color:#92400e;font-size:13px">Logging: '
                . htmlspecialchars($this->logger->bootError(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</p>';
        }
        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Error</title></head>'
            . '<body style="font-family:system-ui,sans-serif;padding:2rem;background:#f8fafc;color:#0f172a">'
            . '<h1 style="margin:0 0 .5rem;font-size:1.25rem">Something went wrong</h1>'
            . '<p style="color:#64748b">The application hit an error. Please try again or contact support.</p>'
            . $loggerNote
            . '</body></html>';
    }

    private function renderDebugPage(\Throwable $e): string
    {
        $msg = htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $cls = htmlspecialchars($e::class, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $file = htmlspecialchars($e->getFile() . ':' . $e->getLine(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $trace = htmlspecialchars($e->getTraceAsString(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $logStatus = $this->logger->isReady()
            ? 'Logging to ' . htmlspecialchars($this->logger->directory(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            : htmlspecialchars($this->logger->bootError() ?: 'Logger not ready', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Application error</title></head>'
            . '<body style="font-family:Consolas,monospace;padding:1.5rem;background:#0f172a;color:#e2e8f0">'
            . '<h1 style="color:#f87171;font-size:1.1rem;margin:0 0 1rem">Unhandled exception</h1>'
            . '<p><strong>' . $cls . '</strong></p>'
            . '<pre style="white-space:pre-wrap;background:#1e293b;padding:1rem;border-radius:6px">' . $msg . '</pre>'
            . '<p style="color:#94a3b8">' . $file . '</p>'
            . '<pre style="white-space:pre-wrap;font-size:12px;color:#cbd5e1">' . $trace . '</pre>'
            . '<p style="margin-top:1.5rem;color:#64748b;font-size:12px">' . $logStatus . '</p>'
            . '</body></html>';
    }
}
