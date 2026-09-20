<?php

declare(strict_types=1);

function hache_zona_horaria_operativa(): DateTimeZone
{
    static $zona = null;
    return $zona ??= new DateTimeZone('America/Cancun');
}

function hache_instante_operativo(?DateTimeImmutable $instante = null): DateTimeImmutable
{
    $zona = hache_zona_horaria_operativa();
    return $instante === null
        ? new DateTimeImmutable('now', $zona)
        : $instante->setTimezone($zona);
}

function hache_fecha_operativa(?DateTimeImmutable $instante = null): string
{
    return hache_instante_operativo($instante)->format('Y-m-d');
}

function hache_hoy_operativo(?DateTimeImmutable $instante = null): DateTimeImmutable
{
    $hoy = DateTimeImmutable::createFromFormat(
        '!Y-m-d',
        hache_fecha_operativa($instante),
        hache_zona_horaria_operativa()
    );
    if (!$hoy) throw new RuntimeException('No se pudo resolver la fecha operativa de Hache Natación');
    return $hoy;
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
