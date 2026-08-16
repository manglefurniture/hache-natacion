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
     * ==========================================================
     * GET
     * Devuelve:
     * - datos del curso
     * - alumnos inscritos
     * - horarios disponibles
     * ==========================================================
     */
    if ($method === 'GET') {

        $cursoId =
            trim((string)($_GET['curso_id'] ?? ''));

        if ($cursoId === '') {

            http_response_code(422);

            echo json_encode([
                'ok' => false,
                'error' => 'curso_id es obligatorio'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        /*
         * Curso.
         */
        $stmt = $pdo->prepare("
            SELECT
                id,
                fecha_inicio,
                fecha_fin,
                precio,
                estado,
                observaciones,
                created_at
            FROM cursos_intensivos
            WHERE id = :id
            LIMIT 1
        ");

        $stmt->execute([
            ':id' => $cursoId
        ]);

        $curso = $stmt->fetch();

        if (!$curso) {

            http_response_code(404);

            echo json_encode([
                'ok' => false,
                'error' => 'El curso intensivo no existe'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        /*
         * Alumnos inscritos.
         */
        $stmt = $pdo->prepare("
            SELECT
                cia.id AS inscripcion_intensivo_id,
                cia.curso_intensivo_id,
                cia.alumno_id,
                a.nombre AS alumno_nombre,
                cia.horario_id,
                h.hora_inicio,
                h.hora_fin,
                cia.reposiciones_justificadas,
                cia.reposiciones_cancelacion,
                cia.continua_regular,
                cia.plan_continuidad_id,
                cia.importe_continuidad,
                cia.observacion_continuidad,
                cia.observaciones,
                cia.created_at
            FROM curso_intensivo_alumnos cia
            INNER JOIN alumnos a
                ON a.id = cia.alumno_id
            INNER JOIN horarios h
                ON h.id = cia.horario_id
            WHERE cia.curso_intensivo_id = :curso_id
            ORDER BY
                h.hora_inicio ASC,
                a.nombre ASC
        ");

        $stmt->execute([
            ':curso_id' => $cursoId
        ]);

        $alumnosCurso =
            $stmt->fetchAll();

        /*
         * Horarios disponibles para intensivos.
         */
        $stmt = $pdo->query("
            SELECT
                id,
                hora_inicio,
                hora_fin
            FROM horarios
            WHERE activo = 1
              AND intensivo = 1
            ORDER BY hora_inicio ASC
        ");

        $horarios =
            $stmt->fetchAll();

        echo json_encode([
            'ok' => true,
            'curso' => $curso,
            'total_alumnos' => count($alumnosCurso),
            'alumnos' => $alumnosCurso,
            'horarios' => $horarios
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        exit;
    }

    /*
     * ==========================================================
     * POST
     * Agrega un alumno a un curso intensivo.
     * ==========================================================
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

        $cursoId =
            trim((string)($input['curso_intensivo_id'] ?? ''));

        $alumnoId =
            trim((string)($input['alumno_id'] ?? ''));

        $horarioId =
            trim((string)($input['horario_id'] ?? ''));

        $observaciones =
            $input['observaciones'] ?? null;

        $createdBy =
            trim((string)($input['created_by'] ?? ''));

        if ($cursoId === '') {

            http_response_code(422);

            echo json_encode([
                'ok' => false,
                'error' => 'El curso es obligatorio'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        if ($alumnoId === '') {

            http_response_code(422);

            echo json_encode([
                'ok' => false,
                'error' => 'Selecciona un alumno'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        if ($horarioId === '') {

            http_response_code(422);

            echo json_encode([
                'ok' => false,
                'error' => 'Selecciona un horario'
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

        /*
         * Verificar usuario.
         */
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

        /*
         * Verificar curso.
         */
        $stmt = $pdo->prepare("
            SELECT id, estado
            FROM cursos_intensivos
            WHERE id = :id
            LIMIT 1
        ");

        $stmt->execute([
            ':id' => $cursoId
        ]);

        $curso = $stmt->fetch();

        if (!$curso) {

            http_response_code(422);

            echo json_encode([
                'ok' => false,
                'error' => 'El curso intensivo no existe'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        if ($curso['estado'] === 'TERMINADO') {

            http_response_code(422);

            echo json_encode([
                'ok' => false,
                'error' => 'No se pueden agregar alumnos a un curso terminado'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        /*
         * Verificar alumno.
         */
        $stmt = $pdo->prepare("
            SELECT id
            FROM alumnos
            WHERE id = :id
            LIMIT 1
        ");

        $stmt->execute([
            ':id' => $alumnoId
        ]);

        if (!$stmt->fetch()) {

            http_response_code(422);

            echo json_encode([
                'ok' => false,
                'error' => 'El alumno seleccionado no existe'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        /*
         * Verificar horario intensivo.
         */
        $stmt = $pdo->prepare("
            SELECT id
            FROM horarios
            WHERE id = :id
              AND activo = 1
              AND intensivo = 1
            LIMIT 1
        ");

        $stmt->execute([
            ':id' => $horarioId
        ]);

        if (!$stmt->fetch()) {

            http_response_code(422);

            echo json_encode([
                'ok' => false,
                'error' => 'El horario seleccionado no está disponible para intensivos'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        /*
         * Evitar duplicar el mismo alumno dentro del mismo curso.
         */
        $stmt = $pdo->prepare("
            SELECT id
            FROM curso_intensivo_alumnos
            WHERE curso_intensivo_id = :curso_id
              AND alumno_id = :alumno_id
            LIMIT 1
        ");

        $stmt->execute([
            ':curso_id' => $cursoId,
            ':alumno_id' => $alumnoId
        ]);

        if ($stmt->fetch()) {

            http_response_code(422);

            echo json_encode([
                'ok' => false,
                'error' => 'El alumno ya está inscrito en este curso intensivo'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        /*
         * Crear relación alumno ↔ curso.
         */
        $id = $pdo->query("
            SELECT UUID()
        ")->fetchColumn();

        $stmt = $pdo->prepare("
            INSERT INTO curso_intensivo_alumnos (
                id,
                curso_intensivo_id,
                alumno_id,
                horario_id,
                observaciones,
                created_by
            )
            VALUES (
                :id,
                :curso_intensivo_id,
                :alumno_id,
                :horario_id,
                :observaciones,
                :created_by
            )
        ");

        $stmt->execute([
            ':id' => $id,
            ':curso_intensivo_id' => $cursoId,
            ':alumno_id' => $alumnoId,
            ':horario_id' => $horarioId,
            ':observaciones' => $observaciones,
            ':created_by' => $createdBy
        ]);

        http_response_code(201);

        echo json_encode([
            'ok' => true,
            'mensaje' => 'Alumno agregado al curso intensivo correctamente',
            'id' => $id
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
