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
    $instanteOperativo = hache_instante_operativo($instante);
    $fecha = $instanteOperativo->format('Y-m-d');

    return [
        'fecha' => $fecha,
        'actualizado_en' => $instanteOperativo->format(DateTimeInterface::ATOM),
        'periodo_vigente' => (string)$resolverPeriodo($sedeId, $fecha),
    ];
}
