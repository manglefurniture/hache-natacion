<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

try {
    $config = require __DIR__ . '/../config/database.php';

    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",
        $config['user'],
        $config['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    echo json_encode([
        'ok' => true,
        'application' => 'Hache Natación API',
        'database' => $pdo->query('SELECT DATABASE()')->fetchColumn(),
        'user' => $pdo->query('SELECT CURRENT_USER()')->fetchColumn(),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'ok' => false,
        'error' => 'Database connection failed'
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
