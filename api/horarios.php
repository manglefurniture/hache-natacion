<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
$config=require __DIR__.'/../config/database.php';
$pdo=new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
function out(array $d,int $c=200):never{http_response_code($c);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
try{
 if($_SERVER['REQUEST_METHOD']==='GET'){
  $rows=$pdo->query("SELECT h.*, (SELECT COUNT(*) FROM alumnos a WHERE a.horario_preferido_id=h.id AND a.estado_administrativo<>'BAJA') alumnos_regulares, (SELECT COUNT(*) FROM curso_intensivo_alumnos cia JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id WHERE cia.horario_id=h.id AND ci.estado IN ('PROGRAMADO','EN_CURSO')) alumnos_intensivo FROM horarios h ORDER BY h.hora_inicio")->fetchAll();
  out(['ok'=>true,'horarios'=>$rows]);
 }
 if($_SERVER['REQUEST_METHOD']!=='POST') out(['ok'=>false,'error'=>'Método no permitido'],405);
 $in=json_decode(file_get_contents('php://input'),true)?:[];$accion=strtoupper((string)($in['accion']??''));
 if($accion==='CREAR'){
  $ini=(string)($in['hora_inicio']??'');$fin=(string)($in['hora_fin']??'');$regular=!empty($in['regular'])?1:0;$intensivo=!empty($in['intensivo'])?1:0;
  if(!$ini||!$fin||(!$regular&&!$intensivo)) out(['ok'=>false,'error'=>'Completa horario y tipo'],422);
  $st=$pdo->prepare("INSERT INTO horarios(hora_inicio,hora_fin,regular,intensivo,activo) VALUES(:i,:f,:r,:n,1)");$st->execute([':i'=>$ini,':f'=>$fin,':r'=>$regular,':n'=>$intensivo]);out(['ok'=>true]);
 }
 if($accion==='ACTUALIZAR'){
  $id=(string)($in['id']??'');$regular=!empty($in['regular'])?1:0;$intensivo=!empty($in['intensivo'])?1:0;if(!$id||(!$regular&&!$intensivo))out(['ok'=>false,'error'=>'Datos inválidos'],422);
  $st=$pdo->prepare("UPDATE horarios SET regular=:r,intensivo=:n,activo=:a WHERE id=:id");$st->execute([':r'=>$regular,':n'=>$intensivo,':a'=>!empty($in['activo'])?1:0,':id'=>$id]);out(['ok'=>true]);
 }
 out(['ok'=>false,'error'=>'Acción inválida'],422);
}catch(Throwable $e){out(['ok'=>false,'error'=>$e->getMessage()],500);}