<?php

declare(strict_types=1);

use App\Core\App;
use App\Support\ErrorHandler;
use App\Support\Logger;

require __DIR__ . '/autoload.php';

$basePath = dirname(__DIR__);
$logger = new Logger($basePath);

$debug = false;
try {
    $appCfg = is_file($basePath . '/config/app.php') ? (require $basePath . '/config/app.php') : [];
    $debug = !empty($appCfg['debug']);
} catch (Throwable) {
    $debug = true;
}

ErrorHandler::register($logger, $debug);

try {
    $app = new App($basePath);
    $app->logger = $logger;
    $app->run();
} catch (Throwable $e) {
    $handler = ErrorHandler::instance();
    if ($handler !== null) {
        $handler->handleException($e);
    } else {
        $logger->exception($e, 'oms');
        http_response_code(500);
        echo $debug ? $e->getMessage() : 'Application error.';
    }
}
