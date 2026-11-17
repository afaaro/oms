<?php

declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

echo "ok\n";
echo 'php=' . PHP_VERSION . "\n";

try {
    require __DIR__ . '/bootstrap/autoload.php';
    $app = new App\Core\App(__DIR__);
    $app->db->query('SELECT 1');
    echo "db=ok\n";
    echo 'login=' . $app->url('login') . "\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo 'error=' . $e->getMessage() . "\n";
}
