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
f8_financial_period_expect(str_contains($page,'$sinCambios=$periodoAntes&&$siguienteAntes'),'Debe detectar no-op solo cuando ambas filas persistidas existen.');
f8_financial_period_expect(str_contains($page,"&&(string)\$periodoAntes['fecha_cierre']===\$close"),'Debe comparar el cierre persistido con el solicitado.');
f8_financial_period_expect(str_contains($page,"&&(string)\$siguienteAntes['fecha_inicio']===\$nextStart"),'Debe comparar el inicio persistido del periodo siguiente.');
f8_financial_period_expect(str_contains($page,"if(\$sinCambios){\n            \$pdo->commit();\n            out(['ok'=>true,'mensaje'=>'Periodo financiero sin cambios'"),'Un no-op debe terminar sin upsert ni evento confirmado.');

$lockPos=strpos($page,'SELECT id,periodo,fecha_inicio,fecha_cierre FROM periodos_financieros WHERE sede_id=:s AND periodo IN (:p1,:p2) FOR UPDATE');
$noopPos=strpos($page,'if($sinCambios){');
$upsertPos=strpos($page,'INSERT INTO periodos_financieros(sede_id,periodo,fecha_inicio,fecha_cierre,updated_by)');
$auditPos=strpos($page,"'PERIODO_FINANCIERO_RANGO_ACTUALIZADO'");
$commitPos=strpos($page,'$pdo->commit();',$auditPos===false?0:$auditPos);
f8_financial_period_expect($lockPos!==false&&$noopPos!==false&&$upsertPos!==false&&$auditPos!==false&&$commitPos!==false&&$lockPos<$noopPos&&$noopPos<$upsertPos&&$upsertPos<$auditPos&&$auditPos<$commitPos,'Debe evaluar el no-op tras bloquear el snapshot y antes de cualquier upsert o evento; los cambios reales se auditan antes del commit.');

fwrite(STDOUT,"F8_FINANCIAL_PERIOD_AUDIT_REGRESSION_OK\n");
