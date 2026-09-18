<?php

declare(strict_types=1);

function f8_payment_invalidation_expect(bool $condition,string $message):void
{
    if(!$condition){
        fwrite(STDERR,"F8_PAYMENT_INVALIDATION_AUDIT_FAIL: {$message}\n");
        exit(1);
    }
}

$page=file_get_contents(__DIR__.'/../api/invalidar-pago.php')?:'';
f8_payment_invalidation_expect(str_contains($page,"SELECT p.id,p.alumno_id,p.estado,p.tipo"),'La invalidación debe leer el estado anterior desde la fila de pago bloqueada.');
f8_payment_invalidation_expect(str_contains($page,'LIMIT 1 FOR UPDATE'),'La fila del pago debe permanecer bloqueada dentro de la transacción.');
f8_payment_invalidation_expect(str_contains($page,"'estado_anterior'=>(string)\$pago['estado']"),'El evento debe tomar el before de la fila bloqueada.');
f8_payment_invalidation_expect(str_contains($page,"'estado_nuevo'=>'INVALIDADO'"),'El evento debe conservar el after confirmado.');
f8_payment_invalidation_expect(str_contains($page,"'PAGO_INVALIDADO','pago',:pid"),'Debe usar una acción y entidad específicas.');

$detailStart=strpos($page,'$detalleAudit=json_encode([');
$detailEnd=$detailStart===false?false:strpos($page,'],JSON_UNESCAPED_UNICODE',$detailStart);
f8_payment_invalidation_expect($detailStart!==false&&$detailEnd!==false,'Debe existir detalle estructurado para la evidencia F8.');
$detail=substr($page,$detailStart,$detailEnd-$detailStart);
f8_payment_invalidation_expect(str_contains($detail,"'sede_id'")&&str_contains($detail,"'estado_anterior'")&&str_contains($detail,"'estado_nuevo'"),'El detalle debe limitarse a evidencia operativa necesaria.');
f8_payment_invalidation_expect(!str_contains($detail,'motivo')&&!str_contains($detail,'observacion')&&!str_contains($detail,'alumno_id'),'No debe duplicar motivo libre, observación ni relación del alumno en auditoria_eventos.');

$updatePos=strpos($page,"UPDATE pagos SET estado='INVALIDADO'");
$auditPos=strpos($page,"'PAGO_INVALIDADO'");
$commitPos=strpos($page,'$pdo->commit();');
f8_payment_invalidation_expect($updatePos!==false&&$auditPos!==false&&$commitPos!==false&&$updatePos<$auditPos&&$auditPos<$commitPos,'La evidencia específica debe persistirse tras la mutación y antes del commit.');

fwrite(STDOUT,"F8_PAYMENT_INVALIDATION_AUDIT_REGRESSION_OK\n");
