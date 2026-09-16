<?php
declare(strict_types=1);

function f2_ok(bool $condition,string $message):void
{
    if(!$condition){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}
}

$api=file_get_contents(__DIR__.'/../api/finanzas-internas.php')?:'';
$page=file_get_contents(__DIR__.'/../public/finanzas-internas.php')?:'';

f2_ok(str_contains($api,"auth_require(['ADMIN','VERIFICADOR'])"),'La API debe conservar permisos administrativos');
f2_ok(str_contains($api,"REQUEST_METHOD']??'GET')!=='GET'"),'La API debe rechazar métodos distintos de GET');
f2_ok(str_contains($api,"financiero_totales(\$pdo,\$sede,\$periodo)"),'La API debe reutilizar financiero_totales');
f2_ok(str_contains($api,"m.importe_a_cobrar total_obligacion"),'La mensualidad debe usar su obligación registrada');
f2_ok(str_contains($api,"i.importe total_obligacion"),'La inscripción debe usar su importe registrado');
f2_ok(str_contains($api,"ci.precio total_obligacion"),'El intensivo debe usar el precio registrado del curso');
f2_ok(str_contains($api,"p.intensivo_id=ci.id AND p.alumno_id=cia.alumno_id"),'Los abonos de intensivo deben cercarse por alumno y curso');
f2_ok(str_contains($api,"p.estado='VALIDO'"),'Solo pagos válidos deben reducir saldo');
f2_ok(str_contains($api,'pagos_invalidados'),'Los pagos invalidados deben permanecer visibles como historia');
f2_ok(str_contains($api,'comparacion_cierre'),'Debe existir comparación contra el cierre histórico');
f2_ok(!preg_match('/\b(?:INSERT|UPDATE|DELETE|REPLACE)\s+(?:INTO\s+)?(?:pagos|mensualidades|inscripciones|cursos_intensivos|curso_intensivo_alumnos|periodos_financieros|cierres_mensuales)\b/i',$api),'La lectura F2 no puede mutar tablas financieras');
f2_ok(str_contains($page,'No sobrescribe el cierre guardado'),'La UI debe declarar el cierre histórico inmutable');
f2_ok(str_contains($page,'Saldo = obligación registrada'),'La UI debe explicar la definición de saldo');

fwrite(STDOUT,"FINANZAS_INTERNAS_READONLY_OK\n");
