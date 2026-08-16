<?php

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
$config=require __DIR__.'/../config/database.php';
try{
$pdo=new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$input=json_decode(file_get_contents('php://input'),true);
$pagoId=trim((string)($input['pago_id']??''));$motivo=trim((string)($input['motivo']??''));$usuarioId=trim((string)($input['usuario_id']??''));
if($pagoId===''||$motivo===''||$usuarioId===''){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'Pago, motivo y usuario son obligatorios']);exit;}
$stmt=$pdo->prepare("SELECT id,estado,tipo,mensualidad_id,observacion FROM pagos WHERE id=:id LIMIT 1");$stmt->execute([':id'=>$pagoId]);$pago=$stmt->fetch();
if(!$pago){http_response_code(404);echo json_encode(['ok'=>false,'error'=>'Pago no encontrado']);exit;}
if($pago['estado']!=='VALIDO'){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'El pago ya está invalidado']);exit;}
$stmt=$pdo->prepare("SELECT id FROM usuarios WHERE id=:id LIMIT 1");$stmt->execute([':id'=>$usuarioId]);if(!$stmt->fetch()){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'Usuario administrativo inválido']);exit;}
$pdo->beginTransaction();
$obs=trim((string)($pago['observacion']??''));$obs=($obs!==''?$obs."\n":'').'INVALIDADO: '.$motivo;
$stmt=$pdo->prepare("UPDATE pagos SET estado='INVALIDADO',invalidated_at=NOW(),invalidated_by=:uid,observacion=:obs WHERE id=:id");$stmt->execute([':uid'=>$usuarioId,':obs'=>$obs,':id'=>$pagoId]);
if($pago['tipo']==='MENSUALIDAD'&&!empty($pago['mensualidad_id'])){$stmt=$pdo->prepare("UPDATE mensualidades SET importe_cobrado=NULL,estado='PENDIENTE',fecha_pago=NULL,updated_at=NOW(),observacion=CONCAT(COALESCE(observacion,''),CASE WHEN observacion IS NULL OR observacion='' THEN '' ELSE '\n' END,'Pago invalidado: ',:motivo) WHERE id=:id");$stmt->execute([':motivo'=>$motivo,':id'=>$pago['mensualidad_id']]);}
$pdo->commit();echo json_encode(['ok'=>true,'mensaje'=>'Pago invalidado correctamente'],JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();http_response_code(500);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
