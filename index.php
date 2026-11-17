<?php

declare(strict_types=1);

/**
 * Front controller — point the domain document root at this project folder.
 */
if ((int) ($_SERVER['REDIRECT_STATUS'] ?? 0) === 404) {
    http_response_code(200);
}

require __DIR__ . '/bootstrap/app.php';
