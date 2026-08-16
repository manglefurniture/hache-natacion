<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once dirname(__DIR__, 2) . '/config/database.php';

try {
    $pdo = db();
    $pdo->query('SELECT 1');

    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'app' => 'Hache Natación',
        'database' => 'connected',
        'time' => date(DATE_ATOM),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode([
        'ok' => false,
        'app' => 'Hache Natación',
        'database' => 'unavailable',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
