<?php

declare(strict_types=1);

function f8_intensive_removal_expect(bool $condition,string $message):void
{
    if(!$condition){
        fwrite(STDERR,"F8_INTENSIVE_REMOVAL_AUDIT_FAIL: {$message}\n");
        exit(1);
    }
}

$page=file_get_contents(__DIR__.'/../api/intensivo-alumnos.php')?:'';
f8_intensive_removal_expect(str_contains($page,'SELECT cia.id,cia.curso_intensivo_id,cia.alumno_id,ci.estado FROM curso_intensivo_alumnos cia'),'El before debe salir de la relación bloqueada.');
f8_intensive_removal_expect(str_contains($page,'LIMIT 1 FOR UPDATE'),'La relación debe permanecer bloqueada antes de eliminarse.');
f8_intensive_removal_expect(str_contains($page,"'INTENSIVO_ALUMNO_RETIRADO','intensivo-alumnos',:rid"),'Debe persistir un evento específico con el ID de relación.');
f8_intensive_removal_expect(str_contains($page,"'relacion_id'=>(string)\$rel['id']"),'Debe conservar el ID original de la relación.');
f8_intensive_removal_expect(str_contains($page,"'curso_intensivo_id'=>(string)\$rel['curso_intensivo_id']"),'Debe conservar el curso desde la fila bloqueada.');
f8_intensive_removal_expect(str_contains($page,"'alumno_id'=>(string)\$rel['alumno_id']"),'Debe conservar el alumno desde la fila bloqueada.');
f8_intensive_removal_expect(str_contains($page,"'presente_anterior'=>true,'presente_nuevo'=>false"),'Debe expresar la eliminación como presencia real true→false.');

$deletePos=strpos($page,'DELETE FROM curso_intensivo_alumnos WHERE id=:id');
$auditPos=strpos($page,"'INTENSIVO_ALUMNO_RETIRADO'");
$commitPos=strpos($page,'$pdo->commit();',$auditPos===false?0:$auditPos);
f8_intensive_removal_expect($deletePos!==false&&$auditPos!==false&&$commitPos!==false&&$deletePos<$auditPos&&$auditPos<$commitPos,'La evidencia debe persistirse después del DELETE y antes del commit.');

$detailStart=strpos($page,'$detalleAudit=json_encode([');
$detailEnd=$detailStart===false?false:strpos($page,'],JSON_UNESCAPED_UNICODE',$detailStart);
f8_intensive_removal_expect($detailStart!==false&&$detailEnd!==false,'Debe existir detalle estructurado para el retiro.');
$detail=substr($page,$detailStart,$detailEnd-$detailStart);
f8_intensive_removal_expect(!str_contains($detail,'nombre')&&!str_contains($detail,'observaciones'),'No debe copiar PII ni observaciones de la relación.');

fwrite(STDOUT,"F8_INTENSIVE_REMOVAL_AUDIT_REGRESSION_OK\n");
