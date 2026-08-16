<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
$config=require __DIR__.'/../config/database.php';
try{
$pdo=new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
if($_SERVER['REQUEST_METHOD']==='GET'){$horarios=$pdo->query("SELECT id,hora_inicio,hora_fin FROM horarios WHERE activo=1 AND regular=1 ORDER BY hora_inicio")->fetchAll();echo json_encode(['ok'=>true,'horarios'=>$horarios],JSON_UNESCAPED_UNICODE);exit;}
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['ok'=>false,'error'=>'Método no permitido']);exit;}
$in=json_decode(file_get_contents('php://input'),true)?:[];$id=trim((string)($in['alumno_id']??''));$campo=strtoupper(trim((string)($in['campo']??'')));$valor=$in['valor']??null;
if($id===''||!in_array($campo,['WHATSAPP','HORARIO'],true)){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'Datos inválidos']);exit;}
$st=$pdo->prepare("SELECT id,EXISTS(SELECT 1 FROM curso_intensivo_alumnos cia JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id WHERE cia.alumno_id=a.id AND ci.estado IN ('PROGRAMADO','EN_CURSO')) intensivo_activo FROM alumnos a WHERE a.id=:id LIMIT 1");$st->execute([':id'=>$id]);$a=$st->fetch();if(!$a){http_response_code(404);echo json_encode(['ok'=>false,'error'=>'Alumno no encontrado']);exit;}
if($campo==='WHATSAPP'){$v=trim((string)$valor);if($v===''){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'El WhatsApp no puede quedar vacío']);exit;}$st=$pdo->prepare("UPDATE alumnos SET whatsapp=:v,updated_at=NOW() WHERE id=:id");$st->execute([':v'=>$v,':id'=>$id]);echo json_encode(['ok'=>true,'valor'=>$v],JSON_UNESCAPED_UNICODE);exit;}
if((int)$a['intensivo_activo']===1){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'El horario de un alumno en intensivo se modifica dentro del curso intensivo']);exit;}
$v=trim((string)$valor);if($v===''){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'Selecciona un horario']);exit;}$st=$pdo->prepare("SELECT id,hora_inicio,hora_fin FROM horarios WHERE id=:id AND activo=1 AND regular=1 LIMIT 1");$st->execute([':id'=>$v]);$h=$st->fetch();if(!$h){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'Horario no disponible']);exit;}$st=$pdo->prepare("UPDATE alumnos SET horario_preferido_id=:h,updated_at=NOW() WHERE id=:id");$st->execute([':h'=>$v,':id'=>$id]);echo json_encode(['ok'=>true,'horario'=>$h],JSON_UNESCAPED_UNICODE);exit;
}catch(Throwable $e){http_response_code(500);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
