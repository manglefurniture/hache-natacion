<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/auth.php';
$me=auth_require(['ADMIN']);
$config=require __DIR__.'/../config/database.php';
$pdo=new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
function out(array $d,int $c=200):never{http_response_code($c);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
try{
 if($_SERVER['REQUEST_METHOD']==='GET'){
  $rows=$pdo->query("SELECT u.id,u.usuario,u.rol,u.activo,u.alumno_id,u.last_login,a.nombre alumno_nombre FROM usuarios u LEFT JOIN alumnos a ON a.id=u.alumno_id ORDER BY u.rol,u.usuario")->fetchAll();
  $alumnos=$pdo->query("SELECT a.id,a.nombre FROM alumnos a WHERE NOT EXISTS(SELECT 1 FROM usuarios u WHERE u.alumno_id=a.id AND u.rol='ALUMNO') ORDER BY a.nombre")->fetchAll();
  out(['ok'=>true,'usuarios'=>$rows,'alumnos_disponibles'=>$alumnos]);
 }
 if($_SERVER['REQUEST_METHOD']!=='POST')out(['ok'=>false,'error'=>'Método no permitido'],405);
 $in=json_decode(file_get_contents('php://input'),true)?:[];$accion=strtoupper((string)($in['accion']??''));
 if($accion==='CREAR'){
  $usuario=trim((string)($in['usuario']??''));$password=(string)($in['password']??'');$rol=strtoupper((string)($in['rol']??''));$alumno=$in['alumno_id']??null;
  if($usuario===''||strlen($password)<6||!in_array($rol,['ADMIN','VERIFICADOR','ALUMNO'],true))out(['ok'=>false,'error'=>'Datos de usuario inválidos. La contraseña debe tener al menos 6 caracteres.'],422);
  if($rol==='ALUMNO'&&!$alumno)out(['ok'=>false,'error'=>'Un usuario ALUMNO debe vincularse a un alumno'],422);
  if($rol!=='ALUMNO')$alumno=null;
  $st=$pdo->prepare("INSERT INTO usuarios(usuario,password_hash,rol,activo,alumno_id) VALUES(:u,:p,:r,1,:a)");
  $st->execute([':u'=>$usuario,':p'=>password_hash($password,PASSWORD_DEFAULT),':r'=>$rol,':a'=>$alumno]);out(['ok'=>true]);
 }
 if($accion==='ESTADO'){
  $id=(string)($in['id']??'');$activo=!empty($in['activo'])?1:0;if($id===$me['id']&&!$activo)out(['ok'=>false,'error'=>'No puedes desactivar tu propio usuario'],422);
  $st=$pdo->prepare("UPDATE usuarios SET activo=:a WHERE id=:id");$st->execute([':a'=>$activo,':id'=>$id]);out(['ok'=>true]);
 }
 if($accion==='PASSWORD'){
  $id=(string)($in['id']??'');$password=(string)($in['password']??'');if(strlen($password)<6)out(['ok'=>false,'error'=>'La contraseña debe tener al menos 6 caracteres'],422);
  $st=$pdo->prepare("UPDATE usuarios SET password_hash=:p WHERE id=:id");$st->execute([':p'=>password_hash($password,PASSWORD_DEFAULT),':id'=>$id]);out(['ok'=>true]);
 }
 out(['ok'=>false,'error'=>'Acción inválida'],422);
}catch(PDOException $e){$msg=$e->getCode()==='23000'?'El usuario o alumno ya está vinculado a otra cuenta.':'No se pudo guardar el usuario.';out(['ok'=>false,'error'=>$msg],422);}catch(Throwable $e){out(['ok'=>false,'error'=>$e->getMessage()],500);}
