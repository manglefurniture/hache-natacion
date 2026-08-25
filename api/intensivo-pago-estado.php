<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/auth.php';
auth_require(['ADMIN','VERIFICADOR']);
$config = require __DIR__ . '/../config/database.php';

function intensive_payment_status_out(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        header('Allow: GET');
        intensive_payment_status_out(['ok' => false, 'error' => 'Método no permitido'], 405);
    }

    $courseId = trim((string)($_GET['curso_id'] ?? ''));
    $studentId = trim((string)($_GET['alumno_id'] ?? ''));
    if ($courseId === '' || $studentId === '') {
        intensive_payment_status_out(['ok' => false, 'error' => 'Curso y alumno son obligatorios'], 422);
    }

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

    $siteKey = auth_active_sede_clave();
    $stmt = $pdo->prepare("SELECT id FROM sedes WHERE clave=:clave AND activo=1 LIMIT 1");
    $stmt->execute([':clave' => $siteKey]);
    $siteId = (string)$stmt->fetchColumn();
    if ($siteId === '') {
        intensive_payment_status_out(['ok' => false, 'error' => 'Sede activa inválida'], 422);
    }

    $stmt = $pdo->prepare(
        "SELECT cia.curso_intensivo_id,cia.alumno_id,
            EXISTS(
                SELECT 1
                FROM pagos p
                WHERE p.alumno_id=cia.alumno_id
                  AND p.intensivo_id=cia.curso_intensivo_id
                  AND p.tipo='INTENSIVO'
                  AND p.estado='VALIDO'
            ) AS pagado
         FROM curso_intensivo_alumnos cia
         INNER JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id
         INNER JOIN alumnos a ON a.id=cia.alumno_id AND a.sede_id=ci.sede_id
         WHERE cia.curso_intensivo_id=:curso
           AND cia.alumno_id=:alumno
           AND ci.sede_id=:sede
         LIMIT 1"
    );
    $stmt->execute([
        ':curso' => $courseId,
        ':alumno' => $studentId,
        ':sede' => $siteId,
    ]);
    $relation = $stmt->fetch();
    if (!$relation) {
        intensive_payment_status_out(['ok' => false, 'error' => 'El alumno no pertenece a este curso intensivo'], 404);
    }

    intensive_payment_status_out([
        'ok' => true,
        'curso_id' => (string)$relation['curso_intensivo_id'],
        'alumno_id' => (string)$relation['alumno_id'],
        'pagado' => (int)$relation['pagado'] === 1,
    ]);
} catch (Throwable $e) {
    error_log('[intensivo-pago-estado] ' . $e->getMessage());
    intensive_payment_status_out(['ok' => false, 'error' => 'No se pudo comprobar el estado del pago'], 500);
}
