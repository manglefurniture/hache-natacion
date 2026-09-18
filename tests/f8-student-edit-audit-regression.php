<?php

declare(strict_types=1);

require_once __DIR__.'/../config/alumno-estado-eventos.php';

function f8_student_expect(bool $condition,string $message):void
{
    if(!$condition){
        fwrite(STDERR,"F8_STUDENT_EDIT_AUDIT_FAIL: {$message}\n");
        exit(1);
    }
}

$before=[
    'id'=>'a1',
    'nombre'=>'Nombre anterior',
    'fecha_nacimiento'=>'1990-01-01',
    'whatsapp'=>'5211111111111',
    'correo'=>'antes@example.test',
    'horario_preferido_id'=>'h1',
    'plan_actual_id'=>null,
    'observaciones'=>'Nota anterior',
];
$after=[
    'nombre'=>'Nombre nuevo',
    'fecha_nacimiento'=>'1991-02-02',
    'whatsapp'=>'5222222222222',
    'correo'=>'nuevo@example.test',
    'horario_preferido_id'=>'h2',
    'plan_actual_id'=>'p1',
    'observaciones'=>'Nota nueva',
];

$detail=hache_alumno_edicion_detalle($before,$after,'s1');
f8_student_expect(is_array($detail),'Una edición real debe generar evidencia.');
f8_student_expect(($detail['sede_id']??null)==='s1','Debe conservar la sede real de la mutación.');
f8_student_expect(($detail['cambios']['horario_preferido_id']['anterior']??null)==='h1','Horario debe guardar before.');
f8_student_expect(($detail['cambios']['horario_preferido_id']['nuevo']??null)==='h2','Horario debe guardar after.');
f8_student_expect(array_key_exists('anterior',$detail['cambios']['plan_actual_id'])&&$detail['cambios']['plan_actual_id']['anterior']===null,'Plan debe conservar null real como before.');
f8_student_expect(($detail['cambios']['plan_actual_id']['nuevo']??null)==='p1','Plan debe guardar after.');

$json=json_encode($detail,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
foreach(['Nombre anterior','Nombre nuevo','5211111111111','5222222222222','antes@example.test','nuevo@example.test','Nota anterior','Nota nueva','1990-01-01','1991-02-02'] as $pii){
    f8_student_expect(!str_contains((string)$json,$pii),'No debe duplicar PII en auditoría: '.$pii);
}
foreach(['nombre','fecha_nacimiento','whatsapp','correo','observaciones'] as $field){
    f8_student_expect(($detail['cambios'][$field]['modificado']??false)===true,'Debe registrar que cambió '.$field.'.');
    f8_student_expect(($detail['cambios'][$field]['valores_omitidos']??null)==='PII','Debe marcar valores omitidos para '.$field.'.');
}

$same=hache_alumno_edicion_detalle($before,$before,'s1');
f8_student_expect($same===null,'Guardar sin cambios no debe crear un evento artificial.');

$page=file_get_contents(__DIR__.'/../public/editar-alumno.php')?:'';
f8_student_expect(str_contains($page,'SELECT id,nombre,fecha_nacimiento,whatsapp,correo,sede_id,horario_preferido_id,plan_actual_id,fecha_inicio,estado_administrativo,observaciones FROM alumnos'),'El snapshot bloqueado debe incluir los campos que se comparan.');
f8_student_expect(str_contains($page,"hache_alumno_edicion_evento(\$pdo,\$admin,\$actual,\$despuesAudit,\$sedeId,'/public/editar-alumno.php')"),'La edición debe persistir su evento F8.');
$updatePos=strpos($page,'UPDATE alumnos SET');
$auditPos=strpos($page,'hache_alumno_edicion_evento(');
$commitPos=strpos($page,'$pdo->commit();');
f8_student_expect($updatePos!==false&&$auditPos!==false&&$commitPos!==false&&$updatePos<$auditPos&&$auditPos<$commitPos,'El audit debe escribirse después de la mutación y antes del commit.');

$helper=file_get_contents(__DIR__.'/../config/alumno-estado-eventos.php')?:'';
f8_student_expect(str_contains($helper,"'ALUMNO_DATOS_ACTUALIZADOS'"),'Debe usar una acción específica y estable.');
f8_student_expect(str_contains($helper,"'valores_omitidos'=>'PII'"),'La política de minimización PII debe quedar explícita.');

fwrite(STDOUT,"F8_STUDENT_EDIT_AUDIT_REGRESSION_OK\n");
