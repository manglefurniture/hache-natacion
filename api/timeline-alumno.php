<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/auth.php';
require_once __DIR__.'/../config/expediente-360.php';
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
    $push=function(string $date,string $type,string $title,string $detail='',?string $href=null,?string $dateLabel=null,string $source='')use(&$items):void{
        $items[]=['fecha'=>$date,'fecha_etiqueta'=>$dateLabel,'tipo'=>$type,'titulo'=>$title,'detalle'=>$detail,'href'=>$href,'origen'=>$source];
    };

    $stmt=$pdo->prepare("SELECT p.fecha,p.tipo,p.importe,p.metodo,p.estado,p.folio,p.observacion FROM pagos p JOIN alumnos a ON a.id=p.alumno_id WHERE p.alumno_id=:student AND a.sede_id=:site ORDER BY p.fecha");
    $stmt->execute([':student'=>$studentId,':site'=>$siteId]);
    foreach($stmt as $row){
        $observacion=(string)($row['observacion']??'');
        $historicoImpreciso=hache_expediente_pago_historico_fecha_imprecisa((string)$row['metodo'],$observacion);
        $metodo=strtoupper((string)$row['metodo'])==='NO_REGISTRADO'
            ? 'Método no registrado'
            : (string)$row['metodo'];
        if($historicoImpreciso)$observacion=hache_expediente_limpiar_marca_pago_historico($observacion);
        $detalle='Fuente: Pagos · Folio '.$row['folio'].' · '.$metodo
            .($row['estado']!=='VALIDO'?' · '.$row['estado']:'')
            .($observacion!==''?' · '.$observacion:'');
        $fechaEtiqueta=$historicoImpreciso
            ? date('m/Y',strtotime((string)$row['fecha'])).' · fecha exacta no registrada'
            : null;
        $push($row['fecha'],'PAGO',$row['tipo'].' · $'.number_format((float)$row['importe'],0),$detalle,'/pago-detalle.php?folio='.rawurlencode((string)$row['folio']),$fechaEtiqueta,'Pagos');
    }

    $stmt=$pdo->prepare('SELECT ci.id,ci.fecha_inicio,ci.fecha_fin,ci.estado,cia.reposiciones_justificadas,cia.reposiciones_cancelacion,cia.continua_regular,cia.observacion_continuidad FROM curso_intensivo_alumnos cia JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id WHERE cia.alumno_id=:student AND ci.sede_id=:site ORDER BY ci.fecha_inicio');
    $stmt->execute([':student'=>$studentId,':site'=>$siteId]);
    foreach($stmt as $row){
        $reposJust=(int)$row['reposiciones_justificadas'];
        $reposCancel=(int)$row['reposiciones_cancelacion'];
        $detalle='Fuente: Curso intensivo · Del '.date('d/m/Y',strtotime($row['fecha_inicio'])).' al '.date('d/m/Y',strtotime($row['fecha_fin'])).' · '.$row['estado'];
        if($reposJust>0||$reposCancel>0)$detalle.=' · Reposiciones: justificadas '.$reposJust.' / cancelación '.$reposCancel;
        if($row['continua_regular'])$detalle.=' · Continuó a regular';
        if($row['observacion_continuidad'])$detalle.=' · '.$row['observacion_continuidad'];
        $push($row['fecha_inicio'].' 00:00:00','INTENSIVO','Curso intensivo',$detalle,'/intensivo-detalle.php?id='.rawurlencode((string)$row['id']),null,'Curso intensivo');
    }

    $stmt=$pdo->prepare('SELECT s.id sesion_id,s.fecha,a.estado,a.observacion FROM asistencias a JOIN sesiones s ON s.id=a.sesion_id JOIN horarios h ON h.id=s.horario_id WHERE a.alumno_id=:student AND h.sede_id=:site ORDER BY s.fecha');
    $stmt->execute([':student'=>$studentId,':site'=>$siteId]);
    foreach($stmt as $row){
        $detalle='Fuente: Asistencia'.(!empty($row['observacion'])?' · '.$row['observacion']:'');
        $push($row['fecha'].' 12:00:00','ASISTENCIA',$row['estado'],$detalle,'/sesiones.php?fecha='.rawurlencode((string)$row['fecha']).'&alumno_id='.rawurlencode($studentId),null,'Asistencia');
    }

    $stmt=$pdo->prepare('SELECT aa.fecha_desde,aa.fecha_hasta,aa.motivo,aa.estado FROM avisos_ausencia aa JOIN alumnos a ON a.id=aa.alumno_id WHERE aa.alumno_id=:student AND a.sede_id=:site ORDER BY aa.fecha_desde');
    $stmt->execute([':student'=>$studentId,':site'=>$siteId]);
    foreach($stmt as $row){
        $detalle='Fuente: Aviso de ausencia · Hasta '.date('d/m/Y',strtotime($row['fecha_hasta'])).' · '.$row['motivo'];
        $push($row['fecha_desde'].' 00:00:00','AUSENCIA','Aviso de ausencia · '.$row['estado'],$detalle,'/ausencias.php?alumno_id='.rawurlencode($studentId),null,'Avisos de ausencia');
    }

    $stmt=$pdo->prepare('SELECT rr.estado,rr.created_at,rr.used_at,sa.fecha AS ausencia_fecha,sr.fecha AS reposicion_fecha FROM reposiciones_regulares rr JOIN alumnos al ON al.id=rr.alumno_id JOIN asistencias aa ON aa.id=rr.ausencia_asistencia_id AND aa.alumno_id=rr.alumno_id JOIN sesiones sa ON sa.id=aa.sesion_id JOIN horarios ha ON ha.id=sa.horario_id AND ha.sede_id=:site_source LEFT JOIN sesiones sr ON sr.id=rr.sesion_reposicion_id WHERE rr.alumno_id=:student AND al.sede_id=:site_student ORDER BY rr.created_at');
    $stmt->execute([':student'=>$studentId,':site_source'=>$siteId,':site_student'=>$siteId]);
    foreach($stmt as $row){
        $detalle='Fuente: Reposición regular · Ausencia '.date('d/m/Y',strtotime((string)$row['ausencia_fecha']));
        if(!empty($row['reposicion_fecha']))$detalle.=' · Clase de reposición '.date('d/m/Y',strtotime((string)$row['reposicion_fecha']));
        if(!empty($row['used_at']))$detalle.=' · Uso registrado '.date('d/m/Y H:i',strtotime((string)$row['used_at']));
        $fechaOrigen=!empty($row['reposicion_fecha'])?(string)$row['reposicion_fecha']:(string)$row['ausencia_fecha'];
        $push((string)$row['created_at'],'REPOSICIÓN REGULAR','Reposición regular · '.$row['estado'],$detalle,'/sesiones.php?fecha='.rawurlencode($fechaOrigen).'&alumno_id='.rawurlencode($studentId),null,'Reposiciones regulares');
    }

    $stmt=$pdo->prepare('SELECT h.fecha_hora,h.tipo,h.descripcion FROM historial h JOIN alumnos a ON a.id=h.alumno_id WHERE h.alumno_id=:student AND a.sede_id=:site ORDER BY h.fecha_hora');
    $stmt->execute([':student'=>$studentId,':site'=>$siteId]);
    foreach($stmt as $row)$push($row['fecha_hora'],'HISTORIAL',$row['tipo'],'Fuente: Historial administrativo · '.$row['descripcion'],null,null,'Historial administrativo');

    usort($items,fn(array $a,array $b):int=>strcmp($b['fecha'],$a['fecha']));
    out(['ok'=>true,'items'=>$items]);
}catch(Throwable $e){
    error_log('[timeline-alumno] '.$e->getMessage());
    out(['ok'=>false,'error'=>'No se pudo cargar el historial del alumno'],500);
}
