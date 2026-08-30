<?php

declare(strict_types=1);
require_once __DIR__.'/../config/dashboard-tiempo.php';

function same(string $label, mixed $actual, mixed $expected): void
{
    if ($actual !== $expected) {
        fwrite(STDERR, $label.': esperado '.var_export($expected,true).', recibido '.var_export($actual,true).PHP_EOL);
        exit(1);
    }
}

$resolver = static function (string $sedeId, string $fecha): string {
    if ($sedeId !== 'TEST') {
        throw new RuntimeException('Sede inesperada');
    }
    return $fecha <= '2026-08-30' ? '2026-08' : '2026-09';
};

$utcAunDiaAnterior = dashboard_contexto_temporal(
    'TEST',
    $resolver,
    new DateTimeImmutable('2026-08-30T00:30:00+00:00')
);
same('00:30 UTC fecha Cancún', $utcAunDiaAnterior['fecha'], '2026-08-29');
same('00:30 UTC periodo Cancún', $utcAunDiaAnterior['periodo_vigente'], '2026-08');

$antesMedianocheCancun = dashboard_contexto_temporal(
    'TEST',
    $resolver,
    new DateTimeImmutable('2026-08-31T04:59:59+00:00')
);
same('antes medianoche fecha', $antesMedianocheCancun['fecha'], '2026-08-30');
same('antes medianoche periodo', $antesMedianocheCancun['periodo_vigente'], '2026-08');

$despuesMedianocheCancun = dashboard_contexto_temporal(
    'TEST',
    $resolver,
    new DateTimeImmutable('2026-08-31T05:00:00+00:00')
);
same('después medianoche fecha', $despuesMedianocheCancun['fecha'], '2026-08-31');
same('después medianoche periodo', $despuesMedianocheCancun['periodo_vigente'], '2026-09');

echo "dashboard Cancun operational boundary: OK\n";
