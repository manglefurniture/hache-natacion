<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/auth.php';
$config=require __DIR__.'/../config/database.php';
$pdo=new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
function out(array $d,int $c=200):never{http_response_code($c);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function sedeId(PDO $pdo,string $clave):string{$st=$pdo->prepare("SELECT id FROM sedes WHERE clave=:c AND activo=1 LIMIT 1");$st->execute([':c'=>$clave]);$id=(string)$st->fetchColumn();if(!$id)out(['ok'=>false,'error'=>'Sede inválida'],422);return $id;}
function exactTime(string $value):string{$date=DateTimeImmutable::createFromFormat('!H:i',$value);if(!$date||$date->format('H:i')!==$value)throw new InvalidArgumentException('Horario inválido');return $date->format('H:i:s');}
try{
 $method=$_SERVER['REQUEST_METHOD']??'GET';
 if($method==='GET'){
  auth_require(['ADMIN','VERIFICADOR']);$clave=auth_resolve_sede_clave((string)($_GET['sede']??'MONTEVERDE'));$sid=sedeId($pdo,$clave);
  $st=$pdo->prepare("SELECT h.*, (SELECT COUNT(*) FROM alumnos a WHERE a.horario_preferido_id=h.id AND a.sede_id=:sa AND a.estado_administrativo<>'BAJA') alumnos_regulares, (SELECT COUNT(*) FROM curso_intensivo_alumnos cia JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id WHERE cia.horario_id=h.id AND ci.sede_id=:sc AND ci.estado IN ('PROGRAMADO','EN_CURSO')) alumnos_intensivo FROM horarios h WHERE h.sede_id=:sh ORDER BY h.hora_inicio");$st->execute([':sa'=>$sid,':sc'=>$sid,':sh'=>$sid]);out(['ok'=>true,'horarios'=>$st->fetchAll()]);
 }
 auth_require(['ADMIN']);if($method!=='POST')out(['ok'=>false,'error'=>'Método no permitido'],405);
 $in=json_decode(file_get_contents('php://input'),true);if(!is_array($in))out(['ok'=>false,'error'=>'JSON inválido'],400);$accion=strtoupper((string)($in['accion']??''));$clave=auth_resolve_sede_clave((string)($in['sede']??'MONTEVERDE'));$sid=sedeId($pdo,$clave);
 if($accion==='CREAR'){$ini=exactTime(trim((string)($in['hora_inicio']??'')));$fin=exactTime(trim((string)($in['hora_fin']??'')));$regular=!empty($in['regular'])?1:0;$intensivo=!empty($in['intensivo'])?1:0;if(!$regular&&!$intensivo)out(['ok'=>false,'error'=>'Selecciona al menos un tipo de clase'],422);if($ini>=$fin)out(['ok'=>false,'error'=>'La hora final debe ser posterior a la inicial'],422);$st=$pdo->prepare("INSERT INTO horarios(sede_id,hora_inicio,hora_fin,regular,intensivo,activo) VALUES(:s,:i,:f,:r,:n,1)");$st->execute([':s'=>$sid,':i'=>$ini,':f'=>$fin,':r'=>$regular,':n'=>$intensivo]);out(['ok'=>true]);}
 if($accion==='ACTUALIZAR'){$id=trim((string)($in['id']??''));$regular=!empty($in['regular'])?1:0;$intensivo=!empty($in['intensivo'])?1:0;if($id===''||(!$regular&&!$intensivo))out(['ok'=>false,'error'=>'Datos inválidos'],422);$st=$pdo->prepare("UPDATE horarios SET regular=:r,intensivo=:n,activo=:a WHERE id=:id AND sede_id=:s");$st->execute([':r'=>$regular,':n'=>$intensivo,':a'=>!empty($in['activo'])?1:0,':id'=>$id,':s'=>$sid]);if($st->rowCount()===0){$check=$pdo->prepare('SELECT 1 FROM horarios WHERE id=:id AND sede_id=:s');$check->execute([':id'=>$id,':s'=>$sid]);if(!$check->fetchColumn())out(['ok'=>false,'error'=>'Horario no encontrado en la sede activa'],404);}out(['ok'=>true]);}
 out(['ok'=>false,'error'=>'Acción inválida'],422);
}catch(InvalidArgumentException $e){out(['ok'=>false,'error'=>$e->getMessage()],422);}catch(PDOException $e){if((string)$e->getCode()==='23000')out(['ok'=>false,'error'=>'Ese horario ya existe en la sede activa'],409);error_log('[horarios] '.$e->getMessage());out(['ok'=>false,'error'=>'No se pudo guardar el horario'],500);}catch(Throwable $e){error_log('[horarios] '.$e->getMessage());out(['ok'=>false,'error'=>'No se pudo procesar el horario'],500);}
