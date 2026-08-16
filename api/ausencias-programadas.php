<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
$config=require __DIR__.'/../config/database.php';
$pdo=new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
function out(array $d,int $c=200):never{http_response_code($c);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function uid(PDO $pdo):string{$id=(string)$pdo->query("SELECT id FROM usuarios WHERE activo=1 ORDER BY CASE WHEN rol='ADMIN' THEN 0 ELSE 1 END,created_at LIMIT 1")->fetchColumn();if(!$id)throw new RuntimeException('No hay usuario activo');return $id;}
try{
 if($_SERVER['REQUEST_METHOD']==='GET'){
  $rows=$pdo->query("SELECT aa.id,aa.alumno_id,a.nombre,aa.fecha_desde,aa.fecha_hasta,aa.motivo,aa.estado,aa.created_at FROM avisos_ausencia aa JOIN alumnos a ON a.id=aa.alumno_id ORDER BY aa.fecha_desde DESC,a.nombre")->fetchAll();
  $repos=$pdo->query("SELECT rr.alumno_id,a.nombre,COUNT(*) disponibles FROM reposiciones_regulares rr JOIN alumnos a ON a.id=rr.alumno_id WHERE rr.estado='DISPONIBLE' GROUP BY rr.alumno_id,a.nombre ORDER BY a.nombre")->fetchAll();
  out(['ok'=>true,'avisos'=>$rows,'reposiciones'=>$repos]);
 }
 if($_SERVER['REQUEST_METHOD']!=='POST')out(['ok'=>false,'error'=>'Método no permitido'],405);
 $in=json_decode(file_get_contents('php://input'),true)?:[];$accion=strtoupper((string)($in['accion']??''));
 if($accion==='CREAR'){
  $aid=(string)($in['alumno_id']??'');$d=(string)($in['fecha_desde']??'');$h=(string)($in['fecha_hasta']??$d);if(!$aid||!$d||!$h)out(['ok'=>false,'error'=>'Completa alumno y fechas'],422);
  $st=$pdo->prepare("INSERT INTO avisos_ausencia(alumno_id,fecha_desde,fecha_hasta,motivo,created_by) VALUES(:a,:d,:h,:m,:u)");$st->execute([':a'=>$aid,':d'=>$d,':h'=>$h,':m'=>trim((string)($in['motivo']??''))?:null,':u'=>uid($pdo)]);out(['ok'=>true]);
 }
 if($accion==='CANCELAR'){$id=(string)($in['id']??'');$st=$pdo->prepare("UPDATE avisos_ausencia SET estado='CANCELADO',cancelled_at=NOW() WHERE id=:id AND estado='ACTIVO'");$st->execute([':id'=>$id]);out(['ok'=>true]);}
 out(['ok'=>false,'error'=>'Acción inválida'],422);
}catch(Throwable $e){out(['ok'=>false,'error'=>$e->getMessage()],500);}