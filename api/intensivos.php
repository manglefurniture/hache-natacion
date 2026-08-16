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

        $stmt = $pdo->query("
            SELECT
                ci.id,
                ci.fecha_inicio,
                ci.fecha_fin,
                ci.precio,
                ci.estado,
                ci.observaciones,
                ci.created_by,
                ci.created_at,
                COUNT(cia.id) AS total_alumnos
            FROM cursos_intensivos ci
            LEFT JOIN curso_intensivo_alumnos cia
                ON cia.curso_intensivo_id = ci.id
            GROUP BY
                ci.id,
                ci.fecha_inicio,
                ci.fecha_fin,
                ci.precio,
                ci.estado,
                ci.observaciones,
                ci.created_by,
                ci.created_at
            ORDER BY
                ci.fecha_inicio DESC
        ");

        $intensivos = $stmt->fetchAll();

        echo json_encode([
            'ok' => true,
            'total' => count($intensivos),
            'intensivos' => $intensivos
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        exit;
    }

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

        $fechaInicioTexto =
            trim((string)($input['fecha_inicio'] ?? ''));

        $precio =
            $input['precio'] ?? 1200;

        $observaciones =
            $input['observaciones'] ?? null;

        $createdBy =
            trim((string)($input['created_by'] ?? ''));

        if ($fechaInicioTexto === '') {

            http_response_code(422);

            echo json_encode([
                'ok' => false,
                'error' => 'Selecciona la fecha de inicio'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        if (!is_numeric($precio) || (float)$precio < 0) {

            http_response_code(422);

            echo json_encode([
                'ok' => false,
                'error' => 'El precio es inválido'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        if ($createdBy === '') {

            http_response_code(422);

            echo json_encode([
                'ok' => false,
                'error' => 'La sesión administrativa no está disponible'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        $fechaInicio = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $fechaInicioTexto
        );

        $erroresFecha =
            DateTimeImmutable::getLastErrors();

        if (
            !$fechaInicio ||
            (
                is_array($erroresFecha) &&
                (
                    $erroresFecha['warning_count'] > 0 ||
                    $erroresFecha['error_count'] > 0
                )
            )
        ) {

            http_response_code(422);

            echo json_encode([
                'ok' => false,
                'error' => 'La fecha de inicio no es válida'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        if ((int)$fechaInicio->format('N') !== 1) {

            http_response_code(422);

            echo json_encode([
                'ok' => false,
                'error' => 'Los cursos intensivos solo pueden iniciar en lunes'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        $fechaFin =
            $fechaInicio->modify('+18 days');

        $fechaInicioSql =
            $fechaInicio->format('Y-m-d');

        $fechaFinSql =
            $fechaFin->format('Y-m-d');

        $stmt = $pdo->prepare("
            SELECT id
            FROM usuarios
            WHERE id = :id
            LIMIT 1
        ");

        $stmt->execute([
            ':id' => $createdBy
        ]);

        if (!$stmt->fetch()) {

            http_response_code(422);

            echo json_encode([
                'ok' => false,
                'error' => 'El usuario administrativo no existe'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        $stmt = $pdo->prepare("
            SELECT id
            FROM cursos_intensivos
            WHERE fecha_inicio = :fecha_inicio
            LIMIT 1
        ");

        $stmt->execute([
            ':fecha_inicio' => $fechaInicioSql
        ]);

        if ($stmt->fetch()) {

            http_response_code(422);

            echo json_encode([
                'ok' => false,
                'error' => 'Ya existe un curso intensivo con esa fecha de inicio'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        $id = $pdo->query("
            SELECT UUID()
        ")->fetchColumn();

        if (!$id) {

            throw new RuntimeException(
                'No se pudo generar el ID del curso intensivo'
            );
        }

        $stmt = $pdo->prepare("
            INSERT INTO cursos_intensivos (
                id,
                fecha_inicio,
                fecha_fin,
                precio,
                estado,
                observaciones,
                created_by
            )
            VALUES (
                :id,
                :fecha_inicio,
                :fecha_fin,
                :precio,
                'PROGRAMADO',
                :observaciones,
                :created_by
            )
        ");

        $stmt->execute([
            ':id' => $id,
            ':fecha_inicio' => $fechaInicioSql,
            ':fecha_fin' => $fechaFinSql,
            ':precio' => number_format(
                (float)$precio,
                2,
                '.',
                ''
            ),
            ':observaciones' => $observaciones,
            ':created_by' => $createdBy
        ]);

        $stmt = $pdo->prepare("
            SELECT
                ci.id,
                ci.fecha_inicio,
                ci.fecha_fin,
                ci.precio,
                ci.estado,
                ci.observaciones,
                ci.created_by,
                ci.created_at,
                0 AS total_alumnos
            FROM cursos_intensivos ci
            WHERE ci.id = :id
            LIMIT 1
        ");

        $stmt->execute([
            ':id' => $id
        ]);

        $intensivo =
            $stmt->fetch();

        http_response_code(201);

        echo json_encode([
            'ok' => true,
            'mensaje' => 'Curso intensivo creado correctamente',
            'intensivo' => $intensivo
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
