<?php

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/auth.php';
require_once __DIR__.'/../config/reglas-acceso.php';
$me=auth_require(['ADMIN']);
$config=require __DIR__.'/../config/database.php';
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){http_response_code(405);header('Allow: POST');echo json_encode(['ok'=>false,'error'=>'Método no permitido'],JSON_UNESCAPED_UNICODE);exit;}
try{
$pdo=new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$input=json_decode(file_get_contents('php://input'),true);if(!is_array($input)){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'Solicitud JSON inválida']);exit;}$pagoId=trim((string)($input['pago_id']??''));$motivo=trim((string)($input['motivo']??''));$usuarioId=(string)$me['id'];
if($pagoId===''||$motivo===''){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'Pago y motivo son obligatorios']);exit;}if(mb_strlen($motivo)>500){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'El motivo no puede exceder 500 caracteres']);exit;}
$sedeClave=auth_active_sede_clave();$stmt=$pdo->prepare("SELECT id FROM sedes WHERE clave=:c AND activo=1 LIMIT 1");$stmt->execute([':c'=>$sedeClave]);$sedeId=(string)$stmt->fetchColumn();if($sedeId===''){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'Sede activa inválida']);exit;}
$pdo->beginTransaction();
$stmt=$pdo->prepare("SELECT p.id,p.alumno_id,p.estado,p.tipo,p.mensualidad_id,p.observacion,m.mes,m.anio,m.periodo_inicio,m.plan_id FROM pagos p INNER JOIN alumnos a ON a.id=p.alumno_id LEFT JOIN mensualidades m ON m.id=p.mensualidad_id AND m.sede_id=a.sede_id WHERE p.id=:id AND a.sede_id=:s LIMIT 1 FOR UPDATE");$stmt->execute([':id'=>$pagoId,':s'=>$sedeId]);$pago=$stmt->fetch();if(!$pago){$pdo->rollBack();http_response_code(404);echo json_encode(['ok'=>false,'error'=>'Pago no encontrado en la sede activa']);exit;}if($pago['estado']!=='VALIDO'){$pdo->rollBack();http_response_code(422);echo json_encode(['ok'=>false,'error'=>'El pago ya está invalidado']);exit;}if($pago['tipo']==='MENSUALIDAD'&&!empty($pago['mensualidad_id'])&&$pago['mes']===null){$pdo->rollBack();http_response_code(409);echo json_encode(['ok'=>false,'error'=>'La mensualidad relacionada no pertenece a la sede activa']);exit;}
$obs=trim((string)($pago['observacion']??''));$obs=($obs!==''?$obs."\n":'').'INVALIDADO: '.$motivo;
$stmt=$pdo->prepare("UPDATE pagos SET estado='INVALIDADO',invalidated_at=NOW(),invalidated_by=:uid,observacion=:obs WHERE id=:id AND estado='VALIDO'");$stmt->execute([':uid'=>$usuarioId,':obs'=>$obs,':id'=>$pagoId]);if($stmt->rowCount()!==1)throw new RuntimeException('El pago cambió mientras se procesaba la invalidación');
if($pago['tipo']==='MENSUALIDAD'&&!empty($pago['mensualidad_id'])){
 $stmt=$pdo->prepare("SELECT id FROM pagos WHERE mensualidad_id=:mensualidad AND estado='VALIDO' ORDER BY folio DESC LIMIT 1 FOR UPDATE");$stmt->execute([':mensualidad'=>$pago['mensualidad_id']]);$otroPagoValido=$stmt->fetchColumn();
 if(!$otroPagoValido){
  $stmt=$pdo->prepare("UPDATE mensualidades SET importe_cobrado=NULL,estado='PENDIENTE',fecha_pago=NULL,updated_at=NOW(),observacion=CONCAT(COALESCE(observacion,''),CASE WHEN observacion IS NULL OR observacion='' THEN '' ELSE '\n' END,'Pago invalidado: ',:motivo) WHERE id=:id AND sede_id=:sede");$stmt->execute([':motivo'=>$motivo,':id'=>$pago['mensualidad_id'],':sede'=>$sedeId]);
  if(!empty($pago['periodo_inicio'])&&!empty($pago['plan_id'])){$stmt=$pdo->prepare("UPDATE alumnos SET plan_programado_id=NULL,plan_programado_desde=NULL,updated_at=NOW() WHERE id=:alumno AND sede_id=:sede AND plan_programado_id=:plan AND plan_programado_desde=:desde");$stmt->execute([':alumno'=>$pago['alumno_id'],':sede'=>$sedeId,':plan'=>$pago['plan_id'],':desde'=>$pago['periodo_inicio']]);}
 }
}
$stmt=$pdo->prepare("SELECT COUNT(*) FROM pagos WHERE alumno_id=:alumno AND estado='VALIDO'");$stmt->execute([':alumno'=>$pago['alumno_id']]);$validos=(int)$stmt->fetchColumn();
regla_recalcular_alumno($pdo,(string)$pago['alumno_id']);
$pdo->commit();echo json_encode(['ok'=>true,'mensaje'=>'Pago invalidado correctamente','pagos_validos_restantes'=>$validos],JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){if(isset($pdo)&&$pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();error_log('[invalidar-pago] '.$e->getMessage());http_response_code(500);echo json_encode(['ok'=>false,'error'=>'No se pudo invalidar el pago'],JSON_UNESCAPED_UNICODE);}
