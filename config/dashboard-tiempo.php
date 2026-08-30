<?php

declare(strict_types=1);

function hache_instante_operativo(?DateTimeImmutable $instante = null): DateTimeImmutable
{
    $zona = new DateTimeZone('America/Cancun');
    return $instante === null
        ? new DateTimeImmutable('now', $zona)
        : $instante->setTimezone($zona);
}

function dashboard_contexto_temporal(string $sedeId, callable $resolverPeriodo, ?DateTimeImmutable $instante = null): array
{
    $fecha = hache_instante_operativo($instante)->format('Y-m-d');

    return [
        'fecha' => $fecha,
        'periodo_vigente' => (string)$resolverPeriodo($sedeId, $fecha),
    ];
}
