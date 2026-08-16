<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
$config=require __DIR__.'/../config/database.php';require_once __DIR__.'/../config/auth.php';$me=auth_user();
$pdo=new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
function out(array $d,int $c=200):never{http_response_code($c);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
if($_SERVER['REQUEST_METHOD']==='GET'){$cfg=$pdo->query("SELECT clave,valor,descripcion,updated_at FROM configuracion ORDER BY clave")->fetchAll();$planes=$pdo->query("SELECT id,nombre,sesiones_semana,precio,activo FROM planes ORDER BY activo DESC,sesiones_semana,nombre")->fetchAll();out(['ok'=>true,'configuracion'=>$cfg,'planes'=>$planes]);}
if($_SERVER['REQUEST_METHOD']!=='POST')out(['ok'=>false,'error'=>'Método no permitido'],405);
$in=json_decode(file_get_contents('php://input'),true)?:[];$accion=strtoupper((string)($in['accion']??''));
if($accion==='CONFIG'){$clave=trim((string)($in['clave']??''));$valor=(string)($in['valor']??'');if($clave==='')out(['ok'=>false,'error'=>'Clave inválida'],422);$st=$pdo->prepare("INSERT INTO configuracion(clave,valor,updated_by) VALUES(:c,:v,:u) ON DUPLICATE KEY UPDATE valor=VALUES(valor),updated_by=VALUES(updated_by),updated_at=NOW()");$st->execute([':c'=>$clave,':v'=>$valor,':u'=>$me['id']]);out(['ok'=>true]);}
if($accion==='PLAN'){$id=(string)($in['id']??'');$nombre=trim((string)($in['nombre']??''));$ses=(int)($in['sesiones_semana']??0);$precio=(float)($in['precio']??0);$activo=!empty($in['activo'])?1:0;if(!$nombre||$ses<1||$precio<0)out(['ok'=>false,'error'=>'Datos de plan inválidos'],422);if($id){$st=$pdo->prepare("UPDATE planes SET nombre=:n,sesiones_semana=:s,precio=:p,activo=:a WHERE id=:id");$st->execute([':n'=>$nombre,':s'=>$ses,':p'=>$precio,':a'=>$activo,':id'=>$id]);}else{$st=$pdo->prepare("INSERT INTO planes(nombre,sesiones_semana,precio,activo) VALUES(:n,:s,:p,:a)");$st->execute([':n'=>$nombre,':s'=>$ses,':p'=>$precio,':a'=>$activo]);}out(['ok'=>true]);}
out(['ok'=>false,'error'=>'Acción inválida'],422);
