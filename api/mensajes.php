<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
$config=require __DIR__.'/../config/database.php';
require_once __DIR__.'/../config/auth.php';$me=auth_user();
$pdo=new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
function out(array $d,int $c=200):never{http_response_code($c);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
if($_SERVER['REQUEST_METHOD']==='GET'){$rows=$pdo->query("SELECT m.id,m.titulo,m.cuerpo,m.audiencia,m.alumno_id,m.activo,m.vigencia_desde,m.vigencia_hasta,m.created_at,a.nombre alumno_nombre FROM mensajes m LEFT JOIN alumnos a ON a.id=m.alumno_id ORDER BY m.created_at DESC LIMIT 100")->fetchAll();out(['ok'=>true,'mensajes'=>$rows]);}
if($_SERVER['REQUEST_METHOD']!=='POST')out(['ok'=>false,'error'=>'Método no permitido'],405);
$in=json_decode(file_get_contents('php://input'),true)?:[];$accion=strtoupper((string)($in['accion']??''));
if($accion==='CREAR'){$titulo=trim((string)($in['titulo']??''));$cuerpo=trim((string)($in['cuerpo']??''));$aud=strtoupper((string)($in['audiencia']??'TODOS'));$alumno=$in['alumno_id']??null;$desde=$in['vigencia_desde']??null;$hasta=$in['vigencia_hasta']??null;if(!$titulo||!$cuerpo||!in_array($aud,['TODOS','REGULARES','INTENSIVOS','ALUMNO'],true))out(['ok'=>false,'error'=>'Datos inválidos'],422);if($aud==='ALUMNO'&&!$alumno)out(['ok'=>false,'error'=>'Selecciona el alumno'],422);if($aud!=='ALUMNO')$alumno=null;$st=$pdo->prepare("INSERT INTO mensajes(titulo,cuerpo,audiencia,alumno_id,vigencia_desde,vigencia_hasta,created_by) VALUES(:t,:c,:au,:al,:d,:h,:u)");$st->execute([':t'=>$titulo,':c'=>$cuerpo,':au'=>$aud,':al'=>$alumno,':d'=>$desde?:null,':h'=>$hasta?:null,':u'=>$me['id']]);out(['ok'=>true]);}
if($accion==='ESTADO'){$st=$pdo->prepare("UPDATE mensajes SET activo=:a,updated_at=NOW() WHERE id=:id");$st->execute([':a'=>!empty($in['activo'])?1:0,':id'=>(string)($in['id']??'')]);out(['ok'=>true]);}
out(['ok'=>false,'error'=>'Acción inválida'],422);
