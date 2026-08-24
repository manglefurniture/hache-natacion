<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/auth.php';
$config=require __DIR__.'/../config/database.php';
$pdo=new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
function out(array $d,int $c=200):never{http_response_code($c);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function siteId(PDO $pdo,string $clave):string{$st=$pdo->prepare("SELECT id FROM sedes WHERE clave=:c AND activo=1 LIMIT 1");$st->execute([':c'=>$clave]);$id=(string)$st->fetchColumn();if(!$id)out(['ok'=>false,'error'=>'Sede inválida'],422);return $id;}
try{
 $method=$_SERVER['REQUEST_METHOD']??'GET';
 if($method==='GET'){
  auth_require(['ADMIN','VERIFICADOR']);$clave=auth_resolve_sede_clave((string)($_GET['sede']??'MONTEVERDE'));$sid=siteId($pdo,$clave);
  $st=$pdo->prepare("SELECT aa.id,aa.alumno_id,a.nombre,aa.fecha_desde,aa.fecha_hasta,aa.motivo,aa.estado,aa.created_at FROM avisos_ausencia aa JOIN alumnos a ON a.id=aa.alumno_id WHERE a.sede_id=:s ORDER BY aa.fecha_desde DESC,a.nombre");$st->execute([':s'=>$sid]);$rows=$st->fetchAll();
  $st=$pdo->prepare("SELECT rr.alumno_id,a.nombre,COUNT(*) disponibles FROM reposiciones_regulares rr JOIN alumnos a ON a.id=rr.alumno_id WHERE rr.estado='DISPONIBLE' AND a.sede_id=:s GROUP BY rr.alumno_id,a.nombre ORDER BY a.nombre");$st->execute([':s'=>$sid]);$repos=$st->fetchAll();
  out(['ok'=>true,'avisos'=>$rows,'reposiciones'=>$repos]);
 }
 auth_require(['ADMIN']);if($method!=='POST')out(['ok'=>false,'error'=>'Método no permitido'],405);
 $in=json_decode(file_get_contents('php://input'),true);if(!is_array($in))out(['ok'=>false,'error'=>'JSON inválido'],400);$accion=strtoupper((string)($in['accion']??''));$clave=auth_resolve_sede_clave((string)($in['sede']??'MONTEVERDE'));$sid=siteId($pdo,$clave);
 if($accion==='CREAR'){$aid=(string)($in['alumno_id']??'');$d=(string)($in['fecha_desde']??'');$h=(string)($in['fecha_hasta']??$d);$motivo=trim((string)($in['motivo']??''));if(!$aid||!$d||!$h)out(['ok'=>false,'error'=>'Completa alumno y fechas'],422);$desde=DateTimeImmutable::createFromFormat('!Y-m-d',$d);$hasta=DateTimeImmutable::createFromFormat('!Y-m-d',$h);if(!$desde||!$hasta||$desde->format('Y-m-d')!==$d||$hasta->format('Y-m-d')!==$h||$hasta<$desde)out(['ok'=>false,'error'=>'El rango de fechas no es válido'],422);if(mb_strlen($motivo)>500)out(['ok'=>false,'error'=>'El motivo no puede exceder 500 caracteres'],422);$pdo->beginTransaction();$st=$pdo->prepare("SELECT id FROM alumnos WHERE id=:a AND sede_id=:s LIMIT 1 FOR UPDATE");$st->execute([':a'=>$aid,':s'=>$sid]);if(!$st->fetch()){$pdo->rollBack();out(['ok'=>false,'error'=>'El alumno no pertenece a la sede seleccionada'],422);}$st=$pdo->prepare("SELECT id FROM avisos_ausencia WHERE alumno_id=:a AND fecha_desde=:d AND fecha_hasta=:h AND estado='ACTIVO' LIMIT 1");$st->execute([':a'=>$aid,':d'=>$d,':h'=>$h]);if($st->fetch()){$pdo->rollBack();out(['ok'=>false,'error'=>'Ya existe un aviso activo para ese mismo rango'],409);}$me=auth_user();$st=$pdo->prepare("INSERT INTO avisos_ausencia(alumno_id,fecha_desde,fecha_hasta,motivo,created_by) VALUES(:a,:d,:h,:m,:u)");$st->execute([':a'=>$aid,':d'=>$d,':h'=>$h,':m'=>$motivo!==''?$motivo:null,':u'=>$me['id']]);$pdo->commit();out(['ok'=>true]);}
 if($accion==='CANCELAR'){$id=(string)($in['id']??'');$st=$pdo->prepare("UPDATE avisos_ausencia aa JOIN alumnos a ON a.id=aa.alumno_id SET aa.estado='CANCELADO',aa.cancelled_at=NOW() WHERE aa.id=:id AND aa.estado='ACTIVO' AND a.sede_id=:s");$st->execute([':id'=>$id,':s'=>$sid]);if(!$st->rowCount())out(['ok'=>false,'error'=>'Aviso no encontrado o ya cancelado'],404);out(['ok'=>true]);}
 out(['ok'=>false,'error'=>'Acción inválida'],422);
}catch(Throwable $e){if(isset($pdo)&&$pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();error_log('[ausencias-programadas] '.$e->getMessage());out(['ok'=>false,'error'=>'No se pudieron procesar las ausencias'],500);}
