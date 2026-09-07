<?php

declare(strict_types=1);

require_once __DIR__.'/../config/intensivos-estado.php';
require_once __DIR__.'/../config/intensivo-transferencias.php';

function check(bool $condition,string $message): void
{
    if(!$condition) throw new RuntimeException($message);
}

function expectTransferException(callable $fn,string $needle,string $message): void
{
    try{$fn();}
    catch(IntensivoTransferenciaException $e){
        check(str_contains($e->getMessage(),$needle),$message.' Mensaje recibido: '.$e->getMessage());
        return;
    }
    throw new RuntimeException($message.' No se lanzó IntensivoTransferenciaException.');
}

$tz=new DateTimeZone('America/Cancun');
$viernes=new DateTimeImmutable('2026-08-28 12:00:00',$tz);
$opciones=intensivo_lunes_registro(4,$viernes);
check($opciones===['2026-08-31','2026-09-07','2026-09-14','2026-09-21'],'Después del martes el curso de la semana actual ya no debe ofrecerse; el próximo lunes debe ser la primera opción.');
$lunes=new DateTimeImmutable('2026-08-31 08:00:00',$tz);$opcionesLunes=intensivo_lunes_registro(4,$lunes);
check($opcionesLunes===['2026-08-31','2026-09-07','2026-09-14','2026-09-21'],'En lunes el curso que inicia ese mismo día debe seguir siendo la primera opción.');
$martes=new DateTimeImmutable('2026-09-01 12:00:00',$tz);$opcionesMartes=intensivo_lunes_registro(4,$martes);
check($opcionesMartes===['2026-09-07','2026-08-31','2026-09-14','2026-09-21'],'En martes todavía debe permitirse incorporarse al curso iniciado el lunes, pero el próximo lunes queda como opción predeterminada.');
$miercoles=new DateTimeImmutable('2026-09-02 12:00:00',$tz);$opcionesMiercoles=intensivo_lunes_registro(4,$miercoles);
check($opcionesMiercoles===['2026-09-07','2026-09-14','2026-09-21','2026-09-28'],'Desde el miércoles el curso iniciado esa semana debe desaparecer de las opciones.');
check(intensivo_cierre_inscripcion('2026-08-31')==='2026-09-01','La ventana de incorporación del intensivo debe cerrar el martes.');
check(intensivo_inscripcion_abierta('2026-08-31',$martes)===true && intensivo_inscripcion_abierta('2026-08-31',$miercoles)===false,'El alta debe admitirse lunes/martes y rechazarse desde el miércoles.');

check(intensivo_validar_fecha_transferencia([], '2026-08-27')===null,'Un alumno sin relación activa de intensivo debe poder conservar una fecha regular que no sea lunes.');
$relacionActiva=[['curso_intensivo_id'=>'curso-1']];
expectTransferException(fn()=>intensivo_validar_fecha_transferencia($relacionActiva,''),'obligatoria','No debe permitirse borrar la fecha mientras exista una relación activa de intensivo.');
expectTransferException(fn()=>intensivo_validar_fecha_transferencia($relacionActiva,'2026-08-27'),'lunes','La restricción de lunes sí debe aplicarse cuando existe una relación activa de intensivo.');
$fechaValida=intensivo_validar_fecha_transferencia($relacionActiva,'2026-08-31');
check($fechaValida instanceof DateTimeImmutable && $fechaValida->format('Y-m-d')==='2026-08-31','Una relación activa de intensivo debe aceptar un lunes válido.');

$helper=file_get_contents(__DIR__.'/../config/intensivo-transferencias.php');
$editar=file_get_contents(__DIR__.'/../public/editar-alumno.php');
check(is_string($helper)&&is_string($editar),'No se pudieron leer los archivos de transferencia.');
foreach(["tipo='INTENSIVO' AND estado='VALIDO'",'FROM ausencias WHERE alumno_id=:a AND intensivo_id=:c','FROM asistencias aa INNER JOIN sesiones se','UPDATE curso_intensivo_alumnos SET curso_intensivo_id=:nuevo','Creado automáticamente al transferir un alumno desde la edición administrativa.'] as $needle){check(str_contains($helper,$needle),'Falta una protección crítica en la transferencia de intensivos: '.$needle);}
check(!str_contains($helper,'UPDATE alumnos SET fecha_inicio=:f,horario_preferido_id=:h'),'La transferencia no debe reemplazar horario_preferido_id con el horario del intensivo.');
check(str_contains($helper,'UPDATE alumnos SET fecha_inicio=:f,updated_at=NOW()'),'La sincronización debe limitarse a la fecha del alumno y preservar su horario regular/preferido.');
check(str_contains($helper,'bool $permitirHistorico=false')&&str_contains($helper,"$estado==='TERMINADO'&&!$permitirHistorico"),'Solo una corrección administrativa explícita debe poder apuntar a un intensivo ya terminado.');
check(str_contains($helper,'Corrección histórica:'),'La transferencia histórica debe dejar nota en la relación del intensivo.');

check(str_contains($editar,"require_once __DIR__.'/../config/intensivo-transferencias.php'")&&str_contains($editar,'intensivo_transferir_por_fecha_edicion($pdo,$id,$sedeId,$fechaInicio'),'Editar alumno debe sincronizar la fecha con la pertenencia real al intensivo.');
check(str_contains($editar,"require_once __DIR__.'/../config/admin-historical-corrections.php'")&&str_contains($editar,'hache_admin_history($pdo,$id'),'El cambio de fecha de ADMIN debe quedar auditado en historial.');
check(str_contains($editar,'Para modificar la fecha de inicio, escribe en Observaciones el motivo de la corrección'),'Cambiar fecha de inicio debe exigir comentario administrativo.');
check(str_contains($editar,'$cambioFecha,$cambioFecha?$obs:null'),'Una fecha modificada por ADMIN debe habilitar explícitamente la corrección histórica usando su observación como motivo.');
check(str_contains($editar,"if(\$estado==='BAJA')")&&str_contains($editar,'SELECT ci.fecha_inicio FROM curso_intensivo_alumnos')&&str_contains($editar,'$fechaInicio===null')&&str_contains($editar,'incluso al marcarlo como baja'),'Marcar BAJA no debe permitir borrar fecha_inicio si aún existe una relación activa de intensivo.');
check(str_contains($editar,'$fechaInicio!==$fechaIntensivoBaja')&&str_contains($editar,'Al marcar BAJA conserva la fecha del curso intensivo actual'),'Marcar BAJA tampoco debe permitir cambiar fecha_inicio sin mover primero la relación real del intensivo.');
check(str_contains($editar,'Si el intensivo actual ya tiene pagos, asistencia, ausencias, reposiciones o continuidad, el sistema seguirá frenando el traslado automático'),'La interfaz debe explicar que el override histórico no rompe las protecciones de historial académico/contable.');

echo "INTENSIVO_FECHA_TRANSFERENCIA_REGRESSION_OK\n";
