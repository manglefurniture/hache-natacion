<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/auth.php';
auth_require(['ADMIN','VERIFICADOR']);
$config=require __DIR__.'/../config/database.php';
$pdo=new PDO(
    "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",
    $config['user'],
    $config['password'],
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]
);

function out(array $data,int $status=200):never
{
    http_response_code($status);
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

try{
    $studentId=trim((string)($_GET['alumno_id']??''));
    if($studentId==='')out(['ok'=>false,'error'=>'alumno_id obligatorio'],422);
    $siteKey=auth_active_sede_clave();
    $stmt=$pdo->prepare('SELECT a.sede_id FROM alumnos a JOIN sedes s ON s.id=a.sede_id WHERE a.id=:student AND s.clave=:site AND s.activo=1 LIMIT 1');
    $stmt->execute([':student'=>$studentId,':site'=>$siteKey]);
    $siteId=(string)$stmt->fetchColumn();
    if($siteId==='')out(['ok'=>false,'error'=>'Alumno no encontrado en la sede activa'],404);

    $items=[];
    $push=function(string $date,string $type,string $title,string $detail='',?string $href=null)use(&$items):void{
        $items[]=['fecha'=>$date,'tipo'=>$type,'titulo'=>$title,'detalle'=>$detail,'href'=>$href];
    };

    $stmt=$pdo->prepare("SELECT p.fecha,p.tipo,p.importe,p.metodo,p.estado,p.folio,p.observacion FROM pagos p JOIN alumnos a ON a.id=p.alumno_id WHERE p.alumno_id=:student AND a.sede_id=:site ORDER BY p.fecha");
    $stmt->execute([':student'=>$studentId,':site'=>$siteId]);
    foreach($stmt as $row){
        $push($row['fecha'],'PAGO',$row['tipo'].' · $'.number_format((float)$row['importe'],0),'Folio '.$row['folio'].' · '.$row['metodo'].($row['estado']!=='VALIDO'?' · '.$row['estado']:'').($row['observacion']?' · '.$row['observacion']:''),'/pago-detalle.php?folio='.rawurlencode((string)$row['folio']));
    }

    $stmt=$pdo->prepare('SELECT ci.id,ci.fecha_inicio,ci.fecha_fin,ci.estado,cia.continua_regular,cia.observacion_continuidad FROM curso_intensivo_alumnos cia JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id WHERE cia.alumno_id=:student AND ci.sede_id=:site ORDER BY ci.fecha_inicio');
    $stmt->execute([':student'=>$studentId,':site'=>$siteId]);
    foreach($stmt as $row){
        $push($row['fecha_inicio'].' 00:00:00','INTENSIVO','Curso intensivo','Del '.date('d/m/Y',strtotime($row['fecha_inicio'])).' al '.date('d/m/Y',strtotime($row['fecha_fin'])).' · '.$row['estado'].($row['continua_regular']?' · Continuó a regular':'').($row['observacion_continuidad']?' · '.$row['observacion_continuidad']:''),'/intensivo-detalle.php?id='.rawurlencode((string)$row['id']));
    }

    $stmt=$pdo->prepare('SELECT s.id sesion_id,s.fecha,a.estado,a.observacion FROM asistencias a JOIN sesiones s ON s.id=a.sesion_id JOIN horarios h ON h.id=s.horario_id WHERE a.alumno_id=:student AND h.sede_id=:site ORDER BY s.fecha');
    $stmt->execute([':student'=>$studentId,':site'=>$siteId]);
    foreach($stmt as $row){
        $push($row['fecha'].' 12:00:00','ASISTENCIA',$row['estado'],$row['observacion']??'','/sesiones.php?fecha='.rawurlencode((string)$row['fecha']).'&alumno_id='.rawurlencode($studentId));
    }

    $stmt=$pdo->prepare('SELECT aa.fecha_desde,aa.fecha_hasta,aa.motivo,aa.estado FROM avisos_ausencia aa JOIN alumnos a ON a.id=aa.alumno_id WHERE aa.alumno_id=:student AND a.sede_id=:site ORDER BY aa.fecha_desde');
    $stmt->execute([':student'=>$studentId,':site'=>$siteId]);
    foreach($stmt as $row){
        $push($row['fecha_desde'].' 00:00:00','AUSENCIA','Aviso de ausencia · '.$row['estado'],'Hasta '.date('d/m/Y',strtotime($row['fecha_hasta'])).' · '.$row['motivo'],'/ausencias.php?alumno_id='.rawurlencode($studentId));
    }

    $stmt=$pdo->prepare('SELECT h.fecha_hora,h.tipo,h.descripcion FROM historial h JOIN alumnos a ON a.id=h.alumno_id WHERE h.alumno_id=:student AND a.sede_id=:site ORDER BY h.fecha_hora');
    $stmt->execute([':student'=>$studentId,':site'=>$siteId]);
    foreach($stmt as $row)$push($row['fecha_hora'],'HISTORIAL',$row['tipo'],$row['descripcion']);

    usort($items,fn(array $a,array $b):int=>strcmp($b['fecha'],$a['fecha']));
    out(['ok'=>true,'items'=>$items]);
}catch(Throwable $e){
    error_log('[timeline-alumno] '.$e->getMessage());
    out(['ok'=>false,'error'=>'No se pudo cargar el historial del alumno'],500);
}
