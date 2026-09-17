<?php
declare(strict_types=1);

require_once __DIR__.'/../config/intensive-no-continuity-alert.php';

function continuity_alert_expect(bool $ok,string $message): void
{
    if(!$ok){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}
}

continuity_alert_expect(in_array('sqlite',PDO::getAvailableDrivers(),true),'La regresión requiere PDO SQLite.');
$now=new DateTimeImmutable('2026-09-17 12:00:00',new DateTimeZone('America/Cancun'));
continuity_alert_expect(hache_internal_intensive_no_continuity_due('2026-09-10',7,$now),'Siete días después debe vencer el umbral.');
continuity_alert_expect(!hache_internal_intensive_no_continuity_due('2026-09-11',7,$now),'Antes del umbral no debe alertar.');
continuity_alert_expect(!hache_internal_intensive_no_continuity_due('2026-09-17',0,$now),'Un curso que termina hoy todavía no terminó operativamente.');
continuity_alert_expect(hache_internal_intensive_no_continuity_matches(null,'SIN_EVALUAR'),'SIN_EVALUAR debe incluir NULL.');
continuity_alert_expect(!hache_internal_intensive_no_continuity_matches(0,'SIN_EVALUAR'),'SIN_EVALUAR no debe incluir un no explícito.');
continuity_alert_expect(hache_internal_intensive_no_continuity_matches(0,'SIN_EVALUAR_O_NO'),'El alcance amplio debe incluir un no explícito.');
continuity_alert_expect(!hache_internal_intensive_no_continuity_matches(1,'SIN_EVALUAR_O_NO'),'Una continuidad confirmada nunca debe alertar.');

$pdo=new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE configuracion (clave TEXT PRIMARY KEY, valor TEXT NOT NULL)');
$pdo->exec('CREATE TABLE alumnos (id TEXT PRIMARY KEY, nombre TEXT NOT NULL)');
$pdo->exec('CREATE TABLE cursos_intensivos (id TEXT PRIMARY KEY, sede_id TEXT NOT NULL, fecha_fin TEXT NOT NULL, estado TEXT NOT NULL)');
$pdo->exec('CREATE TABLE curso_intensivo_alumnos (id TEXT PRIMARY KEY, curso_intensivo_id TEXT NOT NULL, alumno_id TEXT NOT NULL, continua_regular INTEGER NULL)');
foreach([['a1','Ana'],['a2','Beto'],['a3','Caro'],['a4','Dani'],['a5','Eva'],['a6','Fabi']] as$row){$st=$pdo->prepare('INSERT INTO alumnos(id,nombre) VALUES(?,?)');$st->execute($row);}
foreach([
    ['c1','s1','2026-09-10','TERMINADO'],
    ['c2','s1','2026-09-16','TERMINADO'],
    ['c3','s1','2026-09-10','CANCELADO'],
    ['c4','s2','2026-09-10','TERMINADO'],
] as$row){$st=$pdo->prepare('INSERT INTO cursos_intensivos(id,sede_id,fecha_fin,estado) VALUES(?,?,?,?)');$st->execute($row);}
foreach([
    ['r1','c1','a1',null],['r2','c1','a2',0],['r3','c1','a3',1],
    ['r4','c2','a4',null],['r5','c3','a5',null],['r6','c4','a6',null],
] as$row){$st=$pdo->prepare('INSERT INTO curso_intensivo_alumnos(id,curso_intensivo_id,alumno_id,continua_regular) VALUES(?,?,?,?)');$st->execute($row);}

$disabled=['intensive_no_continuity_days'=>null,'intensive_no_continuity_scope'=>null];
continuity_alert_expect(hache_internal_intensive_no_continuity_candidates($pdo,'s1',$now,$disabled)===[],'La regla debe permanecer apagada sin configuración completa.');
$strict=['intensive_no_continuity_days'=>7,'intensive_no_continuity_scope'=>'SIN_EVALUAR'];
$rows=hache_internal_intensive_no_continuity_candidates($pdo,'s1',$now,$strict);
continuity_alert_expect(array_column($rows,'relacion_id')===['r1'],'Solo la relación vencida y sin evaluar debe aparecer en alcance estricto.');
continuity_alert_expect($rows[0]['curso_id']==='c1'&&$rows[0]['alumno_id']==='a1','La candidata debe conservar curso, alumno e identidad de relación.');
$wide=['intensive_no_continuity_days'=>7,'intensive_no_continuity_scope'=>'SIN_EVALUAR_O_NO'];
$wideRows=hache_internal_intensive_no_continuity_candidates($pdo,'s1',$now,$wide);
continuity_alert_expect(array_column($wideRows,'relacion_id')===['r1','r2'],'El alcance amplio debe añadir únicamente el no explícito vencido.');

$api=file_get_contents(__DIR__.'/../api/alertas.php')?:'';
continuity_alert_expect(str_contains($api,"require_once __DIR__.'/../config/intensive-no-continuity-alert.php'"),'Alertas debe cargar la autoridad de continuidad F5.');
continuity_alert_expect(str_contains($api,"'tipo'=>'CONTINUIDAD','nivel'=>'NEUTRA'")&&str_contains($api,'hache_internal_intensive_no_continuity_candidates'),'El Centro de alertas debe reutilizar la misma detección y mantener prioridad neutra.');

echo "INTENSIVE_NO_CONTINUITY_ALERT_REGRESSION_OK\n";
