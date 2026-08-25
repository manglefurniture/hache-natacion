<?php
declare(strict_types=1);

function financiero_validar_periodo(string $periodo): string
{
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodo)) {
        throw new InvalidArgumentException('Periodo inválido');
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $periodo.'-01');
    if (!$date || $date->format('Y-m') !== $periodo) {
        throw new InvalidArgumentException('Periodo inválido');
    }
    return $periodo;
}

function financiero_periodo_anterior(string $periodo): string
{
    financiero_validar_periodo($periodo);
    return (new DateTimeImmutable($periodo.'-01'))->modify('-1 month')->format('Y-m');
}

function financiero_periodo_siguiente(string $periodo): string
{
    financiero_validar_periodo($periodo);
    return (new DateTimeImmutable($periodo.'-01'))->modify('+1 month')->format('Y-m');
}

function financiero_tabla_disponible(PDO $pdo): bool
{
    static $cache = [];
    $key = spl_object_id($pdo);
    if (array_key_exists($key, $cache)) return $cache[$key];
    $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='periodos_financieros'");
    return $cache[$key] = ((int)$stmt->fetchColumn() > 0);
}

function financiero_rango(PDO $pdo, string $sedeId, string $periodo): array
{
    financiero_validar_periodo($periodo);
    $periodDate = $periodo.'-01';
    if (!financiero_tabla_disponible($pdo)) {
        return [
            'periodo'=>$periodo,
            'inicio'=>$periodDate,
            'cierre'=>(new DateTimeImmutable($periodDate))->modify('last day of this month')->format('Y-m-d'),
            'personalizado'=>false,
        ];
    }
    $stmt = $pdo->prepare('SELECT fecha_inicio,fecha_cierre FROM periodos_financieros WHERE sede_id=:sede AND periodo=:periodo LIMIT 1');
    $stmt->execute([':sede'=>$sedeId, ':periodo'=>$periodDate]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $inicio = $row && !empty($row['fecha_inicio']) ? (string)$row['fecha_inicio'] : null;
    $cierre = $row && !empty($row['fecha_cierre']) ? (string)$row['fecha_cierre'] : null;

    if ($inicio === null) {
        $prev = financiero_periodo_anterior($periodo).'-01';
        $stmt = $pdo->prepare('SELECT fecha_cierre FROM periodos_financieros WHERE sede_id=:sede AND periodo=:periodo LIMIT 1');
        $stmt->execute([':sede'=>$sedeId, ':periodo'=>$prev]);
        $prevClose = $stmt->fetchColumn();
        $inicio = $prevClose
            ? (new DateTimeImmutable((string)$prevClose))->modify('+1 day')->format('Y-m-d')
            : $periodDate;
    }

    if ($cierre === null) {
        $next = financiero_periodo_siguiente($periodo).'-01';
        $stmt = $pdo->prepare('SELECT fecha_inicio FROM periodos_financieros WHERE sede_id=:sede AND periodo=:periodo LIMIT 1');
        $stmt->execute([':sede'=>$sedeId, ':periodo'=>$next]);
        $nextStart = $stmt->fetchColumn();
        $cierre = $nextStart
            ? (new DateTimeImmutable((string)$nextStart))->modify('-1 day')->format('Y-m-d')
            : (new DateTimeImmutable($periodDate))->modify('last day of this month')->format('Y-m-d');
    }

    if ($cierre < $inicio) {
        throw new RuntimeException('La configuración del periodo financiero es inconsistente');
    }

    return [
        'periodo'=>$periodo,
        'inicio'=>$inicio,
        'cierre'=>$cierre,
        'personalizado'=>($inicio !== $periodDate || $cierre !== (new DateTimeImmutable($periodDate))->modify('last day of this month')->format('Y-m-d')),
    ];
}

function financiero_periodo_para_fecha(PDO $pdo, string $sedeId, string $fecha): string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
    if (!$date || $date->format('Y-m-d') !== $fecha) {
        throw new InvalidArgumentException('Fecha inválida');
    }
    $calendar = $date->format('Y-m');
    foreach ([financiero_periodo_anterior($calendar), $calendar, financiero_periodo_siguiente($calendar)] as $periodo) {
        $rango = financiero_rango($pdo, $sedeId, $periodo);
        if ($fecha >= $rango['inicio'] && $fecha <= $rango['cierre']) {
            return $periodo;
        }
    }
    return $calendar;
}

function financiero_totales(PDO $pdo, array $sede, string $periodo): array
{
    financiero_validar_periodo($periodo);
    [$anio,$mes] = array_map('intval', explode('-', $periodo));
    $rango = financiero_rango($pdo, (string)$sede['id'], $periodo);
    $sql = "SELECT COUNT(*) pagos_count,
                   COALESCE(SUM(p.importe),0) total,
                   COALESCE(SUM(p.tipo='INSCRIPCION'),0) inscripciones_count,
                   COALESCE(SUM(CASE WHEN p.tipo='INSCRIPCION' THEN p.importe ELSE 0 END),0) inscripciones_total,
                   COALESCE(SUM(p.tipo='MENSUALIDAD'),0) mensualidades_count,
                   COALESCE(SUM(CASE WHEN p.tipo='MENSUALIDAD' THEN p.importe ELSE 0 END),0) mensualidades_total,
                   COALESCE(SUM(p.tipo='INTENSIVO'),0) intensivos_count,
                   COALESCE(SUM(CASE WHEN p.tipo='INTENSIVO' THEN p.importe ELSE 0 END),0) intensivos_total
            FROM pagos p
            LEFT JOIN mensualidades m ON m.id=p.mensualidad_id
            LEFT JOIN inscripciones i ON i.id=p.inscripcion_id
            LEFT JOIN cursos_intensivos ci ON ci.id=p.intensivo_id
            WHERE p.estado='VALIDO' AND (
              (p.tipo='MENSUALIDAD' AND m.sede_id=:sm AND m.mes=:mes AND m.anio=:anio)
              OR (p.tipo='INSCRIPCION' AND i.sede_id=:si AND i.fecha BETWEEN :di AND :hi)
              OR (p.tipo='INTENSIVO' AND ci.sede_id=:sc AND ci.fecha_inicio BETWEEN :dc AND :hc)
            )";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':sm'=>$sede['id'], ':mes'=>$mes, ':anio'=>$anio,
        ':si'=>$sede['id'], ':di'=>$rango['inicio'], ':hi'=>$rango['cierre'],
        ':sc'=>$sede['id'], ':dc'=>$rango['inicio'], ':hc'=>$rango['cierre'],
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return $row + ['rango'=>$rango];
}
