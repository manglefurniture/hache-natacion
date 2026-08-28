<?php

declare(strict_types=1);

require_once __DIR__.'/../config/intensivos-estado.php';
require_once __DIR__.'/../config/intensivo-transferencias.php';

function check(bool $condition,string $message): void
{
    if(!$condition) throw new RuntimeException($message);
}

$tz=new DateTimeZone('America/Cancun');
$viernes=new DateTimeImmutable('2026-08-28 12:00:00',$tz);
$opciones=intensivo_lunes_registro(4,$viernes);
check(
    $opciones===['2026-08-31','2026-08-24','2026-09-07','2026-09-14'],
    'En viernes el próximo lunes debe ser la opción predeterminada y el lunes en curso debe seguir disponible como inscripción tardía.'
);

$lunes=new DateTimeImmutable('2026-08-31 08:00:00',$tz);
$opcionesLunes=intensivo_lunes_registro(4,$lunes);
check(
    $opcionesLunes===['2026-08-31','2026-09-07','2026-09-14','2026-09-21'],
    'En lunes el curso que inicia ese mismo día debe seguir siendo la primera opción.'
);

$helper=file_get_contents(__DIR__.'/../config/intensivo-transferencias.php');
$editar=file_get_contents(__DIR__.'/../public/editar-alumno.php');
check(is_string($helper)&&is_string($editar),'No se pudieron leer los archivos de transferencia.');

foreach([
    "tipo='INTENSIVO' AND estado='VALIDO'",
    'FROM ausencias WHERE alumno_id=:a AND intensivo_id=:c',
    'FROM asistencias aa INNER JOIN sesiones se',
    'UPDATE curso_intensivo_alumnos SET curso_intensivo_id=:nuevo',
    'Creado automáticamente al transferir un alumno desde la edición administrativa.',
] as $needle){
    check(str_contains($helper,$needle),'Falta una protección crítica en la transferencia de intensivos: '.$needle);
}

check(
    str_contains($editar,"require_once __DIR__.'/../config/intensivo-transferencias.php'") &&
    str_contains($editar,'intensivo_transferir_por_fecha_edicion($pdo,$id,$sedeId,$fechaInicio'),
    'Editar alumno debe sincronizar la fecha con la pertenencia real al intensivo.'
);
check(
    str_contains($editar,'Por seguridad no se moverá si ya tiene pago, asistencia, ausencias, reposiciones o continuidad registradas.'),
    'La interfaz administrativa debe explicar cuándo una transferencia está protegida/bloqueada.'
);

echo "INTENSIVO_FECHA_TRANSFERENCIA_REGRESSION_OK\n";
