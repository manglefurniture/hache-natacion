<?php

declare(strict_types=1);

function intensivo_estado_por_fechas(
    string $fechaInicio,
    string $fechaFin,
    ?DateTimeImmutable $referencia = null
): string {
    $hoy = ($referencia ?? new DateTimeImmutable('today'))->format('Y-m-d');

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
    $hoy = ($referencia ?? new DateTimeImmutable('today'))->format('Y-m-d');

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
