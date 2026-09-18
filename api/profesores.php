<?php

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/auth.php';
require_once __DIR__.'/../config/telefono.php';
require_once __DIR__.'/../config/profesores-asignaciones.php';
$me=auth_require(['ADMIN']);
$config=require __DIR__.'/../config/database.php';
$pdo=new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
function profesores_out(array $d,int $c=200):never{http_response_code($c);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function profesores_phone(string $value):?string{$digits=preg_replace('/\D+/','',$value)?:'';if(strlen($digits)===10)$digits='52'.$digits;if(strlen($digits)===13&&str_starts_with($digits,'521'))$digits='52'.substr($digits,3);$phone='+'.$digits;return telefono_es_e164($phone)?$phone:null;}
function profesores_schema(PDO $pdo):bool{
 try{
  $st=$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('profesores','profesor_horarios')");
  if((int)$st->fetchColumn()!==2)return false;
  return hache_profesores_vigencias_schema_ready($pdo)&&hache_profesores_vigencias_invariantes_ready($pdo);
 }catch(Throwable $e){return false;}
}
try{
 if(!profesores_schema($pdo))profesores_out(['ok'=>false,'error'=>'Falta aplicar la migración vigente de profesores.'],503);
 $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
 if($method==='GET'){
  $rows=$pdo->query("SELECT p.id,p.nombre,p.whatsapp,p.correo,p.activo,p.created_at,p.updated_at FROM profesores p ORDER BY p.activo DESC,p.nombre")->fetchAll();
  $st=$pdo->prepare("SELECT ph.profesor_id,h.id horario_id,h.hora_inicio,h.hora_fin,s.id sede_id,s.clave sede_clave,s.nombre sede_nombre,ph.activo FROM profesor_horarios ph JOIN horarios h ON h.id=ph.horario_id JOIN sedes s ON s.id=h.sede_id ORDER BY s.nombre,h.hora_inicio");$st->execute();$assignments=[];foreach($st->fetchAll() as $r)$assignments[(string)$r['profesor_id']][]=$r;
  foreach($rows as &$row)$row['horarios']=$assignments[(string)$row['id']]??[];unset($row);
  $horarios=$pdo->query("SELECT h.id,h.hora_inicio,h.hora_fin,s.id sede_id,s.clave sede_clave,s.nombre sede_nombre FROM horarios h JOIN sedes s ON s.id=h.sede_id WHERE h.activo=1 AND s.activo=1 ORDER BY s.nombre,h.hora_inicio")->fetchAll();
  profesores_out(['ok'=>true,'profesores'=>$rows,'horarios'=>$horarios,'csrf'=>auth_csrf_token()]);
 }
 if($method!=='POST')profesores_out(['ok'=>false,'error'=>'Método no permitido'],405);
 $in=json_decode(file_get_contents('php://input'),true);if(!is_array($in))profesores_out(['ok'=>false,'error'=>'JSON inválido'],400);
 if(!auth_csrf_validate(isset($in['csrf'])?(string)$in['csrf']:null))profesores_out(['ok'=>false,'error'=>'Sesión de seguridad vencida. Recarga la página.'],419);
 $action=strtoupper(trim((string)($in['accion']??'')));
 if($action==='SAVE'){
  $id=trim((string)($in['id']??''));$name=preg_replace('/\\s+/u',' ',trim((string)($in['nombre']??'')))??'';$phone=profesores_phone((string)($in['whatsapp']??''));$email=trim((string)($in['correo']??''));$active=array_key_exists('activo',$in)?(!empty($in['activo'])?1:0):1;
  if($name===''||mb_strlen($name)>180)profesores_out(['ok'=>false,'error'=>'El nombre es obligatorio y debe tener máximo 180 caracteres.'],422);if($phone===null)profesores_out(['ok'=>false,'error'=>'WhatsApp inválido. Usa un número de México o formato internacional.'],422);if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))profesores_out(['ok'=>false,'error'=>'Correo inválido.'],422);
  $pdo->beginTransaction();
  try{
   if($id===''){
    $id=(string)$pdo->query('SELECT UUID()')->fetchColumn();
    $st=$pdo->prepare("INSERT INTO profesores(id,nombre,whatsapp,correo,activo,created_by) VALUES(:id,:n,:w,:c,:a,:u)");
    $st->execute([':id'=>$id,':n'=>$name,':w'=>$phone,':c'=>$email!==''?$email:null,':a'=>$active,':u'=>$me['id']]);
   }else{
    $lock=$pdo->prepare('SELECT id,nombre,whatsapp,correo,activo FROM profesores WHERE id=:id LIMIT 1 FOR UPDATE');$lock->execute([':id'=>$id]);$actual=$lock->fetch();
    if(!$actual)throw new HacheProfesoresAssignmentException('Profesor no encontrado.',404);
    $st=$pdo->prepare("UPDATE profesores SET nombre=:n,whatsapp=:w,correo=:c,activo=:a WHERE id=:id");
    $st->execute([':n'=>$name,':w'=>$phone,':c'=>$email!==''?$email:null,':a'=>$active,':id'=>$id]);
    if($active===0)hache_profesores_cerrar_asignaciones_profesor($pdo,$id,(string)$me['id']);
    $cambios=[];
    if((int)$actual['activo']!==$active)$cambios['activo']=['anterior'=>(bool)$actual['activo'],'nuevo'=>(bool)$active];
    if((string)$actual['nombre']!==$name)$cambios['nombre']=['modificado'=>true,'valores_omitidos'=>'PII'];
    if((string)$actual['whatsapp']!==$phone)$cambios['whatsapp']=['modificado'=>true,'valores_omitidos'=>'PII'];
    if((string)($actual['correo']??'')!==$email)$cambios['correo']=['modificado'=>true,'valores_omitidos'=>'PII'];
    if($cambios){
     $detalle=json_encode(['cambios'=>$cambios],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
     if(!is_string($detalle))throw new RuntimeException('No se pudo serializar la edición del profesor.');
     $st=$pdo->prepare("INSERT INTO auditoria_eventos(usuario_id,usuario_nombre,accion,entidad,entidad_id,detalle,metodo,ruta) VALUES(:uid,:un,'PROFESOR_DATOS_ACTUALIZADOS','profesor',:pid,:detalle,'POST','/api/profesores.php')");
     $st->execute([':uid'=>$me['id'],':un'=>$me['usuario']??null,':pid'=>$id,':detalle'=>$detalle]);
    }
   }
   $pdo->commit();
  }catch(Throwable $e){
   if($pdo->inTransaction())$pdo->rollBack();
   throw $e;
  }
  profesores_out(['ok'=>true,'profesor_id'=>$id]);
 }
 if($action==='ASSIGN'){
  $teacher=trim((string)($in['profesor_id']??''));$schedule=trim((string)($in['horario_id']??''));$active=!empty($in['activo']);
  if($teacher===''||$schedule==='')profesores_out(['ok'=>false,'error'=>'Profesor y horario son obligatorios.'],422);
  $assignmentId=hache_profesores_asignacion_set($pdo,$teacher,$schedule,$active,(string)$me['id']);
  profesores_out(['ok'=>true,'profesor_horario_id'=>$assignmentId]);
 }
 profesores_out(['ok'=>false,'error'=>'Acción inválida'],422);
}catch(HacheProfesoresAssignmentException $e){if($pdo->inTransaction())$pdo->rollBack();profesores_out(['ok'=>false,'error'=>$e->getMessage()],$e->httpStatus);}catch(PDOException $e){if($pdo->inTransaction())$pdo->rollBack();if((string)$e->getCode()==='23000')profesores_out(['ok'=>false,'error'=>'Ese WhatsApp ya está asignado a otro profesor o existe una relación duplicada.'],409);error_log('[profesores] '.$e->getMessage());profesores_out(['ok'=>false,'error'=>'No se pudo guardar el profesor.'],500);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('[profesores] '.$e->getMessage());profesores_out(['ok'=>false,'error'=>'No se pudo procesar la solicitud.'],500);}
