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
$input=json_decode(file_get_contents('php://input'),true);
if(!is_array($input)||json_last_error()!==JSON_ERROR_NONE){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'JSON inválido']);exit;}
$alumnoId=trim((string)($input['alumno_id']??''));$accion=strtoupper(trim((string)($input['accion']??'')));
if($alumnoId===''||!in_array($accion,['BAJA','REACTIVAR','ELIMINAR'],true)){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'Acción inválida']);exit;}
$sedeClave=auth_active_sede_clave();$stmt=$pdo->prepare("SELECT id FROM sedes WHERE clave=:c AND activo=1 LIMIT 1");$stmt->execute([':c'=>$sedeClave]);$sedeId=(string)$stmt->fetchColumn();if($sedeId===''){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'Sede activa inválida']);exit;}
$pdo->beginTransaction();
$stmt=$pdo->prepare("SELECT id,nombre,estado_administrativo FROM alumnos WHERE id=:id AND sede_id=:s LIMIT 1 FOR UPDATE");$stmt->execute([':id'=>$alumnoId,':s'=>$sedeId]);$alumno=$stmt->fetch();if(!$alumno){$pdo->rollBack();http_response_code(404);echo json_encode(['ok'=>false,'error'=>'Alumno no encontrado en la sede activa']);exit;}
if($accion==='BAJA'){$stmt=$pdo->prepare("UPDATE alumnos SET estado_administrativo='BAJA',updated_at=NOW() WHERE id=:id AND sede_id=:s");$stmt->execute([':id'=>$alumnoId,':s'=>$sedeId]);$pdo->commit();echo json_encode(['ok'=>true,'estado'=>'BAJA','mensaje'=>'Alumno dado de baja'],JSON_UNESCAPED_UNICODE);exit;}
if($accion==='REACTIVAR'){$stmt=$pdo->prepare("UPDATE alumnos SET estado_administrativo='PENDIENTE',updated_at=NOW() WHERE id=:id AND sede_id=:s");$stmt->execute([':id'=>$alumnoId,':s'=>$sedeId]);$resultado=regla_recalcular_alumno($pdo,$alumnoId);$nuevo=(string)($resultado['estado']??'PENDIENTE');$pdo->commit();echo json_encode(['ok'=>true,'estado'=>$nuevo,'mensaje'=>$nuevo==='ACTIVO'?'Alumno reactivado con obligaciones vigentes':'Alumno reabierto como pendiente hasta cubrir sus obligaciones vigentes'],JSON_UNESCAPED_UNICODE);exit;}
$tablas=['pagos','mensualidades','inscripciones','curso_intensivo_alumnos','cursos_intensivos','alumno_responsable','ausencias','asistencias','avisos_ausencia','reposiciones_regulares','mensajes','registros_publicos','notification_events','alumno_reglas_negocio','historial','usuarios'];$movimientos=0;$detalle=[];foreach($tablas as $tabla){try{$stmt=$pdo->prepare("SELECT COUNT(*) FROM `$tabla` WHERE alumno_id=:id");$stmt->execute([':id'=>$alumnoId]);$c=(int)$stmt->fetchColumn();$movimientos+=$c;if($c)$detalle[$tabla]=$c;}catch(Throwable $e){}}
if($movimientos>0){$pdo->rollBack();http_response_code(409);echo json_encode(['ok'=>false,'error'=>'El alumno tiene historial y no puede eliminarse definitivamente. Usa Dar de baja.','referencias'=>$detalle],JSON_UNESCAPED_UNICODE);exit;}
try{$stmt=$pdo->prepare("DELETE FROM alumnos WHERE id=:id AND sede_id=:s");$stmt->execute([':id'=>$alumnoId,':s'=>$sedeId]);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();http_response_code(409);echo json_encode(['ok'=>false,'error'=>'El alumno tiene información relacionada y no puede eliminarse. Usa Dar de baja.'],JSON_UNESCAPED_UNICODE);exit;}echo json_encode(['ok'=>true,'mensaje'=>'Alumno eliminado definitivamente'],JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){if(isset($pdo)&&$pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();error_log('api/alumno-gestion.php: '.$e->getMessage());http_response_code(500);echo json_encode(['ok'=>false,'error'=>'No se pudo completar la gestión del alumno.'],JSON_UNESCAPED_UNICODE);}
