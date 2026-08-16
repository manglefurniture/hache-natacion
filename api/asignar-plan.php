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

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Método no permitido'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'JSON inválido'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $alumnoId = trim((string)($input['alumno_id'] ?? ''));
    $planId = trim((string)($input['plan_id'] ?? ''));

    if ($alumnoId === '' || $planId === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Alumno y plan son obligatorios'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare('SELECT id FROM alumnos WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $alumnoId]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'El alumno no existe'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT id, nombre, sesiones_semana, precio
        FROM planes
        WHERE id = :id AND activo = 1
        LIMIT 1
    ");
    $stmt->execute([':id' => $planId]);
    $plan = $stmt->fetch();
    if (!$plan) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'El plan seleccionado no existe o está inactivo'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare("
        UPDATE alumnos
        SET plan_actual_id = :plan_id, updated_at = NOW()
        WHERE id = :alumno_id
    ");
    $stmt->execute([':plan_id' => $planId, ':alumno_id' => $alumnoId]);

    echo json_encode([
        'ok' => true,
        'mensaje' => 'Plan asignado correctamente',
        'plan' => $plan,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
