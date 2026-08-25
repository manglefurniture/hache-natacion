<?php

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/auth.php';
require_once __DIR__.'/../config/reglas-acceso.php';
require_once __DIR__.'/../config/rate-limit.php';
require_once __DIR__.'/../config/periodos-financieros.php';
$me=auth_require(['ADMIN']);
$config=require __DIR__.'/../config/database.php';
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){http_response_code(405);header('Allow: POST');echo json_encode(['ok'=>false,'error'=>'Método no permitido'],JSON_UNESCAPED_UNICODE);exit;}

function tabla_tiene_columna(PDO $pdo,string $tabla,string $columna):bool
{
    static $cache=[];$k=$tabla.'.'.$columna;if(isset($cache[$k]))return $cache[$k];
    $st=$pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=:t AND column_name=:c");$st->execute([':t'=>$tabla,':c'=>$columna]);return $cache[$k]=((int)$st->fetchColumn()>0);
}
function borrar_por_alumno(PDO $pdo,string $tabla,string $alumnoId):int
{
    $permitidas=['pagos','reposiciones_regulares','asistencias','ausencias','avisos_ausencia','notificaciones','mensajes','notification_events','registros_publicos','historial','alumno_reglas_negocio','curso_intensivo_alumnos','mensualidades','inscripciones','alumno_responsable'];
    if(!in_array($tabla,$permitidas,true)||!tabla_tiene_columna($pdo,$tabla,'alumno_id'))return 0;
    $st=$pdo->prepare("DELETE FROM `$tabla` WHERE alumno_id=:id");$st->execute([':id'=>$alumnoId]);return $st->rowCount();
}
function periodos_cerrados_alumno(PDO $pdo,string $alumnoId,string $sedeId):array
{
    $st=$pdo->prepare("SELECT p.tipo,DATE(p.fecha) fecha_pago,p.created_at,p.invalidated_at,m.mes,m.anio,i.fecha inscripcion_fecha,ci.fecha_inicio intensivo_fecha FROM pagos p LEFT JOIN mensualidades m ON m.id=p.mensualidad_id LEFT JOIN inscripciones i ON i.id=p.inscripcion_id LEFT JOIN cursos_intensivos ci ON ci.id=p.intensivo_id WHERE p.alumno_id=:a FOR UPDATE");$st->execute([':a'=>$alumnoId]);
    $periodos=[];foreach($st->fetchAll() as $p){
        if($p['tipo']==='MENSUALIDAD'&&!empty($p['mes'])&&!empty($p['anio']))$periodo=sprintf('%04d-%02d',(int)$p['anio'],(int)$p['mes']);
        elseif($p['tipo']==='INTENSIVO'&&!empty($p['intensivo_fecha']))$periodo=financiero_periodo_para_fecha($pdo,$sedeId,(string)$p['intensivo_fecha']);
        elseif($p['tipo']==='INSCRIPCION'&&!empty($p['inscripcion_fecha']))$periodo=financiero_periodo_para_fecha($pdo,$sedeId,(string)$p['inscripcion_fecha']);
        else $periodo=financiero_periodo_para_fecha($pdo,$sedeId,(string)$p['fecha_pago']);
        $periodos[$periodo][]=$p;
    }
    if(!$periodos)return [];
    $cerrados=[];$st=$pdo->prepare("SELECT cerrado_at FROM cierres_mensuales WHERE sede_id=:s AND periodo=:p LIMIT 1 FOR UPDATE");
    foreach($periodos as $periodo=>$pagos){$st->execute([':s'=>$sedeId,':p'=>$periodo.'-01']);$cerradoAt=$st->fetchColumn();if($cerradoAt===false)continue;foreach($pagos as $p){if((string)$p['created_at']<=(string)$cerradoAt&&($p['invalidated_at']===null||(string)$p['invalidated_at']>=(string)$cerradoAt)){$cerrados[]=$periodo;break;}}}
    sort($cerrados);return $cerrados;
}
function borrar_suscripciones_usuario(PDO $pdo,array $usuarios):int
{
    if(!$usuarios||!tabla_tiene_columna($pdo,'push_subscriptions','usuario_id'))return 0;
    $marks=implode(',',array_fill(0,count($usuarios),'?'));$st=$pdo->prepare("DELETE FROM push_subscriptions WHERE usuario_id IN ($marks)");$st->execute($usuarios);return $st->rowCount();
}
function auditar_eliminacion(PDO $pdo,array $me,array $alumno,array $detalle):void
{
    if(!tabla_tiene_columna($pdo,'auditoria_eventos','entidad_id'))return;
    $st=$pdo->prepare("INSERT INTO auditoria_eventos(usuario_id,usuario_nombre,accion,entidad,entidad_id,detalle,metodo,ruta) VALUES(:uid,:un,'ELIMINAR_DEFINITIVO','alumno',:aid,:detalle,'POST','/api/alumno-gestion.php')");
    $st->execute([':uid'=>$me['id'],':un'=>$me['usuario']??null,':aid'=>$alumno['id'],':detalle'=>json_encode(['alumno'=>$alumno['nombre'],'eliminados'=>$detalle],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
}

try{
    $pdo=new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $input=json_decode(file_get_contents('php://input'),true);if(!is_array($input)||json_last_error()!==JSON_ERROR_NONE){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'JSON inválido']);exit;}
    $alumnoId=trim((string)($input['alumno_id']??''));$accion=strtoupper(trim((string)($input['accion']??'')));
    if($alumnoId===''||!in_array($accion,['BAJA','REACTIVAR','ELIMINAR'],true)){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'Acción inválida']);exit;}
    $sedeClave=auth_active_sede_clave();$stmt=$pdo->prepare("SELECT id FROM sedes WHERE clave=:c AND activo=1 LIMIT 1");$stmt->execute([':c'=>$sedeClave]);$sedeId=(string)$stmt->fetchColumn();if($sedeId===''){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'Sede activa inválida']);exit;}

    if($accion==='ELIMINAR'){
        if(!auth_csrf_validate(isset($input['csrf'])?(string)$input['csrf']:null)){http_response_code(419);echo json_encode(['ok'=>false,'error'=>'La confirmación venció. Recarga la ficha e inténtalo otra vez.'],JSON_UNESCAPED_UNICODE);exit;}
        $password=(string)($input['password']??'');if($password===''){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'Escribe tu contraseña para confirmar la eliminación'],JSON_UNESCAPED_UNICODE);exit;}
        $rateKey=(string)$me['id'];$limit=security_rate_limit_check('admin-delete-password',$rateKey,5,900);if(!$limit['allowed']){header('Retry-After: '.max(1,(int)$limit['retry_after']));http_response_code(429);echo json_encode(['ok'=>false,'error'=>'Demasiados intentos de confirmación. Espera unos minutos.'],JSON_UNESCAPED_UNICODE);exit;}
        $st=$pdo->prepare("SELECT password_hash FROM usuarios WHERE id=:id AND rol='ADMIN' AND activo=1 LIMIT 1");$st->execute([':id'=>$me['id']]);$hash=(string)($st->fetchColumn()?:'');
        if($hash===''||!password_verify($password,$hash)){security_rate_limit_record('admin-delete-password',$rateKey,5,900);http_response_code(401);echo json_encode(['ok'=>false,'error'=>'Contraseña incorrecta'],JSON_UNESCAPED_UNICODE);exit;}
        security_rate_limit_clear('admin-delete-password',$rateKey);
    }

    $pdo->beginTransaction();
    $stmt=$pdo->prepare("SELECT id,nombre,estado_administrativo FROM alumnos WHERE id=:id AND sede_id=:s LIMIT 1 FOR UPDATE");$stmt->execute([':id'=>$alumnoId,':s'=>$sedeId]);$alumno=$stmt->fetch();if(!$alumno){$pdo->rollBack();http_response_code(404);echo json_encode(['ok'=>false,'error'=>'Alumno no encontrado en la sede activa']);exit;}

    if($accion==='BAJA'){$stmt=$pdo->prepare("UPDATE alumnos SET estado_administrativo='BAJA',updated_at=NOW() WHERE id=:id AND sede_id=:s");$stmt->execute([':id'=>$alumnoId,':s'=>$sedeId]);$pdo->commit();echo json_encode(['ok'=>true,'estado'=>'BAJA','mensaje'=>'Alumno dado de baja'],JSON_UNESCAPED_UNICODE);exit;}
    if($accion==='REACTIVAR'){$stmt=$pdo->prepare("UPDATE alumnos SET estado_administrativo='PENDIENTE',updated_at=NOW() WHERE id=:id AND sede_id=:s");$stmt->execute([':id'=>$alumnoId,':s'=>$sedeId]);$resultado=regla_recalcular_alumno($pdo,$alumnoId);$nuevo=(string)($resultado['estado']??'PENDIENTE');$pdo->commit();echo json_encode(['ok'=>true,'estado'=>$nuevo,'mensaje'=>$nuevo==='ACTIVO'?'Alumno reactivado con obligaciones vigentes':'Alumno reabierto como pendiente hasta cubrir sus obligaciones vigentes'],JSON_UNESCAPED_UNICODE);exit;}

    $cerrados=periodos_cerrados_alumno($pdo,$alumnoId,$sedeId);if($cerrados){$pdo->rollBack();http_response_code(409);echo json_encode(['ok'=>false,'error'=>'No se puede eliminar definitivamente: el alumno tiene pagos que formaron parte de un cierre mensual ya congelado. Usa Dar de baja o una corrección contable.','periodos_cerrados'=>$cerrados],JSON_UNESCAPED_UNICODE);exit;}

    $usuarios=[];if(tabla_tiene_columna($pdo,'usuarios','alumno_id')){$st=$pdo->prepare("SELECT id FROM usuarios WHERE alumno_id=:a FOR UPDATE");$st->execute([':a'=>$alumnoId]);$usuarios=array_map('strval',$st->fetchAll(PDO::FETCH_COLUMN));}
    $responsables=[];if(tabla_tiene_columna($pdo,'alumno_responsable','responsable_id')&&tabla_tiene_columna($pdo,'responsables','id')){$st=$pdo->prepare("SELECT responsable_id FROM alumno_responsable WHERE alumno_id=:a FOR UPDATE");$st->execute([':a'=>$alumnoId]);$responsables=array_map('strval',$st->fetchAll(PDO::FETCH_COLUMN));}
    $detalle=[];
    foreach(['pagos','reposiciones_regulares','asistencias','ausencias','avisos_ausencia','notificaciones','mensajes','notification_events','registros_publicos'] as $tabla){$n=borrar_por_alumno($pdo,$tabla,$alumnoId);if($n)$detalle[$tabla]=$n;}
    if($usuarios&&tabla_tiene_columna($pdo,'historial','usuario_id')){$marks=implode(',',array_fill(0,count($usuarios),'?'));$st=$pdo->prepare("DELETE FROM historial WHERE alumno_id=? OR usuario_id IN ($marks)");$st->execute(array_merge([$alumnoId],$usuarios));$n=$st->rowCount();if($n)$detalle['historial']=$n;}else{$n=borrar_por_alumno($pdo,'historial',$alumnoId);if($n)$detalle['historial']=$n;}
    foreach(['alumno_reglas_negocio','curso_intensivo_alumnos','mensualidades','inscripciones','alumno_responsable'] as $tabla){$n=borrar_por_alumno($pdo,$tabla,$alumnoId);if($n)$detalle[$tabla]=$n;}
    if($responsables){$marks=implode(',',array_fill(0,count($responsables),'?'));$st=$pdo->prepare("DELETE r FROM responsables r LEFT JOIN alumno_responsable ar ON ar.responsable_id=r.id WHERE r.id IN ($marks) AND ar.responsable_id IS NULL");$st->execute($responsables);if($st->rowCount())$detalle['responsables']=$st->rowCount();}
    if(tabla_tiene_columna($pdo,'cursos_intensivos','alumno_id')){$st=$pdo->prepare("UPDATE cursos_intensivos SET alumno_id=NULL WHERE alumno_id=:a");$st->execute([':a'=>$alumnoId]);if($st->rowCount())$detalle['cursos_intensivos_legacy_desvinculados']=$st->rowCount();}
    $n=borrar_suscripciones_usuario($pdo,$usuarios);if($n)$detalle['push_subscriptions']=$n;
    if($usuarios){$marks=implode(',',array_fill(0,count($usuarios),'?'));$st=$pdo->prepare("DELETE FROM usuarios WHERE id IN ($marks)");$st->execute($usuarios);if($st->rowCount())$detalle['usuarios']=$st->rowCount();}
    auditar_eliminacion($pdo,$me,$alumno,$detalle);
    $stmt=$pdo->prepare("DELETE FROM alumnos WHERE id=:id AND sede_id=:s");$stmt->execute([':id'=>$alumnoId,':s'=>$sedeId]);if($stmt->rowCount()!==1)throw new RuntimeException('No se eliminó la ficha del alumno');$detalle['alumnos']=1;
    $pdo->commit();echo json_encode(['ok'=>true,'mensaje'=>'Alumno y registros asociados eliminados definitivamente','eliminados'=>$detalle],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
}catch(Throwable $e){if(isset($pdo)&&$pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();error_log('api/alumno-gestion.php: '.$e->getMessage());http_response_code(500);echo json_encode(['ok'=>false,'error'=>'No se pudo completar la gestión del alumno. No se eliminó información.'],JSON_UNESCAPED_UNICODE);}
