<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/auth.php';
$me=auth_require(['ALUMNO']);
$config=require __DIR__.'/../config/database.php';
$pdo=new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
function out(array $d,int $c=200):never{http_response_code($c);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
$aid=(string)$me['alumno_id'];
try{
 if($_SERVER['REQUEST_METHOD']==='GET'){
  $st=$pdo->prepare("SELECT a.id,a.nombre,a.whatsapp,a.correo,a.estado_administrativo,p.nombre plan_nombre,p.sesiones_semana,p.precio,h.hora_inicio,h.hora_fin FROM alumnos a LEFT JOIN planes p ON p.id=a.plan_actual_id LEFT JOIN horarios h ON h.id=a.horario_preferido_id WHERE a.id=:id LIMIT 1");$st->execute([':id'=>$aid]);$alumno=$st->fetch();if(!$alumno)out(['ok'=>false,'error'=>'Alumno no encontrado'],404);
  $st=$pdo->prepare("SELECT folio,tipo,importe,metodo,fecha,estado,observacion FROM pagos WHERE alumno_id=:a ORDER BY fecha DESC,folio DESC LIMIT 24");$st->execute([':a'=>$aid]);$pagos=$st->fetchAll();
  $st=$pdo->prepare("SELECT id,fecha_desde,fecha_hasta,motivo,estado,created_at FROM avisos_ausencia WHERE alumno_id=:a ORDER BY fecha_desde DESC,created_at DESC LIMIT 24");$st->execute([':a'=>$aid]);$avisos=$st->fetchAll();
  $st=$pdo->prepare("SELECT rr.id,rr.estado,rr.created_at,rr.used_at,s.fecha fecha_ausencia FROM reposiciones_regulares rr JOIN asistencias aa ON aa.id=rr.ausencia_asistencia_id JOIN sesiones s ON s.id=aa.sesion_id WHERE rr.alumno_id=:a ORDER BY s.fecha DESC");$st->execute([':a'=>$aid]);$repos=$st->fetchAll();
  $st=$pdo->prepare("SELECT ci.id,ci.fecha_inicio,ci.fecha_fin,ci.estado,cia.reposiciones_justificadas,cia.reposiciones_cancelacion,h.hora_inicio,h.hora_fin FROM curso_intensivo_alumnos cia JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id LEFT JOIN horarios h ON h.id=cia.horario_id WHERE cia.alumno_id=:a ORDER BY ci.fecha_inicio DESC LIMIT 6");$st->execute([':a'=>$aid]);$intensivos=$st->fetchAll();
  out(['ok'=>true,'alumno'=>$alumno,'pagos'=>$pagos,'avisos'=>$avisos,'reposiciones'=>$repos,'intensivos'=>$intensivos]);
 }
 if($_SERVER['REQUEST_METHOD']!=='POST')out(['ok'=>false,'error'=>'Método no permitido'],405);
 $in=json_decode(file_get_contents('php://input'),true)?:[];$accion=strtoupper((string)($in['accion']??''));
 if($accion==='AVISO'){
  $desde=(string)($in['fecha_desde']??'');$hasta=(string)($in['fecha_hasta']??$desde);$motivo=trim((string)($in['motivo']??''));
  if(!$desde||!$hasta||$motivo==='')out(['ok'=>false,'error'=>'Completa las fechas y el motivo'],422);
  if($hasta<$desde)out(['ok'=>false,'error'=>'La fecha final no puede ser anterior a la inicial'],422);
  if($hasta<date('Y-m-d'))out(['ok'=>false,'error'=>'El aviso debe corresponder a una fecha actual o futura'],422);
  $id=(string)$pdo->query("SELECT UUID()")->fetchColumn();$st=$pdo->prepare("INSERT INTO avisos_ausencia(id,alumno_id,fecha_desde,fecha_hasta,motivo,estado,created_by) VALUES(:id,:a,:d,:h,:m,'ACTIVO',:u)");$st->execute([':id'=>$id,':a'=>$aid,':d'=>$desde,':h'=>$hasta,':m'=>$motivo,':u'=>$me['id']]);out(['ok'=>true,'id'=>$id]);
 }
 if($accion==='CANCELAR_AVISO'){
  $id=(string)($in['id']??'');$st=$pdo->prepare("UPDATE avisos_ausencia SET estado='CANCELADO' WHERE id=:id AND alumno_id=:a AND estado='ACTIVO'");$st->execute([':id'=>$id,':a'=>$aid]);out(['ok'=>true]);
 }
 out(['ok'=>false,'error'=>'Acción inválida'],422);
}catch(Throwable $e){out(['ok'=>false,'error'=>$e->getMessage()],500);}
