<?php

declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private PDO $pdo;
    private string $prefix;

    /** @param array<string, mixed> $config */
    public function __construct(array $config)
    {
        $host = (string) ($config['hostname'] ?? $config['host'] ?? 'localhost');
        $port = (int) ($config['port'] ?? 3306);
        $name = (string) ($config['database'] ?? $config['name'] ?? '');
        $user = (string) ($config['username'] ?? $config['user'] ?? '');
        $pass = (string) ($config['password'] ?? '');
        $charset = (string) ($config['charset'] ?? 'utf8mb4');
        $this->prefix = (string) ($config['prefix'] ?? '');

        if ($name === '' || $user === '') {
            throw new InvalidArgumentException('Database name and username are required.');
        }

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";
        try {
            $this->pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('Database connection failed.', 500, $e);
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * @param array<int|string, mixed> $params
     */
    public function query(string $sql, array $params = []): QueryResult
    {
        $sql = str_replace('#__', $this->prefix, $sql);
        try {
            $stmt = $this->pdo->prepare($sql);
            $hasNamed = count(array_filter(array_keys($params), 'is_string')) > 0;
            $stmt->execute($hasNamed ? $params : array_values($params));
        } catch (PDOException $e) {
            throw new RuntimeException('Database query failed: ' . $e->getMessage(), 0, $e);
        }

        $rows = [];
        if ($stmt->columnCount() > 0) {
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        return new QueryResult($rows, $rows[0] ?? [], count($rows), $this->pdo->lastInsertId());
    }
}
