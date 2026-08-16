<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/../config/database.php';

try {
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

    $method = $_SERVER['REQUEST_METHOD'];

    if ($method !== 'POST') {
        http_response_code(405);

        echo json_encode([
            'ok' => false,
            'error' => 'Método no permitido'
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    $input = json_decode(
        file_get_contents('php://input'),
        true
    );

    if (!is_array($input)) {
        http_response_code(400);

        echo json_encode([
            'ok' => false,
            'error' => 'JSON inválido'
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    $usuario = trim((string)($input['usuario'] ?? ''));
    $password = (string)($input['password'] ?? '');

    if ($usuario === '' || $password === '') {
        http_response_code(422);

        echo json_encode([
            'ok' => false,
            'error' => 'Usuario y contraseña son obligatorios'
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    $stmt = $pdo->prepare("
        SELECT
            id,
            usuario,
            password_hash,
            rol,
            activo,
            alumno_id
        FROM usuarios
        WHERE usuario = :usuario
        LIMIT 1
    ");

    $stmt->execute([
        ':usuario' => $usuario
    ]);

    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        http_response_code(401);

        echo json_encode([
            'ok' => false,
            'error' => 'Usuario o contraseña incorrectos'
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    if ((int)$user['activo'] !== 1) {
        http_response_code(403);

        echo json_encode([
            'ok' => false,
            'error' => 'El usuario está inactivo'
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    echo json_encode([
        'ok' => true,
        'mensaje' => 'Login correcto',
        'usuario' => [
            'id' => $user['id'],
            'usuario' => $user['usuario'],
            'rol' => $user['rol'],
            'alumno_id' => $user['alumno_id']
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        'ok' => false,
        'error' => 'Error interno del servidor'
    ], JSON_UNESCAPED_UNICODE);
}
