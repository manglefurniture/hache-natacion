<?php

declare(strict_types=1);

function f8_attendance_correction_expect(bool $condition,string $message):void
{
    if(!$condition){
        fwrite(STDERR,"F8_ATTENDANCE_CORRECTION_AUDIT_FAIL: {$message}\n");
        exit(1);
    }
}

$page=file_get_contents(__DIR__.'/../api/sesiones.php')?:'';
f8_attendance_correction_expect(str_contains($page,'SELECT id,estado,observacion FROM asistencias WHERE sesion_id=:s AND alumno_id=:a LIMIT 1 FOR UPDATE'),'La corrección debe leer y bloquear la asistencia existente antes del upsert.');
f8_attendance_correction_expect(str_contains($page,'$asistenciaAnterior=$st->fetch()?:null;'),'Debe distinguir una corrección de una marca inicial sin inventar before.');
f8_attendance_correction_expect(str_contains($page,"if(\$asistenciaAnterior){\$cambios=[];"),'Solo una fila previa real puede generar ASISTENCIA_CORREGIDA.');
f8_attendance_correction_expect(str_contains($page,"'estado']=['anterior'=>(string)\$asistenciaAnterior['estado'],'nuevo'=>\$estado]"),'El estado debe conservar before/after desde la fila bloqueada y la solicitud validada.');
f8_attendance_correction_expect(str_contains($page,"'observacion']=['modificado'=>true,'valores_omitidos'=>'contenido_libre']"),'La observación debe registrar solo que cambió, sin duplicar texto libre.');
f8_attendance_correction_expect(str_contains($page,"'ASISTENCIA_CORREGIDA','asistencia',:asid"),'Debe persistir un evento específico con el ID de asistencia existente.');

$syncPos=strpos($page,'$repo=sincronizarReposicion($pdo,$sid,$aid,$estado,$uid);');
$auditPos=strpos($page,"'ASISTENCIA_CORREGIDA'");
$commitPos=strpos($page,'$pdo->commit();',$auditPos===false?0:$auditPos);
f8_attendance_correction_expect($syncPos!==false&&$auditPos!==false&&$commitPos!==false&&$syncPos<$auditPos&&$auditPos<$commitPos,'La evidencia debe persistirse después de sincronizar efectos relacionados y antes del commit.');

$detailStart=strpos($page,'$detalleAudit=json_encode([');
$detailEnd=$detailStart===false?false:strpos($page,'],JSON_UNESCAPED_UNICODE',$detailStart);
f8_attendance_correction_expect($detailStart!==false&&$detailEnd!==false,'Debe existir detalle estructurado de la corrección.');
$detail=substr($page,$detailStart,$detailEnd-$detailStart);
f8_attendance_correction_expect(str_contains($detail,"'sede_id'")&&str_contains($detail,"'sesion_id'")&&str_contains($detail,"'alumno_id'")&&str_contains($detail,"'cambios'"),'El evento debe conservar referencias operativas exactas.');
f8_attendance_correction_expect(!str_contains($detail,'$observacion'),'El detalle de auditoría no debe copiar el contenido libre de la observación.');

fwrite(STDOUT,"F8_ATTENDANCE_CORRECTION_AUDIT_REGRESSION_OK\n");
