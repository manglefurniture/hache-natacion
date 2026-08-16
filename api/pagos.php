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
                p.id,
                p.folio,
                p.alumno_id,
                a.nombre AS alumno_nombre,
                p.inscripcion_id,
                p.mensualidad_id,
                p.intensivo_id,
                p.tipo,
                p.importe,
                p.metodo,
                p.fecha,
                p.estado,
                p.observacion,
                p.created_by,
                p.created_at
            FROM pagos p
            INNER JOIN alumnos a ON a.id = p.alumno_id
            ORDER BY p.fecha DESC, p.folio DESC
        ");

        $pagos = $stmt->fetchAll();

        echo json_encode([
            'ok' => true,
            'total' => count($pagos),
            'pagos' => $pagos
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

        $alumnoId = trim((string)($input['alumno_id'] ?? ''));
        $tipo = strtoupper(trim((string)($input['tipo'] ?? '')));
        $importe = $input['importe'] ?? null;
        $metodo = strtoupper(trim((string)($input['metodo'] ?? '')));
        $fecha = trim((string)($input['fecha'] ?? ''));
        $observacion = $input['observacion'] ?? null;
        $createdBy = trim((string)($input['created_by'] ?? ''));
        $cursoIntensivoId = trim((string)($input['curso_intensivo_id'] ?? ''));

        if ($alumnoId === '') {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'El alumno es obligatorio'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (!in_array($tipo, ['INSCRIPCION', 'MENSUALIDAD', 'INTENSIVO'], true)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Tipo de pago inválido'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($importe === null || !is_numeric($importe) || (float)$importe < 0) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'El importe es inválido'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (!in_array($metodo, ['EFECTIVO', 'TRANSFERENCIA', 'MERCADO_PAGO'], true)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Método de pago inválido'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($fecha === '') {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'La fecha es obligatoria'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($createdBy === '') {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'No existe usuario administrativo'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $createdBy]);
        if (!$stmt->fetch()) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'El usuario administrativo no existe'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT
                a.id,
                a.nombre,
                a.plan_actual_id,
                a.horario_preferido_id,
                p.nombre AS plan_nombre,
                p.precio AS plan_precio
            FROM alumnos a
            LEFT JOIN planes p ON p.id = a.plan_actual_id
            WHERE a.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $alumnoId]);
        $alumno = $stmt->fetch();

        if (!$alumno) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'El alumno seleccionado no existe'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        try {
            $fechaPago = new DateTime($fecha);
        } catch (Throwable $e) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'La fecha del pago no es válida'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $fechaSql = $fechaPago->format('Y-m-d H:i:s');
        $fechaDia = $fechaPago->format('Y-m-d');
        $mes = (int)$fechaPago->format('n');
        $anio = (int)$fechaPago->format('Y');
        $importeDecimal = number_format((float)$importe, 2, '.', '');

        $inscripcionId = null;
        $mensualidadId = null;
        $intensivoId = null;

        $pdo->beginTransaction();

        if ($tipo === 'INSCRIPCION') {
            $inscripcionId = $pdo->query("SELECT UUID()")->fetchColumn();
            $stmt = $pdo->prepare("
                INSERT INTO inscripciones (
                    id, alumno_id, fecha, origen, importe, observacion, created_by
                ) VALUES (
                    :id, :alumno_id, :fecha, 'REGULAR', :importe, :observacion, :created_by
                )
            ");
            $stmt->execute([
                ':id' => $inscripcionId,
                ':alumno_id' => $alumnoId,
                ':fecha' => $fechaDia,
                ':importe' => $importeDecimal,
                ':observacion' => $observacion,
                ':created_by' => $createdBy
            ]);
        } elseif ($tipo === 'MENSUALIDAD') {
            if (empty($alumno['plan_actual_id'])) {
                throw new RuntimeException('El alumno no tiene un plan activo');
            }

            $stmt = $pdo->prepare("
                SELECT id, estado
                FROM mensualidades
                WHERE alumno_id = :alumno_id AND mes = :mes AND anio = :anio
                LIMIT 1
            ");
            $stmt->execute([
                ':alumno_id' => $alumnoId,
                ':mes' => $mes,
                ':anio' => $anio
            ]);
            $mensualidadExistente = $stmt->fetch();

            if ($mensualidadExistente) {
                if ($mensualidadExistente['estado'] === 'PAGADA') {
                    throw new RuntimeException('La mensualidad de este mes ya está pagada');
                }

                $mensualidadId = $mensualidadExistente['id'];
                $stmt = $pdo->prepare("
                    UPDATE mensualidades
                    SET importe_cobrado = :importe_cobrado,
                        estado = 'PAGADA',
                        fecha_pago = :fecha_pago,
                        observacion = :observacion,
                        updated_at = NOW()
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':importe_cobrado' => $importeDecimal,
                    ':fecha_pago' => $fechaSql,
                    ':observacion' => $observacion,
                    ':id' => $mensualidadId
                ]);
            } else {
                $mensualidadId = $pdo->query("SELECT UUID()")->fetchColumn();
                $importeEstandar = number_format((float)$alumno['plan_precio'], 2, '.', '');
                $observacionMensualidad = $observacion;

                if ((float)$importeDecimal !== (float)$importeEstandar && empty($observacionMensualidad)) {
                    $observacionMensualidad = 'Importe diferente al precio estándar del plan';
                }

                $stmt = $pdo->prepare("
                    INSERT INTO mensualidades (
                        id, alumno_id, mes, anio, plan_id,
                        importe_estandar, importe_a_cobrar, importe_cobrado,
                        estado, observacion, fecha_pago, created_by
                    ) VALUES (
                        :id, :alumno_id, :mes, :anio, :plan_id,
                        :importe_estandar, :importe_a_cobrar, :importe_cobrado,
                        'PAGADA', :observacion, :fecha_pago, :created_by
                    )
                ");
                $stmt->execute([
                    ':id' => $mensualidadId,
                    ':alumno_id' => $alumnoId,
                    ':mes' => $mes,
                    ':anio' => $anio,
                    ':plan_id' => $alumno['plan_actual_id'],
                    ':importe_estandar' => $importeEstandar,
                    ':importe_a_cobrar' => $importeDecimal,
                    ':importe_cobrado' => $importeDecimal,
                    ':observacion' => $observacionMensualidad,
                    ':fecha_pago' => $fechaSql,
                    ':created_by' => $createdBy
                ]);
            }
        } elseif ($tipo === 'INTENSIVO') {
            if ($cursoIntensivoId !== '') {
                $stmt = $pdo->prepare("
                    SELECT ci.id
                    FROM curso_intensivo_alumnos cia
                    INNER JOIN cursos_intensivos ci ON ci.id = cia.curso_intensivo_id
                    WHERE cia.alumno_id = :alumno_id
                      AND cia.curso_intensivo_id = :curso_id
                      AND ci.estado IN ('PROGRAMADO', 'EN_CURSO')
                    LIMIT 1
                ");
                $stmt->execute([
                    ':alumno_id' => $alumnoId,
                    ':curso_id' => $cursoIntensivoId
                ]);
            } else {
                $stmt = $pdo->prepare("
                    SELECT ci.id
                    FROM curso_intensivo_alumnos cia
                    INNER JOIN cursos_intensivos ci ON ci.id = cia.curso_intensivo_id
                    WHERE cia.alumno_id = :alumno_id
                      AND ci.estado IN ('PROGRAMADO', 'EN_CURSO')
                    ORDER BY ci.fecha_inicio DESC
                    LIMIT 1
                ");
                $stmt->execute([':alumno_id' => $alumnoId]);
            }

            $curso = $stmt->fetch();
            if (!$curso) {
                throw new RuntimeException('El alumno no está inscrito en un curso intensivo programado o en curso');
            }

            $intensivoId = $curso['id'];

            $stmt = $pdo->prepare("
                SELECT id
                FROM pagos
                WHERE intensivo_id = :intensivo_id
                  AND alumno_id = :alumno_id
                  AND tipo = 'INTENSIVO'
                  AND estado = 'VALIDO'
                LIMIT 1
            ");
            $stmt->execute([
                ':intensivo_id' => $intensivoId,
                ':alumno_id' => $alumnoId
            ]);

            if ($stmt->fetch()) {
                throw new RuntimeException('Este alumno ya tiene registrado el pago de este curso intensivo');
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO pagos (
                alumno_id, inscripcion_id, mensualidad_id, intensivo_id,
                tipo, importe, metodo, fecha, estado, observacion, created_by
            ) VALUES (
                :alumno_id, :inscripcion_id, :mensualidad_id, :intensivo_id,
                :tipo, :importe, :metodo, :fecha, 'VALIDO', :observacion, :created_by
            )
        ");
        $stmt->execute([
            ':alumno_id' => $alumnoId,
            ':inscripcion_id' => $inscripcionId,
            ':mensualidad_id' => $mensualidadId,
            ':intensivo_id' => $intensivoId,
            ':tipo' => $tipo,
            ':importe' => $importeDecimal,
            ':metodo' => $metodo,
            ':fecha' => $fechaSql,
            ':observacion' => $observacion,
            ':created_by' => $createdBy
        ]);

        $folio = (int)$pdo->lastInsertId();
        $pdo->commit();

        $stmt = $pdo->prepare("
            SELECT
                p.id, p.folio, p.alumno_id, a.nombre AS alumno_nombre,
                p.inscripcion_id, p.mensualidad_id, p.intensivo_id,
                p.tipo, p.importe, p.metodo, p.fecha, p.estado,
                p.observacion, p.created_by, p.created_at
            FROM pagos p
            INNER JOIN alumnos a ON a.id = p.alumno_id
            WHERE p.folio = :folio
            LIMIT 1
        ");
        $stmt->execute([':folio' => $folio]);
        $pago = $stmt->fetch();

        http_response_code(201);
        echo json_encode([
            'ok' => true,
            'mensaje' => 'Pago registrado correctamente',
            'pago' => $pago
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
