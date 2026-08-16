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

    /*
     * GET /api/alumnos.php
     * Lista alumnos.
     */
    if ($method === 'GET') {

        $stmt = $pdo->query("
            SELECT
                a.id,
                a.nombre,
                a.fecha_nacimiento,
                a.whatsapp,
                a.correo,
                a.fecha_inicio,
                a.horario_preferido_id,
                a.plan_actual_id,
                p.nombre AS plan_nombre,
                p.precio AS plan_precio,
                a.estado_administrativo,
                a.observaciones,
                a.created_at,
                a.updated_at
            FROM alumnos a
            LEFT JOIN planes p
                ON p.id = a.plan_actual_id
            ORDER BY a.nombre ASC
        ");

        $alumnos = $stmt->fetchAll();

        echo json_encode([
            'ok' => true,
            'total' => count($alumnos),
            'alumnos' => $alumnos
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        exit;
    }

    /*
     * POST /api/alumnos.php
     * Crea un alumno.
     */
    if ($method === 'POST') {

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

        $nombre = trim((string)($input['nombre'] ?? ''));
        $fechaNacimiento = $input['fecha_nacimiento'] ?? null;
        $whatsapp = trim((string)($input['whatsapp'] ?? ''));
        $correo = trim((string)($input['correo'] ?? ''));
        $fechaInicio = $input['fecha_inicio'] ?? null;
        $horarioId = $input['horario_preferido_id'] ?? null;
        $planId = $input['plan_actual_id'] ?? null;
        $estado = $input['estado_administrativo'] ?? 'ACTIVO';
        $observaciones = $input['observaciones'] ?? null;

        if ($nombre === '') {
            http_response_code(422);

            echo json_encode([
                'ok' => false,
                'error' => 'El nombre es obligatorio'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        if ($fechaNacimiento === null || $fechaNacimiento === '') {
            http_response_code(422);

            echo json_encode([
                'ok' => false,
                'error' => 'La fecha de nacimiento es obligatoria'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        if ($whatsapp === '') {
            http_response_code(422);

            echo json_encode([
                'ok' => false,
                'error' => 'El WhatsApp es obligatorio'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        if ($fechaInicio === null || $fechaInicio === '') {
            http_response_code(422);

            echo json_encode([
                'ok' => false,
                'error' => 'La fecha de inicio es obligatoria'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        if ($horarioId === null || $horarioId === '') {
            http_response_code(422);

            echo json_encode([
                'ok' => false,
                'error' => 'El horario es obligatorio'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        if ($planId === null || $planId === '') {
            http_response_code(422);

            echo json_encode([
                'ok' => false,
                'error' => 'El plan es obligatorio'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        if (!in_array($estado, ['ACTIVO', 'BAJA'], true)) {
            http_response_code(422);

            echo json_encode([
                'ok' => false,
                'error' => 'Estado administrativo inválido'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        /*
         * Verificar horario.
         */
        $stmt = $pdo->prepare("
            SELECT id
            FROM horarios
            WHERE id = :id
              AND activo = 1
            LIMIT 1
        ");

        $stmt->execute([
            ':id' => $horarioId
        ]);

        if (!$stmt->fetch()) {
            http_response_code(422);

            echo json_encode([
                'ok' => false,
                'error' => 'El horario seleccionado no existe o está inactivo'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        /*
         * Verificar plan.
         */
        $stmt = $pdo->prepare("
            SELECT id
            FROM planes
            WHERE id = :id
              AND activo = 1
            LIMIT 1
        ");

        $stmt->execute([
            ':id' => $planId
        ]);

        if (!$stmt->fetch()) {
            http_response_code(422);

            echo json_encode([
                'ok' => false,
                'error' => 'El plan seleccionado no existe o está inactivo'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        /*
         * Generar UUID.
         */
        $id = $pdo->query("
            SELECT UUID()
        ")->fetchColumn();

        if (!$id) {
            throw new RuntimeException('No se pudo generar el UUID del alumno');
        }

        /*
         * Insertar alumno.
         */
        $stmt = $pdo->prepare("
            INSERT INTO alumnos (
                id,
                nombre,
                fecha_nacimiento,
                whatsapp,
                correo,
                fecha_inicio,
                horario_preferido_id,
                plan_actual_id,
                estado_administrativo,
                observaciones
            ) VALUES (
                :id,
                :nombre,
                :fecha_nacimiento,
                :whatsapp,
                :correo,
                :fecha_inicio,
                :horario_preferido_id,
                :plan_actual_id,
                :estado_administrativo,
                :observaciones
            )
        ");

        $stmt->execute([
            ':id' => $id,
            ':nombre' => $nombre,
            ':fecha_nacimiento' => $fechaNacimiento,
            ':whatsapp' => $whatsapp,
            ':correo' => $correo !== '' ? $correo : null,
            ':fecha_inicio' => $fechaInicio,
            ':horario_preferido_id' => $horarioId,
            ':plan_actual_id' => $planId,
            ':estado_administrativo' => $estado,
            ':observaciones' => $observaciones
        ]);

        /*
         * Recuperar alumno creado.
         */
        $stmt = $pdo->prepare("
            SELECT
                a.id,
                a.nombre,
                a.fecha_nacimiento,
                a.whatsapp,
                a.correo,
                a.fecha_inicio,
                a.horario_preferido_id,
                a.plan_actual_id,
                p.nombre AS plan_nombre,
                p.precio AS plan_precio,
                a.estado_administrativo,
                a.observaciones,
                a.created_at,
                a.updated_at
            FROM alumnos a
            LEFT JOIN planes p
                ON p.id = a.plan_actual_id
            WHERE a.id = :id
            LIMIT 1
        ");

        $stmt->execute([
            ':id' => $id
        ]);

        $alumno = $stmt->fetch();

        http_response_code(201);

        echo json_encode([
            'ok' => true,
            'mensaje' => 'Alumno creado correctamente',
            'alumno' => $alumno
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        exit;
    }

    http_response_code(405);

    echo json_encode([
        'ok' => false,
        'error' => 'Método no permitido'
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
