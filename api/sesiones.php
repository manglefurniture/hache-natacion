<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
$config=require __DIR__.'/../config/database.php';
$pdo=new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
function out(array $d,int $c=200):never{http_response_code($c);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function usuario(PDO $pdo):string{$id=(string)$pdo->query("SELECT id FROM usuarios WHERE activo=1 ORDER BY CASE WHEN rol='ADMIN' THEN 0 ELSE 1 END,created_at LIMIT 1")->fetchColumn();if(!$id)throw new RuntimeException('No hay usuario activo.');return $id;}
try{
 if($_SERVER['REQUEST_METHOD']==='GET'){
  $fecha=$_GET['fecha']??date('Y-m-d');
  $stmt=$pdo->prepare("SELECT s.id,s.fecha,s.bloque,s.horario_id,s.estado,s.cerrada,s.motivo_cancelacion,h.hora_inicio,h.hora_fin FROM sesiones s LEFT JOIN horarios h ON h.id=s.horario_id WHERE s.fecha=:f ORDER BY h.hora_inicio,s.bloque");$stmt->execute([':f'=>$fecha]);$sesiones=$stmt->fetchAll();
  foreach($sesiones as &$s){
   if(!$s['horario_id']){$s['alumnos']=[];continue;}
   $q=$pdo->prepare("SELECT a.id,a.nombre,a.estado_administrativo,p.sesiones_semana,m.estado mensualidad_estado,aa.estado asistencia_estado,aa.observacion,
   EXISTS(SELECT 1 FROM curso_intensivo_alumnos cia JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id WHERE cia.alumno_id=a.id AND cia.horario_id=:h1 AND ci.estado IN ('PROGRAMADO','EN_CURSO') AND :f1 BETWEEN ci.fecha_inicio AND ci.fecha_fin) intensivo_activo
   FROM alumnos a LEFT JOIN planes p ON p.id=a.plan_actual_id LEFT JOIN mensualidades m ON m.alumno_id=a.id AND m.mes=MONTH(:f2) AND m.anio=YEAR(:f3)
   LEFT JOIN asistencias aa ON aa.sesion_id=:sid AND aa.alumno_id=a.id
   WHERE a.horario_preferido_id=:h2 OR EXISTS(SELECT 1 FROM curso_intensivo_alumnos cia2 JOIN cursos_intensivos ci2 ON ci2.id=cia2.curso_intensivo_id WHERE cia2.alumno_id=a.id AND cia2.horario_id=:h3 AND ci2.estado IN ('PROGRAMADO','EN_CURSO') AND :f4 BETWEEN ci2.fecha_inicio AND ci2.fecha_fin)
   ORDER BY a.nombre");
   $q->execute([':h1'=>$s['horario_id'],':f1'=>$fecha,':f2'=>$fecha,':f3'=>$fecha,':sid'=>$s['id'],':h2'=>$s['horario_id'],':h3'=>$s['horario_id'],':f4'=>$fecha]);$s['alumnos']=$q->fetchAll();
  }unset($s);out(['ok'=>true,'fecha'=>$fecha,'sesiones'=>$sesiones]);
 }
 if($_SERVER['REQUEST_METHOD']!=='POST')out(['ok'=>false,'error'=>'Método no permitido'],405);
 $in=json_decode(file_get_contents('php://input'),true)?:[];$accion=strtoupper((string)($in['accion']??''));$uid=usuario($pdo);
 if($accion==='CREAR'){$fecha=(string)($in['fecha']??date('Y-m-d'));$horario=(string)($in['horario_id']??'');if(!$horario)out(['ok'=>false,'error'=>'Falta horario'],422);$h=$pdo->prepare("SELECT hora_inicio FROM horarios WHERE id=:id AND activo=1");$h->execute([':id'=>$horario]);$hora=$h->fetchColumn();if(!$hora)out(['ok'=>false,'error'=>'Horario inválido'],422);$bloque=((int)substr((string)$hora,0,2)<12)?'AM':'PM';$st=$pdo->prepare("INSERT INTO sesiones(fecha,bloque,horario_id,created_by) VALUES(:f,:b,:h,:u)");$st->execute([':f'=>$fecha,':b'=>$bloque,':h'=>$horario,':u'=>$uid]);out(['ok'=>true,'id'=>$pdo->lastInsertId()]);}
 if($accion==='ASISTENCIA'){$sid=(string)($in['sesion_id']??'');$aid=(string)($in['alumno_id']??'');$estado=(string)($in['estado']??'');if(!in_array($estado,['PRESENTE','AUSENTE_JUSTIFICADA','AUSENTE_NO_JUSTIFICADA'],true))out(['ok'=>false,'error'=>'Estado inválido'],422);$st=$pdo->prepare("INSERT INTO asistencias(sesion_id,alumno_id,estado,observacion,created_by) VALUES(:s,:a,:e,:o,:u) ON DUPLICATE KEY UPDATE estado=VALUES(estado),observacion=VALUES(observacion),updated_at=NOW()");$st->execute([':s'=>$sid,':a'=>$aid,':e'=>$estado,':o'=>($in['observacion']??null),':u'=>$uid]);out(['ok'=>true]);}
 if($accion==='CERRAR'){$sid=(string)($in['sesion_id']??'');$st=$pdo->prepare("UPDATE sesiones SET estado='REALIZADA',cerrada=1,fecha_cierre=NOW(),cerrada_por=:u WHERE id=:s AND cerrada=0");$st->execute([':u'=>$uid,':s'=>$sid]);out(['ok'=>true]);}
 if($accion==='CANCELAR'){$sid=(string)($in['sesion_id']??'');$mot=trim((string)($in['motivo']??''));if(!$mot)out(['ok'=>false,'error'=>'Indica el motivo'],422);$st=$pdo->prepare("UPDATE sesiones SET estado='CANCELADA',motivo_cancelacion=:m,cerrada=1,fecha_cierre=NOW(),cerrada_por=:u WHERE id=:s");$st->execute([':m'=>$mot,':u'=>$uid,':s'=>$sid]);out(['ok'=>true]);}
 out(['ok'=>false,'error'=>'Acción inválida'],422);
}catch(Throwable $e){out(['ok'=>false,'error'=>$e->getMessage()],500);}
