<?php

declare(strict_types=1);

namespace App\Support;

/**
 * File logger for OMS. Writes under storage/logs with daily rotation.
 */
final class Logger
{
    private string $dir;
    private bool $ready = false;
    private string $bootError = '';

    public function __construct(private string $basePath)
    {
        $this->dir = rtrim(str_replace('\\', '/', $basePath), '/') . '/storage/logs';
        $this->ensureDir();
    }

    public function directory(): string
    {
        return $this->dir;
    }

    public function isReady(): bool
    {
        return $this->ready;
    }

    public function bootError(): string
    {
        return $this->bootError;
    }

    public function info(string $message, array $context = [], string $channel = 'oms'): void
    {
        $this->write('INFO', $message, $context, $channel);
    }

    public function warning(string $message, array $context = [], string $channel = 'oms'): void
    {
        $this->write('WARNING', $message, $context, $channel);
    }

    public function error(string $message, array $context = [], string $channel = 'oms'): void
    {
        $this->write('ERROR', $message, $context, $channel);
    }

    public function exception(\Throwable $e, string $channel = 'oms', array $context = []): void
    {
        $context = array_merge($context, [
            'exception' => $e::class,
            'file'      => $e->getFile() . ':' . $e->getLine(),
        ]);
        $this->write(
            'ERROR',
            $e->getMessage() . "\n" . $e->getTraceAsString(),
            $context,
            $channel
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    public function write(string $level, string $message, array $context = [], string $channel = 'oms'): void
    {
        if (!$this->ensureDir()) {
            return;
        }

        $channel = preg_replace('/[^a-zA-Z0-9_\-]/', '', $channel) ?: 'oms';
        $date = date('Y-m-d');
        $file = $this->dir . '/' . $channel . '-' . $date . '.log';

        $line = sprintf(
            "[%s] %s %s",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $this->formatMessage($message, $context)
        );

        $ok = @file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        if ($ok === false) {
            $this->ready = false;
            $this->bootError = 'Cannot write log file: ' . $file;
            // Last resort — PHP's error_log (may hit Apache/PHP error log)
            error_log('[StoreFeeder] ' . $line);
        }
    }

    /**
     * @return list<array{name:string,path:string,size:int,mtime:int}>
     */
    public function listFiles(int $limit = 50): array
    {
        if (!is_dir($this->dir)) {
            return [];
        }
        $files = glob($this->dir . '/*.log') ?: [];
        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
        $out = [];
        foreach (array_slice($files, 0, max(1, $limit)) as $path) {
            $out[] = [
                'name'  => basename($path),
                'path'  => $path,
                'size'  => (int) (@filesize($path) ?: 0),
                'mtime' => (int) (@filemtime($path) ?: 0),
            ];
        }
        return $out;
    }

    /**
     * Delete one log file. Returns true if removed or already gone.
     */
    public function deleteFile(string $filename): bool
    {
        $filename = basename($filename);
        if (!preg_match('/^[a-zA-Z0-9_\-]+\.log$/', $filename)) {
            return false;
        }
        $path = $this->dir . '/' . $filename;
        if (!is_file($path)) {
            return true;
        }
        return @unlink($path);
    }

    /**
     * Delete all *.log files in the log directory.
     *
     * @return array{ok:bool, deleted:int, message:string}
     */
    public function clearAll(): array
    {
        if (!is_dir($this->dir)) {
            return ['ok' => true, 'deleted' => 0, 'message' => 'No log directory.'];
        }
        $deleted = 0;
        $failed = 0;
        foreach (glob($this->dir . '/*.log') ?: [] as $path) {
            if (@unlink($path)) {
                $deleted++;
            } else {
                $failed++;
            }
        }
        if ($failed > 0) {
            return [
                'ok'      => false,
                'deleted' => $deleted,
                'message' => "Deleted {$deleted} file(s); {$failed} could not be removed.",
            ];
        }
        return [
            'ok'      => true,
            'deleted' => $deleted,
            'message' => $deleted === 0 ? 'No log files to delete.' : "Deleted {$deleted} log file(s).",
        ];
    }

    /** Alias used by older call sites / deploy lag. */
    public function clear(): array
    {
        return $this->clearAll();
    }

    public function readTail(string $filename, int $maxBytes = 65536): string
    {
        $filename = basename($filename);
        if (!preg_match('/^[a-zA-Z0-9_\-]+\.log$/', $filename)) {
            return '';
        }
        $path = $this->dir . '/' . $filename;
        if (!is_file($path)) {
            return '';
        }
        $size = (int) filesize($path);
        if ($size <= 0) {
            return '';
        }
        $fp = @fopen($path, 'rb');
        if ($fp === false) {
            return '';
        }
        $start = max(0, $size - max(1024, $maxBytes));
        if ($start > 0) {
            fseek($fp, $start);
        }
        $data = (string) stream_get_contents($fp);
        fclose($fp);
        if ($start > 0) {
            $nl = strpos($data, "\n");
            if ($nl !== false) {
                $data = substr($data, $nl + 1);
            }
        }
        return $data;
    }

    private function ensureDir(): bool
    {
        if ($this->ready && is_dir($this->dir) && is_writable($this->dir)) {
            return true;
        }
        if (!is_dir($this->dir)) {
            if (!@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
                $this->bootError = 'Cannot create log directory: ' . $this->dir;
                $this->ready = false;
                return false;
            }
        }
        if (!is_writable($this->dir)) {
            $this->bootError = 'Log directory is not writable: ' . $this->dir
                . ' (grant write for the web server / PHP user)';
            $this->ready = false;
            return false;
        }
        // Probe write
        $probe = $this->dir . '/.write_probe';
        if (@file_put_contents($probe, '1') === false) {
            $this->bootError = 'Log directory write probe failed: ' . $this->dir;
            $this->ready = false;
            return false;
        }
        @unlink($probe);
        $this->ready = true;
        $this->bootError = '';
        return true;
    }

    /** @param array<string, mixed> $context */
    private function formatMessage(string $message, array $context): string
    {
        $message = str_replace(["\r\n", "\r"], "\n", $message);
        if ($context === []) {
            return $message;
        }
        $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $message . ($json !== false ? ' ' . $json : '');
    }
}
