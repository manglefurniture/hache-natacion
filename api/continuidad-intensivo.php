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

    if ($method === 'GET') {
        $cursoId = trim((string)($_GET['curso_id'] ?? ''));
        $alumnoId = trim((string)($_GET['alumno_id'] ?? ''));

        if ($cursoId === '' || $alumnoId === '') {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'curso_id y alumno_id son obligatorios'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT
                cia.id,
                cia.curso_intensivo_id,
                cia.alumno_id,
                a.nombre AS alumno_nombre,
                cia.continua_regular,
                cia.plan_continuidad_id,
                cia.importe_continuidad,
                cia.observacion_continuidad,
                a.plan_actual_id,
                a.horario_preferido_id
            FROM curso_intensivo_alumnos cia
            INNER JOIN alumnos a ON a.id = cia.alumno_id
            WHERE cia.curso_intensivo_id = :curso_id
              AND cia.alumno_id = :alumno_id
            LIMIT 1
        ");
        $stmt->execute([':curso_id' => $cursoId, ':alumno_id' => $alumnoId]);
        $relacion = $stmt->fetch();

        if (!$relacion) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'El alumno no pertenece a este intensivo'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $planes = $pdo->query("
            SELECT id, nombre, sesiones_semana, precio
            FROM planes
            WHERE activo = 1
            ORDER BY sesiones_semana, precio
        ")->fetchAll();

        $horarios = $pdo->query("
            SELECT id, hora_inicio, hora_fin
            FROM horarios
            WHERE activo = 1
              AND regular = 1
            ORDER BY hora_inicio
        ")->fetchAll();

        echo json_encode([
            'ok' => true,
            'relacion' => $relacion,
            'planes' => $planes,
            'horarios' => $horarios,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'JSON inválido'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $cursoId = trim((string)($input['curso_id'] ?? ''));
        $alumnoId = trim((string)($input['alumno_id'] ?? ''));
        $continua = filter_var($input['continua_regular'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $planId = trim((string)($input['plan_id'] ?? ''));
        $horarioId = trim((string)($input['horario_id'] ?? ''));
        $importe = $input['importe_continuidad'] ?? null;
        $observacion = trim((string)($input['observacion_continuidad'] ?? ''));

        if ($cursoId === '' || $alumnoId === '' || $continua === null) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Datos de continuidad incompletos'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT id
            FROM curso_intensivo_alumnos
            WHERE curso_intensivo_id = :curso_id
              AND alumno_id = :alumno_id
            LIMIT 1
        ");
        $stmt->execute([':curso_id' => $cursoId, ':alumno_id' => $alumnoId]);
        $relacionId = $stmt->fetchColumn();

        if (!$relacionId) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'El alumno no pertenece a este intensivo'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($continua) {
            if ($planId === '' || $horarioId === '') {
                http_response_code(422);
                echo json_encode(['ok' => false, 'error' => 'Selecciona plan y horario regular'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $stmt = $pdo->prepare("SELECT precio FROM planes WHERE id = :id AND activo = 1 LIMIT 1");
            $stmt->execute([':id' => $planId]);
            $plan = $stmt->fetch();
            if (!$plan) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'error' => 'El plan seleccionado no está disponible'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $stmt = $pdo->prepare("SELECT id FROM horarios WHERE id = :id AND activo = 1 AND regular = 1 LIMIT 1");
            $stmt->execute([':id' => $horarioId]);
            if (!$stmt->fetch()) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'error' => 'El horario seleccionado no está disponible como regular'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $importeFinal = ($importe !== null && $importe !== '' && is_numeric($importe))
                ? number_format((float)$importe, 2, '.', '')
                : number_format((float)$plan['precio'], 2, '.', '');

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                UPDATE curso_intensivo_alumnos
                SET continua_regular = 1,
                    plan_continuidad_id = :plan_id,
                    importe_continuidad = :importe,
                    observacion_continuidad = :observacion
                WHERE id = :id
            ");
            $stmt->execute([
                ':plan_id' => $planId,
                ':importe' => $importeFinal,
                ':observacion' => $observacion !== '' ? $observacion : null,
                ':id' => $relacionId,
            ]);

            $stmt = $pdo->prepare("
                UPDATE alumnos
                SET plan_actual_id = :plan_id,
                    horario_preferido_id = :horario_id,
                    estado_administrativo = 'ACTIVO',
                    updated_at = NOW()
                WHERE id = :alumno_id
            ");
            $stmt->execute([
                ':plan_id' => $planId,
                ':horario_id' => $horarioId,
                ':alumno_id' => $alumnoId,
            ]);

            $pdo->commit();
        } else {
            $stmt = $pdo->prepare("
                UPDATE curso_intensivo_alumnos
                SET continua_regular = 0,
                    plan_continuidad_id = NULL,
                    importe_continuidad = NULL,
                    observacion_continuidad = :observacion
                WHERE id = :id
            ");
            $stmt->execute([
                ':observacion' => $observacion !== '' ? $observacion : null,
                ':id' => $relacionId,
            ]);
        }

        echo json_encode([
            'ok' => true,
            'mensaje' => $continua
                ? 'Alumno convertido a continuidad regular'
                : 'Continuidad marcada como no',
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido'], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
