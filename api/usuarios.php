<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/auth.php';
require_once __DIR__.'/../config/passwords.php';
$me=auth_require(['ADMIN']);
$config=require __DIR__.'/../config/database.php';
$pdo=new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
function out(array $d,int $c=200):never{http_response_code($c);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
try{
 if($_SERVER['REQUEST_METHOD']==='GET'){
  $rows=$pdo->query("SELECT u.id,u.usuario,u.rol,u.activo,u.debe_cambiar_password,u.alumno_id,u.sede_id,u.last_login,a.nombre alumno_nombre,s.clave sede_clave,s.nombre sede_nombre FROM usuarios u LEFT JOIN alumnos a ON a.id=u.alumno_id LEFT JOIN sedes s ON s.id=u.sede_id ORDER BY u.rol,u.usuario")->fetchAll();
  $alumnos=$pdo->query("SELECT a.id,a.nombre FROM alumnos a WHERE NOT EXISTS(SELECT 1 FROM usuarios u WHERE u.alumno_id=a.id AND u.rol='ALUMNO') ORDER BY a.nombre")->fetchAll();
  $sedes=$pdo->query("SELECT id,clave,nombre FROM sedes WHERE activo=1 ORDER BY nombre")->fetchAll();
  out(['ok'=>true,'usuarios'=>$rows,'alumnos_disponibles'=>$alumnos,'sedes'=>$sedes]);
 }
 if($_SERVER['REQUEST_METHOD']!=='POST')out(['ok'=>false,'error'=>'Método no permitido'],405);
 $in=json_decode(file_get_contents('php://input'),true);if(!is_array($in))out(['ok'=>false,'error'=>'JSON inválido'],400);$accion=strtoupper((string)($in['accion']??''));
 if($accion==='CREAR'){
  $usuario=trim((string)($in['usuario']??''));$password=(string)($in['password']??'');$rol=strtoupper((string)($in['rol']??''));$alumno=$in['alumno_id']??null;$sedeId=trim((string)($in['sede_id']??''));
  if(!preg_match('/^[a-zA-Z0-9._-]{3,100}$/',$usuario)||!in_array($rol,['ADMIN','VERIFICADOR','ALUMNO'],true))out(['ok'=>false,'error'=>'El usuario debe tener de 3 a 100 caracteres y usar solo letras, números, punto, guion o guion bajo.'],422);
  if($passwordError=password_error_politica($password))out(['ok'=>false,'error'=>$passwordError],422);
  if($rol==='ALUMNO'&&!$alumno)out(['ok'=>false,'error'=>'Un usuario ALUMNO debe vincularse a un alumno'],422);
  if($rol==='ALUMNO'){$st=$pdo->prepare("SELECT id FROM alumnos WHERE id=:id AND estado_administrativo<>'BAJA' LIMIT 1");$st->execute([':id'=>$alumno]);if(!$st->fetch())out(['ok'=>false,'error'=>'El alumno vinculado no existe o está dado de baja'],422);}
  if($rol==='VERIFICADOR'){
    if($sedeId==='')out(['ok'=>false,'error'=>'La sede es obligatoria para un verificador'],422);
    $st=$pdo->prepare("SELECT id FROM sedes WHERE id=:id AND activo=1 LIMIT 1");$st->execute([':id'=>$sedeId]);if(!$st->fetch())out(['ok'=>false,'error'=>'Sede inválida'],422);
  }else{$sedeId=null;}
  if($rol!=='ALUMNO')$alumno=null;
  $st=$pdo->prepare("INSERT INTO usuarios(usuario,password_hash,rol,activo,debe_cambiar_password,alumno_id,sede_id) VALUES(:u,:p,:r,1,1,:a,:s)");$st->execute([':u'=>$usuario,':p'=>password_hash($password,PASSWORD_DEFAULT),':r'=>$rol,':a'=>$alumno,':s'=>$sedeId]);out(['ok'=>true]);
 }
 if($accion==='ESTADO'){$id=(string)($in['id']??'');$activo=!empty($in['activo'])?1:0;if($id===$me['id']&&!$activo)out(['ok'=>false,'error'=>'No puedes desactivar tu propio usuario'],422);$st=$pdo->prepare("UPDATE usuarios SET activo=:a WHERE id=:id AND activo<>:a2");$st->execute([':a'=>$activo,':a2'=>$activo,':id'=>$id]);if(!$st->rowCount()){$st=$pdo->prepare("SELECT 1 FROM usuarios WHERE id=:id LIMIT 1");$st->execute([':id'=>$id]);if(!$st->fetchColumn())out(['ok'=>false,'error'=>'Usuario no encontrado'],404);out(['ok'=>true,'sin_cambios'=>true]);}out(['ok'=>true]);}
 if($accion==='PASSWORD'){$id=(string)($in['id']??'');$password=(string)($in['password']??'');if($id===$me['id'])out(['ok'=>false,'error'=>'Usa la opción Cambiar contraseña para actualizar tu propia cuenta'],422);if($passwordError=password_error_politica($password))out(['ok'=>false,'error'=>$passwordError],422);$st=$pdo->prepare("UPDATE usuarios SET password_hash=:p,debe_cambiar_password=1 WHERE id=:id");$st->execute([':p'=>password_hash($password,PASSWORD_DEFAULT),':id'=>$id]);if(!$st->rowCount())out(['ok'=>false,'error'=>'Usuario no encontrado'],404);out(['ok'=>true]);}
 out(['ok'=>false,'error'=>'Acción inválida'],422);
}catch(PDOException $e){if((string)$e->getCode()==='23000')out(['ok'=>false,'error'=>'El usuario o alumno ya está vinculado a otra cuenta.'],409);error_log('[usuarios] '.$e->getMessage());out(['ok'=>false,'error'=>'No se pudo guardar el usuario.'],500);}catch(Throwable $e){error_log('[usuarios] '.$e->getMessage());out(['ok'=>false,'error'=>'No se pudo procesar el usuario'],500);}
