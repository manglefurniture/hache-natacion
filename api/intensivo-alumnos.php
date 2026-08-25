<?php

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/reglas-acceso.php';
require_once __DIR__ . '/../config/intensivos-estado.php';
$config = require __DIR__ . '/../config/database.php';

try {
    $pdo = new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $method=$_SERVER['REQUEST_METHOD'];
    $me=auth_require($method==='GET'?['ADMIN','VERIFICADOR']:['ADMIN']);
    $sedeClave=auth_active_sede_clave();
    $stmt=$pdo->prepare("SELECT id FROM sedes WHERE clave=:c AND activo=1 LIMIT 1");$stmt->execute([':c'=>$sedeClave]);$sedeId=(string)$stmt->fetchColumn();
    if($sedeId===''){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'Sede activa inválida']);exit;}

    if($method==='GET'){
        $cursoId=trim((string)($_GET['curso_id']??''));if($cursoId===''){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'curso_id es obligatorio']);exit;}
        $stmt=$pdo->prepare("SELECT id,sede_id,fecha_inicio,fecha_fin,precio,estado,observaciones,created_at FROM cursos_intensivos WHERE id=:id AND sede_id=:s LIMIT 1");$stmt->execute([':id'=>$cursoId,':s'=>$sedeId]);$curso=$stmt->fetch();
        if(!$curso){http_response_code(404);echo json_encode(['ok'=>false,'error'=>'El curso intensivo no existe']);exit;}
        $curso['estado']=intensivo_estado_por_fechas((string)$curso['fecha_inicio'],(string)$curso['fecha_fin']);
        $curso['inscripcion_abierta']=intensivo_inscripcion_abierta((string)$curso['fecha_inicio']);
        $curso['fecha_cierre_inscripcion']=intensivo_cierre_inscripcion((string)$curso['fecha_inicio']);
        $stmt=$pdo->prepare("SELECT cia.id AS inscripcion_intensivo_id,cia.curso_intensivo_id,cia.alumno_id,a.nombre AS alumno_nombre,cia.horario_id,h.hora_inicio,h.hora_fin,cia.reposiciones_justificadas,cia.reposiciones_cancelacion,cia.continua_regular,cia.plan_continuidad_id,cia.importe_continuidad,cia.observacion_continuidad,cia.observaciones,cia.created_at,EXISTS(SELECT 1 FROM pagos p WHERE p.alumno_id=cia.alumno_id AND p.intensivo_id=cia.curso_intensivo_id AND p.tipo='INTENSIVO' AND p.estado='VALIDO') AS intensivo_pagado FROM curso_intensivo_alumnos cia INNER JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id INNER JOIN alumnos a ON a.id=cia.alumno_id AND a.sede_id=ci.sede_id INNER JOIN horarios h ON h.id=cia.horario_id AND h.sede_id=ci.sede_id WHERE cia.curso_intensivo_id=:curso_id AND ci.sede_id=:s ORDER BY h.hora_inicio,a.nombre");$stmt->execute([':curso_id'=>$cursoId,':s'=>$sedeId]);$alumnosCurso=$stmt->fetchAll();foreach($alumnosCurso as &$alumnoCurso){$alumnoCurso['intensivo_pagado']=(int)($alumnoCurso['intensivo_pagado']??0)===1;}unset($alumnoCurso);
        $stmt=$pdo->prepare("SELECT id,hora_inicio,hora_fin FROM horarios WHERE sede_id=:s AND activo=1 AND intensivo=1 ORDER BY hora_inicio");$stmt->execute([':s'=>$sedeId]);$horarios=$stmt->fetchAll();
        echo json_encode(['ok'=>true,'curso'=>$curso,'total_alumnos'=>count($alumnosCurso),'alumnos'=>$alumnosCurso,'horarios'=>$horarios],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);exit;
    }

    if($method==='DELETE'){
        $input=json_decode(file_get_contents('php://input'),true);if(!is_array($input)){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'JSON inválido']);exit;}
        $cursoId=trim((string)($input['curso_intensivo_id']??''));$alumnoId=trim((string)($input['alumno_id']??''));if($cursoId===''||$alumnoId===''){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'Curso y alumno son obligatorios']);exit;}
        intensivos_reconciliar_estados_sede($pdo,$sedeId);$pdo->beginTransaction();
        $stmt=$pdo->prepare("SELECT cia.id,ci.estado FROM curso_intensivo_alumnos cia JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id JOIN alumnos a ON a.id=cia.alumno_id AND a.sede_id=ci.sede_id WHERE cia.curso_intensivo_id=:c AND cia.alumno_id=:a AND ci.sede_id=:s LIMIT 1 FOR UPDATE");$stmt->execute([':c'=>$cursoId,':a'=>$alumnoId,':s'=>$sedeId]);$rel=$stmt->fetch();
        if(!$rel){$pdo->rollBack();http_response_code(404);echo json_encode(['ok'=>false,'error'=>'El alumno no pertenece a este curso']);exit;}
        if($rel['estado']==='TERMINADO'){$pdo->rollBack();http_response_code(422);echo json_encode(['ok'=>false,'error'=>'No se puede modificar un curso terminado']);exit;}
        $stmt=$pdo->prepare("DELETE FROM curso_intensivo_alumnos WHERE id=:id");$stmt->execute([':id'=>$rel['id']]);
        $stmt=$pdo->prepare("SELECT COUNT(*) FROM pagos WHERE alumno_id=:a AND intensivo_id=:c AND tipo='INTENSIVO' AND estado='VALIDO'");$stmt->execute([':a'=>$alumnoId,':c'=>$cursoId]);$tienePago=((int)$stmt->fetchColumn()>0);regla_recalcular_alumno($pdo,$alumnoId);$pdo->commit();
        echo json_encode(['ok'=>true,'mensaje'=>'Alumno retirado del curso intensivo','tiene_pago_intensivo_valido'=>$tienePago,'nota'=>$tienePago?'El pago válido permanece en el historial. Si fue un error, invalídalo desde Pagos.':null],JSON_UNESCAPED_UNICODE);exit;
    }

    if($method==='POST'){
        $input=json_decode(file_get_contents('php://input'),true);if(!is_array($input)){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'JSON inválido']);exit;}
        $cursoId=trim((string)($input['curso_intensivo_id']??''));$alumnoId=trim((string)($input['alumno_id']??''));$horarioId=trim((string)($input['horario_id']??''));$observaciones=trim((string)($input['observaciones']??''));$createdBy=(string)$me['id'];
        if($cursoId===''){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'El curso es obligatorio']);exit;}if($alumnoId===''){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'Selecciona un alumno']);exit;}if($horarioId===''){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'Selecciona un horario']);exit;}if(mb_strlen($observaciones)>1000){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'Las observaciones no pueden exceder 1000 caracteres']);exit;}
        intensivos_reconciliar_estados_sede($pdo,$sedeId);$pdo->beginTransaction();
        $stmt=$pdo->prepare("SELECT id FROM alumnos WHERE id=:id AND sede_id=:s AND estado_administrativo<>'BAJA' LIMIT 1 FOR UPDATE");$stmt->execute([':id'=>$alumnoId,':s'=>$sedeId]);if(!$stmt->fetch()){$pdo->rollBack();http_response_code(422);echo json_encode(['ok'=>false,'error'=>'El alumno no pertenece a la sede del curso o está dado de baja']);exit;}
        $stmt=$pdo->prepare("SELECT id,estado,fecha_inicio,fecha_fin FROM cursos_intensivos WHERE id=:id AND sede_id=:s LIMIT 1 FOR UPDATE");$stmt->execute([':id'=>$cursoId,':s'=>$sedeId]);$curso=$stmt->fetch();
        if(!$curso){$pdo->rollBack();http_response_code(422);echo json_encode(['ok'=>false,'error'=>'El curso intensivo no existe en la sede activa']);exit;}
        if(!intensivo_inscripcion_abierta((string)$curso['fecha_inicio'])){$pdo->rollBack();http_response_code(422);echo json_encode(['ok'=>false,'error'=>'La ventana de inscripción de este curso cerró el '.date('d/m/Y',strtotime(intensivo_cierre_inscripcion((string)$curso['fecha_inicio'])))],JSON_UNESCAPED_UNICODE);exit;}
        $stmt=$pdo->prepare("SELECT id FROM horarios WHERE id=:id AND sede_id=:s AND activo=1 AND intensivo=1 LIMIT 1 FOR UPDATE");$stmt->execute([':id'=>$horarioId,':s'=>$sedeId]);if(!$stmt->fetch()){$pdo->rollBack();http_response_code(422);echo json_encode(['ok'=>false,'error'=>'El horario no pertenece a la sede del curso']);exit;}
        $stmt=$pdo->prepare("SELECT id FROM curso_intensivo_alumnos WHERE curso_intensivo_id=:c AND alumno_id=:a LIMIT 1");$stmt->execute([':c'=>$cursoId,':a'=>$alumnoId]);if($stmt->fetch()){$pdo->rollBack();http_response_code(422);echo json_encode(['ok'=>false,'error'=>'El alumno ya está inscrito en este curso intensivo']);exit;}
        $stmt=$pdo->prepare("SELECT ci.id FROM curso_intensivo_alumnos cia INNER JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id WHERE cia.alumno_id=:a AND ci.id<>:c AND ci.sede_id=:s AND ci.estado IN ('PROGRAMADO','EN_CURSO') LIMIT 1");$stmt->execute([':a'=>$alumnoId,':c'=>$cursoId,':s'=>$sedeId]);if($stmt->fetch()){$pdo->rollBack();http_response_code(409);echo json_encode(['ok'=>false,'error'=>'El alumno ya pertenece a otro curso intensivo activo']);exit;}
        $id=$pdo->query("SELECT UUID()")->fetchColumn();$stmt=$pdo->prepare("INSERT INTO curso_intensivo_alumnos(id,curso_intensivo_id,alumno_id,horario_id,observaciones,created_by) VALUES(:id,:c,:a,:h,:o,:u)");$stmt->execute([':id'=>$id,':c'=>$cursoId,':a'=>$alumnoId,':h'=>$horarioId,':o'=>$observaciones!==''?$observaciones:null,':u'=>$createdBy]);
        $stmt=$pdo->prepare("UPDATE alumnos SET plan_actual_id=NULL,horario_preferido_id=NULL,plan_programado_id=NULL,plan_programado_desde=NULL,estado_administrativo='PENDIENTE',updated_at=NOW() WHERE id=:a AND sede_id=:s AND estado_administrativo<>'BAJA'");$stmt->execute([':a'=>$alumnoId,':s'=>$sedeId]);regla_recalcular_alumno($pdo,$alumnoId);$pdo->commit();
        http_response_code(201);echo json_encode(['ok'=>true,'mensaje'=>'Alumno agregado al curso intensivo correctamente','id'=>$id],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);exit;
    }
    http_response_code(405);echo json_encode(['ok'=>false,'error'=>'Método no permitido'],JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();error_log('[intensivo-alumnos] '.$e->getMessage());http_response_code(500);echo json_encode(['ok'=>false,'error'=>'No se pudo procesar la solicitud'],JSON_UNESCAPED_UNICODE);}
