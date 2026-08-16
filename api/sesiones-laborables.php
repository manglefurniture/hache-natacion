<?php
declare(strict_types=1);

// Hache Natación imparte clases únicamente de lunes a viernes.
// Este endpoint protege la vista de asistencia para que no genere jornadas
// automáticas en sábado o domingo.

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $fecha = (string)($_GET['fecha'] ?? date('Y-m-d'));
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $fecha);

    if (!$dt) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Fecha inválida'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ((int)$dt->format('N') >= 6) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => true,
            'fecha' => $fecha,
            'dia_no_laborable' => true,
            'sesiones_creadas' => 0,
            'sesiones' => [],
            'mensaje' => 'Hache Natación tiene clases de lunes a viernes.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

require __DIR__ . '/sesiones.php';
