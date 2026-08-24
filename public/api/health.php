<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $pdo = require dirname(__DIR__, 2) . '/config/pdo.php';
    $pdo->query('SELECT 1');

    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'application' => 'Hache Natación API',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('[public-health] '.$e->getMessage());
    http_response_code(503);
    echo json_encode([
        'ok' => false,
        'error' => 'Service unavailable',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
