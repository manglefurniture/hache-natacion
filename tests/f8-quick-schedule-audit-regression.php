<?php

declare(strict_types=1);

require_once __DIR__.'/../config/alumno-estado-eventos.php';

function f8_quick_schedule_expect(bool $condition,string $message):void
{
    if(!$condition){
        fwrite(STDERR,"F8_QUICK_SCHEDULE_AUDIT_FAIL: {$message}\n");
        exit(1);
    }
}

$before=['id'=>'a1','horario_preferido_id'=>'h1'];
$after=['horario_preferido_id'=>'h2'];
$detail=hache_alumno_edicion_detalle($before,$after,'s1');
f8_quick_schedule_expect(is_array($detail),'Un cambio rápido real de horario debe generar evidencia.');
f8_quick_schedule_expect(($detail['cambios']['horario_preferido_id']['anterior']??null)==='h1','Debe conservar el horario anterior real.');
f8_quick_schedule_expect(($detail['cambios']['horario_preferido_id']['nuevo']??null)==='h2','Debe conservar el horario nuevo real.');
f8_quick_schedule_expect(hache_alumno_edicion_detalle($before,$before,'s1')===null,'Guardar el mismo horario no debe crear evento artificial.');

$page=file_get_contents(__DIR__.'/../api/alumno-rapido.php')?:'';
f8_quick_schedule_expect(str_contains($page,"require_once __DIR__.'/../config/alumno-estado-eventos.php';"),'El endpoint debe reutilizar el escritor F8 existente.');
f8_quick_schedule_expect(str_contains($page,'SELECT id,horario_preferido_id FROM alumnos WHERE id=:id AND sede_id=:s LIMIT 1 FOR UPDATE'),'El before de horario debe leerse bajo el bloqueo transaccional existente.');
f8_quick_schedule_expect(str_contains($page,"hache_alumno_edicion_evento(\$pdo,\$me,['id'=>\$id,'horario_preferido_id'=>\$actual['horario_preferido_id']??null],['horario_preferido_id'=>\$v],\$sedeId,'/api/alumno-rapido.php')"),'El cambio rápido de horario debe persistir evidencia específica F8.');
f8_quick_schedule_expect(substr_count($page,'hache_alumno_edicion_evento(')===1,'Este micro-paso no debe ampliar la auditoría al cambio rápido de WhatsApp.');

$updatePos=strpos($page,'UPDATE alumnos SET horario_preferido_id=:h');
$auditPos=strpos($page,'hache_alumno_edicion_evento(');
$commitPos=strpos($page,'$pdo->commit();',$auditPos===false?0:$auditPos);
f8_quick_schedule_expect($updatePos!==false&&$auditPos!==false&&$commitPos!==false&&$updatePos<$auditPos&&$auditPos<$commitPos,'La evidencia F8 debe escribirse después de la mutación y antes del commit.');

fwrite(STDOUT,"F8_QUICK_SCHEDULE_AUDIT_REGRESSION_OK\n");
