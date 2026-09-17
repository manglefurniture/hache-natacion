<?php
declare(strict_types=1);

require_once __DIR__.'/../config/consecutive-absence-alert.php';

function consecutive_absence_expect(bool $ok, string $message): void
{
    if (!$ok) { fwrite(STDERR, $message."\n"); exit(1); }
}

consecutive_absence_expect(HACHE_INTERNAL_CONSECUTIVE_ABSENCE_THRESHOLD===3, 'F5 debe conservar el umbral aprobado de 3 ausencias consecutivas.');
consecutive_absence_expect(HACHE_INTERNAL_CONSECUTIVE_UNJUSTIFIED_THRESHOLD===2, 'F5 debe conservar el umbral aprobado de 2 ausencias no justificadas consecutivas.');

$general=hache_internal_consecutive_absence_state([
    ['estado'=>'AUSENTE_JUSTIFICADA','fecha'=>'2026-09-17'],
    ['estado'=>'AUSENTE_NO_JUSTIFICADA','fecha'=>'2026-09-16'],
    ['estado'=>'AUSENTE_JUSTIFICADA','fecha'=>'2026-09-15'],
]);
consecutive_absence_expect($general['alerta']===true&&$general['ausencias_consecutivas']===3&&$general['no_justificadas_consecutivas']===0,'Tres ausencias de cualquier tipo deben activar la regla general.');

$unjustified=hache_internal_consecutive_absence_state([
    ['estado'=>'AUSENTE_NO_JUSTIFICADA','fecha'=>'2026-09-17'],
    ['estado'=>'AUSENTE_NO_JUSTIFICADA','fecha'=>'2026-09-16'],
]);
consecutive_absence_expect($unjustified['alerta']===true&&$unjustified['ausencias_consecutivas']===2&&$unjustified['no_justificadas_consecutivas']===2,'Dos ausencias no justificadas consecutivas deben activar la alerta temprana.');

$presentBreaks=hache_internal_consecutive_absence_state([
    ['estado'=>'PRESENTE','fecha'=>'2026-09-17'],
    ['estado'=>'AUSENTE_NO_JUSTIFICADA','fecha'=>'2026-09-16'],
    ['estado'=>'AUSENTE_NO_JUSTIFICADA','fecha'=>'2026-09-15'],
]);
consecutive_absence_expect($presentBreaks['alerta']===false,'Una presencia reciente debe cortar las rachas.');

$mixed=hache_internal_consecutive_absence_state([
    ['estado'=>'AUSENTE_NO_JUSTIFICADA','fecha'=>'2026-09-17'],
    ['estado'=>'AUSENTE_JUSTIFICADA','fecha'=>'2026-09-16'],
    ['estado'=>'AUSENTE_NO_JUSTIFICADA','fecha'=>'2026-09-15'],
]);
consecutive_absence_expect($mixed['alerta']===true&&$mixed['ausencias_consecutivas']===3&&$mixed['no_justificadas_consecutivas']===1,'Una justificada mantiene la racha general pero corta la racha específica de no justificadas.');

consecutive_absence_expect(in_array('sqlite',PDO::getAvailableDrivers(),true),'La regresión de F5 requiere PDO SQLite.');
$pdo=new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE horarios (id TEXT PRIMARY KEY,sede_id TEXT NOT NULL)');
$pdo->exec('CREATE TABLE alumnos (id TEXT PRIMARY KEY,nombre TEXT NOT NULL,sede_id TEXT NOT NULL,estado_administrativo TEXT NOT NULL)');
$pdo->exec('CREATE TABLE sesiones (id TEXT PRIMARY KEY,fecha TEXT NOT NULL,horario_id TEXT NOT NULL,estado TEXT NOT NULL,cerrada INTEGER NOT NULL,created_at TEXT NOT NULL)');
$pdo->exec('CREATE TABLE asistencias (id TEXT PRIMARY KEY,sesion_id TEXT NOT NULL,alumno_id TEXT NOT NULL,estado TEXT NOT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL)');
$pdo->exec("INSERT INTO horarios(id,sede_id) VALUES('h-mv','mv'),('h-pal','pal')");

$student=$pdo->prepare('INSERT INTO alumnos(id,nombre,sede_id,estado_administrativo) VALUES(:id,:n,:s,:e)');
$session=$pdo->prepare('INSERT INTO sesiones(id,fecha,horario_id,estado,cerrada,created_at) VALUES(:id,:f,:h,:e,:c,:created)');
$attendance=$pdo->prepare('INSERT INTO asistencias(id,sesion_id,alumno_id,estado,created_at,updated_at) VALUES(:id,:ss,:a,:e,:c,:u)');

$add=function(string $studentId,string $name,array $marks,string $site='mv',string $status='ACTIVO') use($student,$session,$attendance): void {
    $student->execute([':id'=>$studentId,':n'=>$name,':s'=>$site,':e'=>$status]);
    foreach($marks as $i=>$mark){
        $sid=$studentId.'-s'.$i;
        $date=(string)$mark['fecha'];
        $session->execute([
            ':id'=>$sid,':f'=>$date,':h'=>$site==='mv'?'h-mv':'h-pal',
            ':e'=>(string)($mark['sesion_estado']??'REALIZADA'),
            ':c'=>(int)($mark['cerrada']??1),
            ':created'=>$date.' 10:00:00',
        ]);
        if(($mark['estado']??null)!==null){
            $attendance->execute([
                ':id'=>$studentId.'-a'.$i,':ss'=>$sid,':a'=>$studentId,':e'=>(string)$mark['estado'],
                ':c'=>$date.' 10:30:00',':u'=>$date.' 10:30:00',
            ]);
        }
    }
};

$add('a','Tres justificadas',[
    ['fecha'=>'2026-09-17','estado'=>'AUSENTE_JUSTIFICADA'],
    ['fecha'=>'2026-09-16','estado'=>'AUSENTE_JUSTIFICADA'],
    ['fecha'=>'2026-09-15','estado'=>'AUSENTE_JUSTIFICADA'],
]);
$add('b','Dos no justificadas',[
    ['fecha'=>'2026-09-17','estado'=>'AUSENTE_NO_JUSTIFICADA'],
    ['fecha'=>'2026-09-16','estado'=>'AUSENTE_NO_JUSTIFICADA'],
]);
$add('c','Presente corta',[
    ['fecha'=>'2026-09-17','estado'=>'PRESENTE'],
    ['fecha'=>'2026-09-16','estado'=>'AUSENTE_NO_JUSTIFICADA'],
    ['fecha'=>'2026-09-15','estado'=>'AUSENTE_NO_JUSTIFICADA'],
]);
$add('d','Cancelada no cuenta',[
    ['fecha'=>'2026-09-17','estado'=>'AUSENTE_JUSTIFICADA','sesion_estado'=>'CANCELADA'],
    ['fecha'=>'2026-09-16','estado'=>'AUSENTE_JUSTIFICADA'],
    ['fecha'=>'2026-09-15','estado'=>'AUSENTE_JUSTIFICADA'],
]);
$add('e','Abierta no cuenta',[
    ['fecha'=>'2026-09-17','estado'=>'AUSENTE_JUSTIFICADA','cerrada'=>0],
    ['fecha'=>'2026-09-16','estado'=>'AUSENTE_JUSTIFICADA'],
    ['fecha'=>'2026-09-15','estado'=>'AUSENTE_JUSTIFICADA'],
]);
$add('f','Baja excluida',[
    ['fecha'=>'2026-09-17','estado'=>'AUSENTE_NO_JUSTIFICADA'],
    ['fecha'=>'2026-09-16','estado'=>'AUSENTE_NO_JUSTIFICADA'],
    ['fecha'=>'2026-09-15','estado'=>'AUSENTE_NO_JUSTIFICADA'],
],'mv','BAJA');
$add('g','Otra sede',[
    ['fecha'=>'2026-09-17','estado'=>'AUSENTE_NO_JUSTIFICADA'],
    ['fecha'=>'2026-09-16','estado'=>'AUSENTE_NO_JUSTIFICADA'],
],'pal');
$add('h','Mixta general',[
    ['fecha'=>'2026-09-17','estado'=>'AUSENTE_NO_JUSTIFICADA'],
    ['fecha'=>'2026-09-16','estado'=>'AUSENTE_JUSTIFICADA'],
    ['fecha'=>'2026-09-15','estado'=>'AUSENTE_NO_JUSTIFICADA'],
]);
$add('i','Sin marca no inventa',[
    ['fecha'=>'2026-09-17','estado'=>null],
    ['fecha'=>'2026-09-16','estado'=>'AUSENTE_JUSTIFICADA'],
    ['fecha'=>'2026-09-15','estado'=>'AUSENTE_JUSTIFICADA'],
]);
$add('j','Ambos criterios una sola alerta',[
    ['fecha'=>'2026-09-17','estado'=>'AUSENTE_NO_JUSTIFICADA'],
    ['fecha'=>'2026-09-16','estado'=>'AUSENTE_NO_JUSTIFICADA'],
    ['fecha'=>'2026-09-15','estado'=>'AUSENTE_NO_JUSTIFICADA'],
]);

$candidates=hache_internal_consecutive_absence_candidates($pdo,'mv');
$byId=[];foreach($candidates as $candidate)$byId[$candidate['alumno_id']]=$candidate;
consecutive_absence_expect(isset($byId['a'])&&$byId['a']['ausencias_consecutivas']===3,'Tres justificadas cerradas deben aparecer.');
consecutive_absence_expect(isset($byId['b'])&&$byId['b']['no_justificadas_consecutivas']===2,'Dos no justificadas cerradas deben aparecer.');
consecutive_absence_expect(!isset($byId['c']),'PRESENTE debe impedir una alerta por racha anterior.');
consecutive_absence_expect(!isset($byId['d']),'Una clase cancelada no debe completar la racha.');
consecutive_absence_expect(!isset($byId['e']),'Una sesión todavía abierta no debe completar la racha.');
consecutive_absence_expect(!isset($byId['f'])&&!isset($byId['g']),'BAJA y otra sede deben quedar fuera del alcance.');
consecutive_absence_expect(isset($byId['h'])&&$byId['h']['ausencias_consecutivas']===3&&$byId['h']['no_justificadas_consecutivas']===1,'La mezcla debe activar solo el umbral general.');
consecutive_absence_expect(!isset($byId['i']),'Una sesión sin marca no debe convertirse automáticamente en ausencia.');
consecutive_absence_expect(isset($byId['j'])&&$byId['j']['ausencias_consecutivas']===3&&$byId['j']['no_justificadas_consecutivas']===3,'Un alumno que cumple ambos criterios debe producir un solo candidato.');
consecutive_absence_expect(count(array_filter($candidates,static fn(array $c):bool=>$c['alumno_id']==='j'))===1,'Los dos umbrales no deben duplicar el mismo alumno.');

$api=file_get_contents(__DIR__.'/../api/alertas.php')?:'';
$doc=file_get_contents(__DIR__.'/../docs/F5-CONSECUTIVE-ABSENCE-ALERT.md')?:'';
consecutive_absence_expect(str_contains($api,'hache_internal_consecutive_absence_candidates')&&str_contains($api,"'nivel'=>'NEUTRA'"),'El Centro de alertas debe consumir la regla sin inventar una prioridad.');
consecutive_absence_expect(str_contains($doc,'3 ausencias consecutivas')&&str_contains($doc,'2 ausencias no justificadas consecutivas')&&str_contains($doc,'sesiones sin marca'),'Los umbrales y exclusiones deben quedar documentados.');

echo "Consecutive absence internal alert regression: OK\n";
