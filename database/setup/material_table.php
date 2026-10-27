<?php
declare(strict_types=1);

// Compatibility entry point: every legacy stage now runs the same safe, idempotent CLI setup.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../../scripts/setup.php';
