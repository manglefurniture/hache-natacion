<?php

declare(strict_types=1);

function intensivo_hoy_operativo(?DateTimeImmutable $referencia = null): DateTimeImmutable
{
    return $referencia ?? new DateTimeImmutable('today', new DateTimeZone('America/Cancun'));
}

function intensivo_lunes_semana_actual(?DateTimeImmutable $referencia = null): DateTimeImmutable
{
    $hoy = intensivo_hoy_operativo($referencia);
    $diasDesdeLunes = (int)$hoy->format('N') - 1;
    return $diasDesdeLunes > 0 ? $hoy->modify('-'.$diasDesdeLunes.' days') : $hoy;
}

function intensivo_lunes_registro(int $cantidad = 10, ?DateTimeImmutable $referencia = null): array
{
    $cantidad = max(1, min(52, $cantidad));
    $lunes = intensivo_lunes_semana_actual($referencia);
    $fechas = [];
    for ($i = 0; $i < $cantidad; $i++) {
        $fechas[] = $lunes->modify('+'.($i * 7).' days')->format('Y-m-d');
    }
    return $fechas;
}

function intensivo_fecha_valida(string $fecha): DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha, new DateTimeZone('America/Cancun'));
    if (!$date || $date->format('Y-m-d') !== $fecha) {
        throw new InvalidArgumentException('Fecha de intensivo inválida');
    }
    return $date;
}

function intensivo_cierre_inscripcion(string $fechaInicio): string
{
    return intensivo_fecha_valida($fechaInicio)->modify('+6 days')->format('Y-m-d');
}

function intensivo_inscripcion_abierta(string $fechaInicio, ?DateTimeImmutable $referencia = null): bool
{
    $hoy = intensivo_hoy_operativo($referencia)->format('Y-m-d');
    return $hoy <= intensivo_cierre_inscripcion($fechaInicio);
}

function intensivo_estado_por_fechas(
    string $fechaInicio,
    string $fechaFin,
    ?DateTimeImmutable $referencia = null
): string {
    $hoy = intensivo_hoy_operativo($referencia)->format('Y-m-d');

    if ($hoy < $fechaInicio) {
        return 'PROGRAMADO';
    }

    if ($hoy <= $fechaFin) {
        return 'EN_CURSO';
    }

    return 'TERMINADO';
}

function intensivos_reconciliar_estados_sede(
    PDO $pdo,
    string $sedeId,
    ?DateTimeImmutable $referencia = null
): int {
    $hoy = intensivo_hoy_operativo($referencia)->format('Y-m-d');

    $sql = "UPDATE cursos_intensivos
        SET estado = CASE
            WHEN :d1 < fecha_inicio THEN 'PROGRAMADO'
            WHEN :d2 <= fecha_fin THEN 'EN_CURSO'
            ELSE 'TERMINADO'
        END
        WHERE sede_id = :s
          AND estado <> CASE
            WHEN :d3 < fecha_inicio THEN 'PROGRAMADO'
            WHEN :d4 <= fecha_fin THEN 'EN_CURSO'
            ELSE 'TERMINADO'
          END";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':d1' => $hoy,
        ':d2' => $hoy,
        ':d3' => $hoy,
        ':d4' => $hoy,
        ':s' => $sedeId,
    ]);

    return $stmt->rowCount();
}
