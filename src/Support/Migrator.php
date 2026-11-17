<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Database;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Apply storage/migrations/*.sql and track applied files in store_schema_migrations.
 */
final class Migrator
{
    /** @var list<int> MySQL codes that mean "already applied" */
    private const IGNORABLE = [1050, 1060, 1061, 1091];

    public function __construct(
        private Database $db,
        private string $basePath,
    ) {}

    public function migrationsPath(): string
    {
        return rtrim(str_replace('\\', '/', $this->basePath), '/') . '/storage/migrations';
    }

    /**
     * @return list<array{file:string,path:string,size:int,mtime:string,applied:bool,applied_at:?string}>
     */
    public function status(): array
    {
        $this->ensureLogTable();
        $applied = [];
        foreach ($this->db->query('SELECT filename, applied_at FROM #__store_schema_migrations')->rows as $row) {
            $applied[(string) $row['filename']] = (string) ($row['applied_at'] ?? '');
        }

        $files = glob($this->migrationsPath() . '/*.sql') ?: [];
        sort($files);
        $out = [];
        foreach ($files as $path) {
            $name = basename($path);
            $out[] = [
                'file'       => $name,
                'path'       => $path,
                'size'       => (int) (filesize($path) ?: 0),
                'mtime'      => date('Y-m-d H:i:s', (int) filemtime($path)),
                'applied'    => isset($applied[$name]),
                'applied_at' => $applied[$name] ?? null,
            ];
        }
        return $out;
    }

    /**
     * @return array{ok:bool, message:string, ran:list<string>, skipped:list<string>, errors:list<string>}
     */
    public function run(bool $pendingOnly = true, ?string $onlyFile = null): array
    {
        $this->ensureLogTable();
        $ran = [];
        $skipped = [];
        $errors = [];

        foreach ($this->status() as $row) {
            $file = $row['file'];
            if ($onlyFile !== null && $onlyFile !== $file) {
                continue;
            }
            if ($pendingOnly && $row['applied'] && $onlyFile === null) {
                $skipped[] = $file;
                continue;
            }

            try {
                $this->applyFile($row['path']);
                $this->markApplied($file);
                $ran[] = $file;
            } catch (Throwable $e) {
                $errors[] = $file . ': ' . $e->getMessage();
                return [
                    'ok'      => false,
                    'message' => 'Migration stopped on ' . $file . '.',
                    'ran'     => $ran,
                    'skipped' => $skipped,
                    'errors'  => $errors,
                ];
            }
        }

        if ($ran === [] && $errors === []) {
            return [
                'ok'      => true,
                'message' => $pendingOnly ? 'Database is up to date — nothing pending.' : 'No migration files found.',
                'ran'     => $ran,
                'skipped' => $skipped,
                'errors'  => $errors,
            ];
        }

        return [
            'ok'      => true,
            'message' => 'Applied ' . count($ran) . ' migration(s).',
            'ran'     => $ran,
            'skipped' => $skipped,
            'errors'  => $errors,
        ];
    }

    /** Mark existing files as applied without executing (bootstrap after CLI-only deploys). */
    public function syncLog(): array
    {
        $this->ensureLogTable();
        $marked = [];
        foreach ($this->status() as $row) {
            if ($row['applied']) {
                continue;
            }
            $this->markApplied($row['file']);
            $marked[] = $row['file'];
        }
        return [
            'ok'      => true,
            'message' => $marked === []
                ? 'Migration log already matches files on disk.'
                : 'Marked ' . count($marked) . ' file(s) as applied (no SQL run).',
            'ran'     => $marked,
            'skipped' => [],
            'errors'  => [],
        ];
    }

    /** @return array{database:string, host:string, driver:string, tables:int, pending:int, applied:int} */
    public function databaseInfo(): array
    {
        $cfg = [];
        $configFile = rtrim(str_replace('\\', '/', $this->basePath), '/') . '/config/database.php';
        if (is_file($configFile)) {
            $loaded = require $configFile;
            if (is_array($loaded)) {
                $cfg = $loaded;
            }
        }

        $tables = 0;
        try {
            $tables = (int) ($this->db->query(
                "SELECT COUNT(*) AS c FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'"
            )->row['c'] ?? 0);
        } catch (Throwable) {
            $tables = 0;
        }

        $status = $this->status();
        $applied = count(array_filter($status, static fn(array $r): bool => $r['applied']));
        $pending = count($status) - $applied;

        return [
            'database' => (string) ($cfg['database'] ?? ''),
            'host'     => (string) ($cfg['hostname'] ?? $cfg['host'] ?? ''),
            'driver'   => 'mysql',
            'tables'   => $tables,
            'pending'  => $pending,
            'applied'  => $applied,
        ];
    }

    private function ensureLogTable(): void
    {
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `#__store_schema_migrations` (
              `id` int unsigned NOT NULL AUTO_INCREMENT,
              `filename` varchar(190) NOT NULL,
              `applied_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `store_schema_migrations_file` (`filename`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private function markApplied(string $filename): void
    {
        $exists = $this->db->query(
            'SELECT id FROM #__store_schema_migrations WHERE filename=? LIMIT 1',
            [$filename]
        )->row;
        if ($exists !== []) {
            $this->db->query(
                'UPDATE #__store_schema_migrations SET applied_at=NOW() WHERE filename=?',
                [$filename]
            );
            return;
        }
        $this->db->query(
            'INSERT INTO #__store_schema_migrations (filename, applied_at) VALUES (?, NOW())',
            [$filename]
        );
    }

    private function applyFile(string $path): void
    {
        $sql = file_get_contents($path);
        if ($sql === false || trim($sql) === '') {
            return;
        }

        $sql = preg_replace('#/\*.*?\*/#s', '', $sql) ?? $sql;
        $lines = preg_split('/\R/', $sql) ?: [];
        $clean = [];
        foreach ($lines as $line) {
            $trim = ltrim($line);
            if (str_starts_with($trim, '--')) {
                continue;
            }
            $clean[] = $line;
        }
        $body = implode("\n", $clean);
        $statements = preg_split('/;\s*\n/', $body) ?: [];

        $pdo = $this->db->pdo();
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if ($statement === '' || $statement === ';') {
                continue;
            }
            // Allow final statement without trailing newline after semicolon strip.
            $statement = rtrim($statement, "; \t\r\n");
            if ($statement === '') {
                continue;
            }
            try {
                $pdo->exec($this->prefixSql($statement));
            } catch (PDOException $e) {
                $code = (int) ($e->errorInfo[1] ?? 0);
                if (in_array($code, self::IGNORABLE, true)) {
                    continue;
                }
                throw new RuntimeException($e->getMessage(), (int) $e->getCode(), $e);
            }
        }
    }

    private function prefixSql(string $sql): string
    {
        $prefix = $this->db->getPrefix();
        return str_replace('#__', $prefix, $sql);
    }
}
