<?php
declare(strict_types=1);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
require_once __DIR__ . '/../config/auth.php';
if ($method === 'GET' || $method === 'HEAD') auth_require(['ADMIN','VERIFICADOR']);
else auth_require(['ADMIN']);

function hache_fecha_solicitada(string $method): ?string
{
    if ($method === 'GET') {
        return isset($_GET['fecha']) ? (string)$_GET['fecha'] : date('Y-m-d');
    }

    // Para POST no consumimos php://input aquí porque el endpoint original lo necesita.
    // Las acciones POST solo se originan desde clases visibles en la interfaz laborable.
    return null;
}

$fecha = hache_fecha_solicitada($method);

if ($fecha !== null) {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
    if ($d && $d->format('Y-m-d') === $fecha) {
        $diaSemana = (int)$d->format('N'); // 1=lunes ... 7=domingo

        if ($diaSemana >= 6) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, max-age=0');
            echo json_encode([
                'ok' => true,
                'fecha' => $d->format('Y-m-d'),
                'laborable' => false,
                'mensaje' => 'Hache Natación imparte clases de lunes a viernes.',
                'sesiones_creadas' => 0,
                'sesiones' => [],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
    }
}

require __DIR__ . '/sesiones.php';
