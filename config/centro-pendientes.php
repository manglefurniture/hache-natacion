<?php
declare(strict_types=1);

/*
 * Centro de pendientes: capa de lectura y gestión sobre fuentes existentes.
 *
 * Esta capa no crea obligaciones ni cambia pagos, alumnos, asistencias o
 * reposiciones. Las funciones de fuente se limitan a reutilizar las reglas de
 * acceso existentes y consultas SELECT sobre los registros de origen.
 */

require_once __DIR__.'/reglas-acceso.php';

const CENTRO_PENDIENTES_TIPOS_HABILITADOS = [
    'MENSUALIDAD_REGULAR_SIN_COBERTURA',
    'INSCRIPCION_REGULAR_SIN_COBERTURA',
    'REPOSICION_REGULAR_DISPONIBLE',
    'SALDO_INTENSIVO_PENDIENTE',
];

function centro_pendientes_identidad(string $tipo, string $origenTipo, string $origenId, ?string $periodoInicio = null, ?string $periodoFin = null): string
{
    return hash('sha256', implode('|', [
        strtoupper(trim($tipo)),
        strtoupper(trim($origenTipo)),
        trim($origenId),
        $periodoInicio ?? '',
        $periodoFin ?? '',
    ]));
}

function centro_pendientes_origen_intensivo(string $cursoId, string $alumnoId): string
{
    return trim($cursoId).':'.trim($alumnoId);
}

function centro_pendientes_parse_origen_intensivo(string $origenId): ?array
{
    $partes = explode(':', trim($origenId), 2);
    if (count($partes) !== 2 || $partes[0] === '' || $partes[1] === '') {
        return null;
    }
    return ['curso_id'=>$partes[0], 'alumno_id'=>$partes[1]];
}

function centro_pendientes_mismo_alcance(array $pendiente, string $sedeId): bool
{
    return hash_equals((string)($pendiente['sede_id'] ?? ''), $sedeId);
}

function centro_pendientes_estado_efectivo(?array $gestion, bool $causaActiva): string
{
    if (!$causaActiva) {
        return 'RESUELTO';
    }
    $estado = strtoupper((string)($gestion['estado'] ?? 'PENDIENTE'));
    return $estado === 'ATENDIDO' ? 'ATENDIDO' : 'PENDIENTE';
}

function centro_pendientes_puede_resolver(bool $causaActiva): bool
{
    return !$causaActiva;
}

function centro_pendientes_gestion_atendida(array $pendiente, array $usuario, ?string $nota, string $atendidoAt): array
{
    return [
        'estado' => 'ATENDIDO',
        'atendido_por' => (string)$usuario['id'],
        'atendido_por_nombre' => (string)($usuario['usuario'] ?? ''),
        'atendido_at' => $atendidoAt,
        'atencion_nota' => $nota,
        'identidad' => (string)$pendiente['identidad'],
    ];
}

function centro_pendientes_indizar(array $pendientes): array
{
    $porIdentidad = [];
    foreach ($pendientes as $pendiente) {
        $identidad = (string)($pendiente['identidad'] ?? '');
        if ($identidad === '') {
            continue;
        }
        $porIdentidad[$identidad] = $pendiente;
    }
    return $porIdentidad;
}

function centro_pendientes_url(string $tipo, string $alumnoId, ?string $cursoIntensivoId = null): string
{
    return match ($tipo) {
        'MENSUALIDAD_REGULAR_SIN_COBERTURA', 'INSCRIPCION_REGULAR_SIN_COBERTURA'
            => '/pagos.php?alumno_id='.rawurlencode($alumnoId),
        'SALDO_INTENSIVO_PENDIENTE'
            => '/pagos.php?alumno_id='.rawurlencode($alumnoId).'&tipo=INTENSIVO'
                .($cursoIntensivoId !== null && $cursoIntensivoId !== '' ? '&curso_intensivo_id='.rawurlencode($cursoIntensivoId) : ''),
        'REPOSICION_REGULAR_DISPONIBLE' => '/ausencias.php?alerta=reposiciones',
        default => '/dashboard.php',
    };
}

function centro_pendientes_descripcion_tipo(string $tipo): string
{
    return match ($tipo) {
        'MENSUALIDAD_REGULAR_SIN_COBERTURA' => 'Mensualidad regular sin cobertura',
        'INSCRIPCION_REGULAR_SIN_COBERTURA' => 'Inscripción regular sin cobertura',
        'REPOSICION_REGULAR_DISPONIBLE' => 'Reposición regular disponible',
        'SALDO_INTENSIVO_PENDIENTE' => 'Saldo de intensivo pendiente',
        default => 'Pendiente administrativo',
    };
}

function centro_pendientes_agregar(array &$pendientes, array $data): void
{
    $data['identidad'] = centro_pendientes_identidad(
        (string)$data['tipo'],
        (string)$data['origen_tipo'],
        (string)$data['origen_id'],
        $data['periodo_inicio'] ?? null,
        $data['periodo_fin'] ?? null,
    );
    $pendientes[] = $data;
}

/**
 * Devuelve exclusivamente asuntos cuya causa sigue vigente en las fuentes.
 * No reutiliza endpoints GET porque algunos de ellos pueden reconciliar datos.
 */
function centro_pendientes_fuentes_activas(PDO $pdo, string $sedeId, string $sedeClave, string $sedeNombre): array
{
    $pendientes = [];
    $alumnos = $pdo->prepare("SELECT a.id,a.nombre,a.ciclo_pago,a.fecha_inicio
        FROM alumnos a
        WHERE a.sede_id=:sede
          AND a.plan_actual_id IS NOT NULL
          AND a.estado_administrativo<>'BAJA'
          AND NOT EXISTS(
              SELECT 1
              FROM curso_intensivo_alumnos cia
              INNER JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id
              WHERE cia.alumno_id=a.id
                AND ci.sede_id=:sede_intensivo
                AND ci.estado IN ('PROGRAMADO','EN_CURSO')
          )
        ORDER BY a.nombre,a.id");
    $alumnos->execute([':sede'=>$sedeId, ':sede_intensivo'=>$sedeId]);
    foreach ($alumnos as $alumno) {
        $alumnoId = (string)$alumno['id'];
        $periodo = regla_periodo_regular_actual($sedeClave, $alumno['ciclo_pago'] !== null ? (string)$alumno['ciclo_pago'] : null);
        if (!regla_mensualidad_regular_cubierta($pdo, $alumnoId, $sedeId, $sedeClave, $alumno['ciclo_pago'] !== null ? (string)$alumno['ciclo_pago'] : null)) {
            centro_pendientes_agregar($pendientes, [
                'tipo' => 'MENSUALIDAD_REGULAR_SIN_COBERTURA',
                'origen_tipo' => 'ALUMNO_REGULAR',
                'origen_id' => $alumnoId,
                'alumno_id' => $alumnoId,
                'alumno_nombre' => (string)$alumno['nombre'],
                'sede_id' => $sedeId,
                'sede_nombre' => $sedeNombre,
                'periodo_inicio' => $periodo['inicio'],
                'periodo_fin' => $periodo['fin'],
                'fecha_referencia' => $periodo['inicio'],
                'explicacion' => 'La regla vigente no registra una mensualidad PAGADA para este período.',
                'href' => centro_pendientes_url('MENSUALIDAD_REGULAR_SIN_COBERTURA', $alumnoId),
                'causa_activa' => true,
            ]);
        }
        if (!regla_inscripcion_regular_cubierta($pdo, $alumnoId, $sedeId, $sedeClave)) {
            centro_pendientes_agregar($pendientes, [
                'tipo' => 'INSCRIPCION_REGULAR_SIN_COBERTURA',
                'origen_tipo' => 'ALUMNO_REGULAR',
                'origen_id' => $alumnoId,
                'alumno_id' => $alumnoId,
                'alumno_nombre' => (string)$alumno['nombre'],
                'sede_id' => $sedeId,
                'sede_nombre' => $sedeNombre,
                'periodo_inicio' => null,
                'periodo_fin' => null,
                'fecha_referencia' => $alumno['fecha_inicio'] !== null ? (string)$alumno['fecha_inicio'] : null,
                'explicacion' => 'La regla vigente no encuentra una inscripción cubierta para este alumno.',
                'href' => centro_pendientes_url('INSCRIPCION_REGULAR_SIN_COBERTURA', $alumnoId),
                'causa_activa' => true,
            ]);
        }
    }

    $intensivos = $pdo->prepare("SELECT ci.id curso_id,cia.alumno_id,a.nombre,ci.fecha_inicio,ci.fecha_fin,ci.precio,
            COALESCE(SUM(CASE WHEN p.estado='VALIDO' THEN p.importe ELSE 0 END),0) pagado_valido
        FROM curso_intensivo_alumnos cia
        INNER JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id
        INNER JOIN alumnos a ON a.id=cia.alumno_id
        LEFT JOIN pagos p ON p.intensivo_id=ci.id AND p.alumno_id=cia.alumno_id AND p.tipo='INTENSIVO'
        WHERE ci.sede_id=:sede
          AND ci.estado IN ('PROGRAMADO','EN_CURSO','TERMINADO')
        GROUP BY ci.id,cia.alumno_id,a.nombre,ci.fecha_inicio,ci.fecha_fin,ci.precio
        HAVING COALESCE(SUM(CASE WHEN p.estado='VALIDO' THEN p.importe ELSE 0 END),0)+0.009<ci.precio
        ORDER BY ci.fecha_inicio,a.nombre,ci.id,cia.alumno_id");
    $intensivos->execute([':sede'=>$sedeId]);
    foreach ($intensivos as $intensivo) {
        $cursoId = (string)$intensivo['curso_id'];
        $alumnoId = (string)$intensivo['alumno_id'];
        $total = round((float)$intensivo['precio'], 2);
        $pagado = round((float)$intensivo['pagado_valido'], 2);
        $saldo = max(0.0, round($total - $pagado, 2));
        centro_pendientes_agregar($pendientes, [
            'tipo' => 'SALDO_INTENSIVO_PENDIENTE',
            'origen_tipo' => 'CURSO_INTENSIVO_ALUMNO',
            'origen_id' => centro_pendientes_origen_intensivo($cursoId, $alumnoId),
            'alumno_id' => $alumnoId,
            'alumno_nombre' => (string)$intensivo['nombre'],
            'sede_id' => $sedeId,
            'sede_nombre' => $sedeNombre,
            'periodo_inicio' => (string)$intensivo['fecha_inicio'],
            'periodo_fin' => (string)$intensivo['fecha_fin'],
            'fecha_referencia' => (string)$intensivo['fecha_inicio'],
            'importe_total' => $total,
            'importe_pagado' => $pagado,
            'saldo' => $saldo,
            'explicacion' => 'Saldo pendiente de $'.number_format($saldo, 2, '.', ',').' MXN; pagos válidos acumulados $'.number_format($pagado, 2, '.', ',').' de $'.number_format($total, 2, '.', ',').' MXN.',
            'href' => centro_pendientes_url('SALDO_INTENSIVO_PENDIENTE', $alumnoId, $cursoId),
            'causa_activa' => true,
        ]);
    }

    $reposiciones = $pdo->prepare("SELECT rr.id,rr.alumno_id,rr.created_at,a.nombre
        FROM reposiciones_regulares rr
        INNER JOIN alumnos a ON a.id=rr.alumno_id
        WHERE rr.estado='DISPONIBLE' AND a.sede_id=:sede
        ORDER BY rr.created_at,a.nombre,rr.id");
    $reposiciones->execute([':sede'=>$sedeId]);
    foreach ($reposiciones as $reposicion) {
        centro_pendientes_agregar($pendientes, [
            'tipo' => 'REPOSICION_REGULAR_DISPONIBLE',
            'origen_tipo' => 'REPOSICION_REGULAR',
            'origen_id' => (string)$reposicion['id'],
            'alumno_id' => (string)$reposicion['alumno_id'],
            'alumno_nombre' => (string)$reposicion['nombre'],
            'sede_id' => $sedeId,
            'sede_nombre' => $sedeNombre,
            'periodo_inicio' => null,
            'periodo_fin' => null,
            'fecha_referencia' => (string)$reposicion['created_at'],
            'explicacion' => 'La reposición regular continúa en estado DISPONIBLE.',
            'href' => centro_pendientes_url('REPOSICION_REGULAR_DISPONIBLE', (string)$reposicion['alumno_id']),
            'causa_activa' => true,
        ]);
    }

    return centro_pendientes_indizar($pendientes);
}

/**
 * Revalida el registro de dominio antes de que una acción pueda resolverlo.
 */
function centro_pendientes_causa_activa(PDO $pdo, array $pendiente, string $sedeId, string $sedeClave): bool
{
    if (!centro_pendientes_mismo_alcance($pendiente, $sedeId)) {
        return false;
    }
    $tipo = (string)($pendiente['tipo'] ?? '');
    $origenId = (string)($pendiente['origen_id'] ?? '');
    if ($tipo === 'REPOSICION_REGULAR_DISPONIBLE') {
        $st = $pdo->prepare("SELECT 1
            FROM reposiciones_regulares rr
            INNER JOIN alumnos a ON a.id=rr.alumno_id
            WHERE rr.id=:id AND rr.estado='DISPONIBLE' AND a.sede_id=:sede
            LIMIT 1");
        $st->execute([':id'=>$origenId, ':sede'=>$sedeId]);
        return (bool)$st->fetchColumn();
    }
    if ($tipo === 'SALDO_INTENSIVO_PENDIENTE') {
        $origen = centro_pendientes_parse_origen_intensivo($origenId);
        if (!$origen || ((string)($pendiente['alumno_id'] ?? '') !== '' && (string)$pendiente['alumno_id'] !== $origen['alumno_id'])) {
            return false;
        }
        $st = $pdo->prepare("SELECT 1
            FROM curso_intensivo_alumnos cia
            INNER JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id
            LEFT JOIN pagos p ON p.intensivo_id=ci.id AND p.alumno_id=cia.alumno_id AND p.tipo='INTENSIVO'
            WHERE ci.id=:curso AND cia.alumno_id=:alumno AND ci.sede_id=:sede
              AND ci.estado IN ('PROGRAMADO','EN_CURSO','TERMINADO')
            GROUP BY ci.id,cia.alumno_id,ci.precio
            HAVING COALESCE(SUM(CASE WHEN p.estado='VALIDO' THEN p.importe ELSE 0 END),0)+0.009<ci.precio
            LIMIT 1");
        $st->execute([':curso'=>$origen['curso_id'], ':alumno'=>$origen['alumno_id'], ':sede'=>$sedeId]);
        return (bool)$st->fetchColumn();
    }

    if (!in_array($tipo, ['MENSUALIDAD_REGULAR_SIN_COBERTURA', 'INSCRIPCION_REGULAR_SIN_COBERTURA'], true)) {
        return false;
    }
    $st = $pdo->prepare("SELECT a.id,a.ciclo_pago,a.plan_actual_id,a.estado_administrativo
        FROM alumnos a
        WHERE a.id=:id AND a.sede_id=:sede
        LIMIT 1");
    $st->execute([':id'=>$origenId, ':sede'=>$sedeId]);
    $alumno = $st->fetch();
    if (!$alumno || $alumno['plan_actual_id'] === null || $alumno['estado_administrativo'] === 'BAJA') {
        return false;
    }
    $intensivo = $pdo->prepare("SELECT 1
        FROM curso_intensivo_alumnos cia
        INNER JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id
        WHERE cia.alumno_id=:alumno AND ci.sede_id=:sede AND ci.estado IN ('PROGRAMADO','EN_CURSO')
        LIMIT 1");
    $intensivo->execute([':alumno'=>$origenId, ':sede'=>$sedeId]);
    if ($intensivo->fetchColumn()) {
        return false;
    }
    if ($tipo === 'INSCRIPCION_REGULAR_SIN_COBERTURA') {
        return !regla_inscripcion_regular_cubierta($pdo, $origenId, $sedeId, $sedeClave);
    }
    $inicio = trim((string)($pendiente['periodo_inicio'] ?? ''));
    $fin = trim((string)($pendiente['periodo_fin'] ?? ''));
    if ($inicio === '' || $fin === '') {
        return false;
    }
    try {
        $referencia = new DateTimeImmutable($inicio);
    } catch (Throwable) {
        return false;
    }
    $periodo = regla_periodo_regular_actual($sedeClave, $alumno['ciclo_pago'] !== null ? (string)$alumno['ciclo_pago'] : null, $referencia);
    if ($periodo['inicio'] !== $inicio || $periodo['fin'] !== $fin) {
        return false;
    }
    return !regla_mensualidad_regular_cubierta($pdo, $origenId, $sedeId, $sedeClave, $alumno['ciclo_pago'] !== null ? (string)$alumno['ciclo_pago'] : null, $referencia);
}

function centro_pendientes_historico(PDO $pdo, string $sedeId): array
{
    $st = $pdo->prepare("SELECT pg.*,a.nombre alumno_nombre,s.nombre sede_nombre
        FROM pendientes_gestion pg
        LEFT JOIN alumnos a ON a.id=pg.alumno_id AND a.sede_id=pg.sede_id
        INNER JOIN sedes s ON s.id=pg.sede_id
        WHERE pg.sede_id=:sede
        ORDER BY pg.updated_at DESC,pg.created_at DESC");
    $st->execute([':sede'=>$sedeId]);
    return centro_pendientes_indizar($st->fetchAll(PDO::FETCH_ASSOC));
}
