<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('COMPANY_SETUP_CONTEXT', true);
require __DIR__ . '/../database/setup_support.php';
exit(company_setup_main($argv, false));
