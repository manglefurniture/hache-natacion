<?php

declare(strict_types=1);

function finanzas_mensualidad_corregible(array $row): bool
{
    $estado=strtoupper((string)($row['obligacion_estado']??$row['estado']??''));
    $sinCobro=($row['importe_cobrado']??null)===null;
    $sinPagos=(int)($row['pagos_totales']??0)===0;
    $observacion=trim((string)($row['observacion']??''));
    $continuidad=str_starts_with($observacion,'Continuidad desde intensivo:');
    $intensivoSolapado=(int)($row['intensivo_solapado']??0)===1;

    return $estado==='PENDIENTE' && $sinCobro && $sinPagos && ($continuidad || $intensivoSolapado);
}
