<?php

declare(strict_types=1);

function dashboard_contexto_temporal(string $sedeId, callable $resolverPeriodo, ?DateTimeImmutable $instante = null): array
{
    $zona = new DateTimeZone('America/Cancun');
    $momento = $instante === null
        ? new DateTimeImmutable('now', $zona)
        : $instante->setTimezone($zona);
    $fecha = $momento->format('Y-m-d');

    return [
        'fecha' => $fecha,
        'periodo_vigente' => (string)$resolverPeriodo($sedeId, $fecha),
    ];
}
