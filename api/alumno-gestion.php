<?php

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
$config=require __DIR__.'/../config/database.php';
try{
$pdo=new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$input=json_decode(file_get_contents('php://input'),true);$alumnoId=trim((string)($input['alumno_id']??''));$accion=strtoupper(trim((string)($input['accion']??'')));
if($alumnoId===''||!in_array($accion,['BAJA','REACTIVAR','ELIMINAR'],true)){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'Acción inválida']);exit;}
$stmt=$pdo->prepare("SELECT id,nombre,estado_administrativo FROM alumnos WHERE id=:id LIMIT 1");$stmt->execute([':id'=>$alumnoId]);$alumno=$stmt->fetch();if(!$alumno){http_response_code(404);echo json_encode(['ok'=>false,'error'=>'Alumno no encontrado']);exit;}
if($accion==='BAJA'){$stmt=$pdo->prepare("UPDATE alumnos SET estado_administrativo='BAJA',updated_at=NOW() WHERE id=:id");$stmt->execute([':id'=>$alumnoId]);echo json_encode(['ok'=>true,'estado'=>'BAJA','mensaje'=>'Alumno dado de baja'],JSON_UNESCAPED_UNICODE);exit;}
if($accion==='REACTIVAR'){$stmt=$pdo->prepare("SELECT COUNT(*) FROM pagos WHERE alumno_id=:id AND estado='VALIDO'");$stmt->execute([':id'=>$alumnoId]);$nuevo=(int)$stmt->fetchColumn()>0?'ACTIVO':'PENDIENTE';$stmt=$pdo->prepare("UPDATE alumnos SET estado_administrativo=:estado,updated_at=NOW() WHERE id=:id");$stmt->execute([':estado'=>$nuevo,':id'=>$alumnoId]);echo json_encode(['ok'=>true,'estado'=>$nuevo,'mensaje'=>$nuevo==='ACTIVO'?'Alumno reactivado':'Alumno reabierto como pendiente hasta registrar un pago válido'],JSON_UNESCAPED_UNICODE);exit;}
$tablas=['pagos','mensualidades','inscripciones','curso_intensivo_alumnos','alumno_responsable','ausencias','historial','usuarios'];$movimientos=0;$detalle=[];foreach($tablas as $tabla){try{$stmt=$pdo->prepare("SELECT COUNT(*) FROM `$tabla` WHERE alumno_id=:id");$stmt->execute([':id'=>$alumnoId]);$c=(int)$stmt->fetchColumn();$movimientos+=$c;if($c)$detalle[$tabla]=$c;}catch(Throwable $e){}}
if($movimientos>0){http_response_code(409);echo json_encode(['ok'=>false,'error'=>'El alumno tiene historial y no puede eliminarse definitivamente. Usa Dar de baja.','referencias'=>$detalle],JSON_UNESCAPED_UNICODE);exit;}
try{$stmt=$pdo->prepare("DELETE FROM alumnos WHERE id=:id");$stmt->execute([':id'=>$alumnoId]);}catch(Throwable $e){http_response_code(409);echo json_encode(['ok'=>false,'error'=>'El alumno tiene información relacionada y no puede eliminarse. Usa Dar de baja.'],JSON_UNESCAPED_UNICODE);exit;}echo json_encode(['ok'=>true,'mensaje'=>'Alumno eliminado definitivamente'],JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){http_response_code(500);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
