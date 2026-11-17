<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Lightweight result matching the old System\Library\Database query shape
 * so ported domain code can use ->rows / ->row / ->num_rows.
 */
final class QueryResult
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(
        public array $rows,
        public array $row,
        public int $num_rows,
        public string|false $insert_id,
    ) {}
}
