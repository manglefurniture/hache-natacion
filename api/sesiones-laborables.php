<?php
declare(strict_types=1);

// Hache Natación imparte clases únicamente de lunes a viernes.
// Este endpoint protege la vista de asistencia para que no genere jornadas
// automáticas en sábado o domingo.

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
require_once __DIR__ . '/../config/auth.php';
if ($method === 'GET' || $method === 'HEAD') auth_require(['ADMIN','VERIFICADOR']);
else auth_require(['ADMIN']);

if ($method === 'GET') {
    $fecha = (string)($_GET['fecha'] ?? date('Y-m-d'));
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);

    if (!$dt || $dt->format('Y-m-d') !== $fecha) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Fecha inválida'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ((int)$dt->format('N') >= 6) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
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
