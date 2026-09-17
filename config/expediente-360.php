<?php

declare(strict_types=1);

function hache_expediente_pago_historico_fecha_imprecisa(string $metodo, ?string $observacion): bool
{
    if (strtoupper(trim($metodo)) !== 'NO_REGISTRADO') return false;
    $observacion = (string)$observacion;
    return str_contains($observacion, 'Fecha exacta y método de pago no registrados')
        || str_contains($observacion, 'Fecha exacta y método no registrados');
}

function hache_expediente_limpiar_marca_pago_historico(?string $observacion): string
{
    $observacion = (string)$observacion;
    $observacion = (string)preg_replace('/\s*Fecha exacta y método(?: de pago)? no registrados\.?(\s*)/u', ' ', $observacion);
    return trim((string)preg_replace('/\s{2,}/u', ' ', $observacion));
}
