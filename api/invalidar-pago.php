<?php

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
$config=require __DIR__.'/../config/database.php';
try{
$pdo=new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$input=json_decode(file_get_contents('php://input'),true);$pagoId=trim((string)($input['pago_id']??''));$motivo=trim((string)($input['motivo']??''));$usuarioId=trim((string)($input['usuario_id']??''));
if($pagoId===''||$motivo===''||$usuarioId===''){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'Pago, motivo y usuario son obligatorios']);exit;}
$stmt=$pdo->prepare("SELECT p.id,p.alumno_id,p.estado,p.tipo,p.mensualidad_id,p.observacion,m.mes,m.anio,m.plan_id FROM pagos p LEFT JOIN mensualidades m ON m.id=p.mensualidad_id WHERE p.id=:id LIMIT 1");$stmt->execute([':id'=>$pagoId]);$pago=$stmt->fetch();if(!$pago){http_response_code(404);echo json_encode(['ok'=>false,'error'=>'Pago no encontrado']);exit;}if($pago['estado']!=='VALIDO'){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'El pago ya está invalidado']);exit;}
$stmt=$pdo->prepare("SELECT id FROM usuarios WHERE id=:id LIMIT 1");$stmt->execute([':id'=>$usuarioId]);if(!$stmt->fetch()){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'Usuario administrativo inválido']);exit;}
$pdo->beginTransaction();
$obs=trim((string)($pago['observacion']??''));$obs=($obs!==''?$obs."\n":'').'INVALIDADO: '.$motivo;
$stmt=$pdo->prepare("UPDATE pagos SET estado='INVALIDADO',invalidated_at=NOW(),invalidated_by=:uid,observacion=:obs WHERE id=:id");$stmt->execute([':uid'=>$usuarioId,':obs'=>$obs,':id'=>$pagoId]);
if($pago['tipo']==='MENSUALIDAD'&&!empty($pago['mensualidad_id'])){
 $stmt=$pdo->prepare("UPDATE mensualidades SET importe_cobrado=NULL,estado='PENDIENTE',fecha_pago=NULL,updated_at=NOW(),observacion=CONCAT(COALESCE(observacion,''),CASE WHEN observacion IS NULL OR observacion='' THEN '' ELSE '\n' END,'Pago invalidado: ',:motivo) WHERE id=:id");$stmt->execute([':motivo'=>$motivo,':id'=>$pago['mensualidad_id']]);
 if(!empty($pago['mes'])&&!empty($pago['anio'])&&!empty($pago['plan_id'])){$desde=sprintf('%04d-%02d-01',(int)$pago['anio'],(int)$pago['mes']);$stmt=$pdo->prepare("UPDATE alumnos SET plan_programado_id=NULL,plan_programado_desde=NULL,updated_at=NOW() WHERE id=:alumno AND plan_programado_id=:plan AND plan_programado_desde=:desde");$stmt->execute([':alumno'=>$pago['alumno_id'],':plan'=>$pago['plan_id'],':desde'=>$desde]);}
}
$stmt=$pdo->prepare("SELECT COUNT(*) FROM pagos WHERE alumno_id=:alumno AND estado='VALIDO'");$stmt->execute([':alumno'=>$pago['alumno_id']]);$validos=(int)$stmt->fetchColumn();
if($validos===0){$stmt=$pdo->prepare("UPDATE alumnos SET estado_administrativo='PENDIENTE',updated_at=NOW() WHERE id=:alumno AND estado_administrativo<>'BAJA'");$stmt->execute([':alumno'=>$pago['alumno_id']]);}
$pdo->commit();echo json_encode(['ok'=>true,'mensaje'=>'Pago invalidado correctamente','pagos_validos_restantes'=>$validos],JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){if(isset($pdo)&&$pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();http_response_code(500);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
