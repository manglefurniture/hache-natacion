<?php

declare(strict_types=1);

function f8_financial_period_expect(bool $condition,string $message):void
{
    if(!$condition){
        fwrite(STDERR,"F8_FINANCIAL_PERIOD_AUDIT_FAIL: {$message}\n");
        exit(1);
    }
}

$page=file_get_contents(__DIR__.'/../api/cierres-mensuales.php')?:'';
f8_financial_period_expect(str_contains($page,'SELECT id,periodo,fecha_inicio,fecha_cierre FROM periodos_financieros WHERE sede_id=:s AND periodo IN (:p1,:p2) FOR UPDATE'),'Debe bloquear las dos filas de periodo antes de modificarlas.');
f8_financial_period_expect(str_contains($page,"'anterior'=>\$periodoAntes"),'Debe construir el before del periodo principal desde la fila bloqueada.');
f8_financial_period_expect(str_contains($page,"'siguiente_anterior'=>\$siguienteAntes"),'Debe construir el before del periodo siguiente desde la fila bloqueada.');
f8_financial_period_expect(str_contains($page,"['existia'=>false]"),'Debe conservar explícitamente la ausencia de una fila previa sin inventar fechas.');
f8_financial_period_expect(str_contains($page,"'PERIODO_FINANCIERO_RANGO_ACTUALIZADO','periodo_financiero',:pid"),'Debe usar un evento de dominio específico con el ID persistido del periodo.');
f8_financial_period_expect(str_contains($page,"'siguiente_periodo_id'=>(string)\$siguienteDespues['id']"),'Debe conservar la referencia exacta al periodo siguiente afectado.');

$lockPos=strpos($page,'SELECT id,periodo,fecha_inicio,fecha_cierre FROM periodos_financieros WHERE sede_id=:s AND periodo IN (:p1,:p2) FOR UPDATE');
$upsertPos=strpos($page,'INSERT INTO periodos_financieros(sede_id,periodo,fecha_inicio,fecha_cierre,updated_by)');
$auditPos=strpos($page,"'PERIODO_FINANCIERO_RANGO_ACTUALIZADO'");
$commitPos=strpos($page,'$pdo->commit();',$auditPos===false?0:$auditPos);
f8_financial_period_expect($lockPos!==false&&$upsertPos!==false&&$auditPos!==false&&$commitPos!==false&&$lockPos<$upsertPos&&$upsertPos<$auditPos&&$auditPos<$commitPos,'El snapshot debe bloquearse antes del upsert y el evento persistirse antes del commit.');

fwrite(STDOUT,"F8_FINANCIAL_PERIOD_AUDIT_REGRESSION_OK\n");
