<?php

declare(strict_types=1);

function f8_professor_edit_expect(bool $condition,string $message):void
{
    if(!$condition){
        fwrite(STDERR,"F8_PROFESSOR_EDIT_AUDIT_FAIL: {$message}\n");
        exit(1);
    }
}

$page=file_get_contents(__DIR__.'/../api/profesores.php')?:'';
f8_professor_edit_expect(str_contains($page,'SELECT id,nombre,whatsapp,correo,activo FROM profesores WHERE id=:id LIMIT 1 FOR UPDATE'),'El snapshot de edición debe leerse bajo el bloqueo transaccional existente.');
f8_professor_edit_expect(str_contains($page,"'PROFESOR_DATOS_ACTUALIZADOS','profesor',:pid"),'La edición debe usar un evento específico de profesor.');
f8_professor_edit_expect(str_contains($page,"'activo']=['anterior'=>(bool)\$actual['activo'],'nuevo'=>(bool)\$active]"),'Activo debe conservar before/after reales.');

$changesStart=strpos($page,'$cambios=[];');
$eventStart=strpos($page,"'PROFESOR_DATOS_ACTUALIZADOS'");
f8_professor_edit_expect($changesStart!==false&&$eventStart!==false,'Debe construir evidencia antes de insertar el evento.');
$changes=substr($page,$changesStart,$eventStart-$changesStart);
f8_professor_edit_expect(str_contains($changes,"'nombre']=['modificado'=>true,'valores_omitidos'=>'PII']"),'Nombre debe registrarse sin duplicar su valor.');
f8_professor_edit_expect(str_contains($changes,"'whatsapp']=['modificado'=>true,'valores_omitidos'=>'PII']"),'WhatsApp debe registrarse sin duplicar su valor.');
f8_professor_edit_expect(str_contains($changes,"'correo']=['modificado'=>true,'valores_omitidos'=>'PII']"),'Correo debe registrarse sin duplicar su valor.');
f8_professor_edit_expect(!str_contains($changes,"'anterior'=>\$actual['nombre']")&&!str_contains($changes,"'nuevo'=>\$name"),'No debe persistir nombre before/after.');
f8_professor_edit_expect(!str_contains($changes,"'anterior'=>\$actual['whatsapp']")&&!str_contains($changes,"'nuevo'=>\$phone"),'No debe persistir WhatsApp before/after.');

$updatePos=strpos($page,'UPDATE profesores SET nombre=:n');
$commitPos=strpos($page,'$pdo->commit();',$eventStart===false?0:$eventStart);
f8_professor_edit_expect($updatePos!==false&&$eventStart!==false&&$commitPos!==false&&$updatePos<$eventStart&&$eventStart<$commitPos,'La evidencia debe persistirse después de la edición y antes del commit.');

fwrite(STDOUT,"F8_PROFESSOR_EDIT_AUDIT_REGRESSION_OK\n");
