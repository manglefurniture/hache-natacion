<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/auth.php';
require_once __DIR__.'/../config/intensivos-estado.php';
$config=require __DIR__.'/../config/database.php';
function out(array $d,int $c=200):never{http_response_code($c);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);exit;}
try{
 $pdo=new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
 $method=$_SERVER['REQUEST_METHOD']??'GET';
 if($method==='GET'){
  auth_require(['ADMIN','VERIFICADOR']);$clave=auth_resolve_sede_clave((string)($_GET['sede']??'MONTEVERDE'));
  $st=$pdo->prepare("SELECT s.id FROM sedes s WHERE s.clave=:c AND s.activo=1 LIMIT 1");$st->execute([':c'=>$clave]);$sid=$st->fetchColumn();if(!$sid)out(['ok'=>false,'error'=>'Sede inválida'],422);
  $st=$pdo->prepare("SELECT ci.id,ci.fecha_inicio,ci.fecha_fin,ci.precio,ci.estado,ci.observaciones,ci.created_by,ci.created_at,COUNT(cia.id) total_alumnos FROM cursos_intensivos ci LEFT JOIN curso_intensivo_alumnos cia ON cia.curso_intensivo_id=ci.id WHERE ci.sede_id=:s GROUP BY ci.id,ci.fecha_inicio,ci.fecha_fin,ci.precio,ci.estado,ci.observaciones,ci.created_by,ci.created_at ORDER BY ci.fecha_inicio DESC");$st->execute([':s'=>$sid]);$rows=$st->fetchAll();
  foreach($rows as &$row){$row['estado']=intensivo_estado_por_fechas((string)$row['fecha_inicio'],(string)$row['fecha_fin']);}unset($row);
  out(['ok'=>true,'total'=>count($rows),'intensivos'=>$rows]);
 }
 $me=auth_require(['ADMIN']);if($method!=='POST')out(['ok'=>false,'error'=>'Método no permitido'],405);
 $in=json_decode(file_get_contents('php://input'),true);if(!is_array($in))out(['ok'=>false,'error'=>'JSON inválido'],400);$clave=auth_resolve_sede_clave((string)($in['sede']??'MONTEVERDE'));$st=$pdo->prepare("SELECT id FROM sedes WHERE clave=:c AND activo=1 LIMIT 1");$st->execute([':c'=>$clave]);$sid=$st->fetchColumn();if(!$sid)out(['ok'=>false,'error'=>'Sede inválida'],422);
 $fechaInicioTexto=trim((string)($in['fecha_inicio']??''));$precio=$in['precio']??1200;$observaciones=isset($in['observaciones'])?trim((string)$in['observaciones']):null;$createdBy=(string)$me['id'];if($fechaInicioTexto===''||!is_numeric($precio)||(float)$precio<=0||(float)$precio>1000000)out(['ok'=>false,'error'=>'La fecha y un precio mayor a cero son obligatorios'],422);if($observaciones!==null&&mb_strlen($observaciones)>2000)out(['ok'=>false,'error'=>'Las observaciones no pueden exceder 2000 caracteres'],422);
 $fechaInicio=DateTimeImmutable::createFromFormat('!Y-m-d',$fechaInicioTexto);$err=DateTimeImmutable::getLastErrors();if(!$fechaInicio||$fechaInicio->format('Y-m-d')!==$fechaInicioTexto||(is_array($err)&&($err['warning_count']>0||$err['error_count']>0)))out(['ok'=>false,'error'=>'La fecha de inicio no es válida'],422);if((int)$fechaInicio->format('N')!==1)out(['ok'=>false,'error'=>'Los cursos intensivos solo pueden iniciar en lunes'],422);$fechaFin=$fechaInicio->modify('+18 days');$fi=$fechaInicio->format('Y-m-d');$ff=$fechaFin->format('Y-m-d');$estadoInicial=intensivo_estado_por_fechas($fi,$ff);
 $pdo->beginTransaction();
 // La sede funciona como candado estable aun cuando todavía no exista una
 // fila de curso para esa fecha. Evita dos cursos iguales por doble clic.
 $st=$pdo->prepare("SELECT id FROM sedes WHERE id=:s LIMIT 1 FOR UPDATE");$st->execute([':s'=>$sid]);
 $st=$pdo->prepare("SELECT id FROM cursos_intensivos WHERE sede_id=:s AND fecha_inicio=:f LIMIT 1");$st->execute([':s'=>$sid,':f'=>$fi]);if($st->fetch()){$pdo->rollBack();out(['ok'=>false,'error'=>'Ya existe un curso intensivo con esa fecha de inicio en esta sede'],422);}
 $id=$pdo->query("SELECT UUID()")->fetchColumn();$st=$pdo->prepare("INSERT INTO cursos_intensivos(id,sede_id,fecha_inicio,fecha_fin,precio,estado,observaciones,created_by) VALUES(:id,:s,:fi,:ff,:p,:estado,:o,:u)");$st->execute([':id'=>$id,':s'=>$sid,':fi'=>$fi,':ff'=>$ff,':p'=>number_format((float)$precio,2,'.',''),':estado'=>$estadoInicial,':o'=>$observaciones!==''?$observaciones:null,':u'=>$createdBy]);
 $st=$pdo->prepare("SELECT ci.id,ci.fecha_inicio,ci.fecha_fin,ci.precio,ci.estado,ci.observaciones,ci.created_by,ci.created_at,0 total_alumnos FROM cursos_intensivos ci WHERE ci.id=:id LIMIT 1");$st->execute([':id'=>$id]);$intensivo=$st->fetch();
 $pdo->commit();out(['ok'=>true,'mensaje'=>'Curso intensivo creado correctamente','intensivo'=>$intensivo],201);
}catch(Throwable $e){if(isset($pdo)&&$pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();error_log('[intensivos] '.$e->getMessage());out(['ok'=>false,'error'=>'No se pudo procesar el curso intensivo'],500);}
