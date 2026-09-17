<?php
declare(strict_types=1);

require_once __DIR__.'/../config/centro-pendientes-continuidad.php';

function continuity_pending_expect(bool $ok,string $message): void
{
    if(!$ok){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}
}

continuity_pending_expect(in_array('sqlite',PDO::getAvailableDrivers(),true),'La regresión requiere PDO SQLite.');
$pdo=new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE configuracion (clave TEXT PRIMARY KEY, valor TEXT NOT NULL)');
$pdo->exec('CREATE TABLE alumnos (id TEXT PRIMARY KEY, nombre TEXT NOT NULL)');
$pdo->exec('CREATE TABLE cursos_intensivos (id TEXT PRIMARY KEY, sede_id TEXT NOT NULL, fecha_fin TEXT NOT NULL, estado TEXT NOT NULL)');
$pdo->exec('CREATE TABLE curso_intensivo_alumnos (id TEXT PRIMARY KEY, curso_intensivo_id TEXT NOT NULL, alumno_id TEXT NOT NULL, continua_regular INTEGER NULL)');
$pdo->exec("INSERT INTO alumnos(id,nombre) VALUES('a1','Ana')");
$pdo->exec("INSERT INTO cursos_intensivos(id,sede_id,fecha_fin,estado) VALUES('c1','s1','2026-09-01','TERMINADO')");
$pdo->exec("INSERT INTO curso_intensivo_alumnos(id,curso_intensivo_id,alumno_id,continua_regular) VALUES('r1','c1','a1',NULL)");
$pdo->exec("INSERT INTO configuracion(clave,valor) VALUES('f5_intensive_no_continuity_days','7'),('f5_intensive_no_continuity_scope','SIN_EVALUAR')");

$items=centro_pendientes_continuidad_fuentes_activas($pdo,'s1','Sede Uno');
continuity_pending_expect(count($items)===1,'Una relación vencida sin continuidad debe entrar al Centro de pendientes.');
$item=array_values($items)[0];
continuity_pending_expect($item['tipo']===CENTRO_PENDIENTES_CONTINUIDAD_TIPO,'Debe usar el tipo específico de continuidad.');
continuity_pending_expect($item['origen_tipo']==='CURSO_INTENSIVO_CONTINUIDAD'&&$item['origen_id']==='r1','La relación curso-alumno debe ser el origen estable.');
continuity_pending_expect($item['periodo_inicio']===null&&$item['periodo_fin']===null,'El umbral configurable no debe formar parte de la identidad.');
$identity=(string)$item['identidad'];

$pdo->exec("UPDATE configuracion SET valor='3' WHERE clave='f5_intensive_no_continuity_days'");
$changed=centro_pendientes_continuidad_fuentes_activas($pdo,'s1','Sede Uno');
continuity_pending_expect(count($changed)===1&&array_key_first($changed)===$identity,'Cambiar el plazo no debe duplicar el mismo pendiente.');
continuity_pending_expect(centro_pendientes_continuidad_causa_activa($pdo,$item,'s1'),'La causa debe revalidarse con la misma regla F5.');

$pdo->exec("UPDATE curso_intensivo_alumnos SET continua_regular=1 WHERE id='r1'");
continuity_pending_expect(!centro_pendientes_continuidad_causa_activa($pdo,$item,'s1'),'Confirmar continuidad debe desactivar la causa.');
$pdo->exec("UPDATE curso_intensivo_alumnos SET continua_regular=NULL WHERE id='r1'");
$pdo->exec("UPDATE configuracion SET valor='' WHERE clave='f5_intensive_no_continuity_scope'");
continuity_pending_expect(!centro_pendientes_continuidad_causa_activa($pdo,$item,'s1'),'Deshabilitar la regla debe dejar la causa inactiva.');
continuity_pending_expect(centro_pendientes_continuidad_descripcion_tipo(CENTRO_PENDIENTES_CONTINUIDAD_TIPO)==='Intensivo terminado sin continuidad','El tipo debe tener etiqueta administrativa propia.');

$api=file_get_contents(__DIR__.'/../api/pendientes.php')?:'';
$page=file_get_contents(__DIR__.'/../public/pendientes.php')?:'';
continuity_pending_expect(str_contains($api,"require_once __DIR__.'/../config/centro-pendientes-continuidad.php'"),'El API debe cargar la extensión de continuidad.');
continuity_pending_expect(str_contains($api,'pendientes_fuentes_activas($pdo')&&str_contains($api,'pendientes_causa_activa($pdo'),'Listar, atender y resolver deben usar la fuente compuesta.');
continuity_pending_expect(str_contains($api,'CENTRO_PENDIENTES_CONTINUIDAD_TIPO'),'El tipo debe exponerse entre los habilitados.');
continuity_pending_expect(!str_contains($page,'varias ausencias y continuidad de intensivos permanecen diferidos'),'La interfaz no debe seguir declarando diferidas reglas ya integradas.');

echo "INTENSIVE_CONTINUITY_PENDING_REGRESSION_OK\n";
