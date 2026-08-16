<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
$config = require __DIR__ . '/../config/database.php';

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",
        $config['user'],
        $config['password'],
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]
    );
    $method=$_SERVER['REQUEST_METHOD'];

    if($method==='GET'){
        $cursoId=trim((string)($_GET['curso_id']??''));
        if($cursoId===''){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'curso_id es obligatorio']);exit;}
        $stmt=$pdo->prepare("SELECT id,fecha_inicio,fecha_fin,precio,estado,observaciones,created_at FROM cursos_intensivos WHERE id=:id LIMIT 1");
        $stmt->execute([':id'=>$cursoId]);$curso=$stmt->fetch();
        if(!$curso){http_response_code(404);echo json_encode(['ok'=>false,'error'=>'El curso intensivo no existe']);exit;}
        $stmt=$pdo->prepare("SELECT cia.id AS inscripcion_intensivo_id,cia.curso_intensivo_id,cia.alumno_id,a.nombre AS alumno_nombre,cia.horario_id,h.hora_inicio,h.hora_fin,cia.reposiciones_justificadas,cia.reposiciones_cancelacion,cia.continua_regular,cia.plan_continuidad_id,cia.importe_continuidad,cia.observacion_continuidad,cia.observaciones,cia.created_at FROM curso_intensivo_alumnos cia INNER JOIN alumnos a ON a.id=cia.alumno_id INNER JOIN horarios h ON h.id=cia.horario_id WHERE cia.curso_intensivo_id=:curso_id ORDER BY h.hora_inicio,a.nombre");
        $stmt->execute([':curso_id'=>$cursoId]);$alumnosCurso=$stmt->fetchAll();
        $horarios=$pdo->query("SELECT id,hora_inicio,hora_fin FROM horarios WHERE activo=1 AND intensivo=1 ORDER BY hora_inicio")->fetchAll();
        echo json_encode(['ok'=>true,'curso'=>$curso,'total_alumnos'=>count($alumnosCurso),'alumnos'=>$alumnosCurso,'horarios'=>$horarios],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);exit;
    }

    if($method==='DELETE'){
        $input=json_decode(file_get_contents('php://input'),true);
        if(!is_array($input)){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'JSON inválido']);exit;}
        $cursoId=trim((string)($input['curso_intensivo_id']??''));
        $alumnoId=trim((string)($input['alumno_id']??''));
        if($cursoId===''||$alumnoId===''){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'Curso y alumno son obligatorios']);exit;}
        $stmt=$pdo->prepare("SELECT cia.id,ci.estado FROM curso_intensivo_alumnos cia JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id WHERE cia.curso_intensivo_id=:c AND cia.alumno_id=:a LIMIT 1");
        $stmt->execute([':c'=>$cursoId,':a'=>$alumnoId]);$rel=$stmt->fetch();
        if(!$rel){http_response_code(404);echo json_encode(['ok'=>false,'error'=>'El alumno no pertenece a este curso']);exit;}
        if($rel['estado']==='TERMINADO'){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'No se puede modificar un curso terminado']);exit;}
        $pdo->beginTransaction();
        $stmt=$pdo->prepare("DELETE FROM curso_intensivo_alumnos WHERE id=:id");$stmt->execute([':id'=>$rel['id']]);
        $stmt=$pdo->prepare("SELECT COUNT(*) FROM pagos WHERE alumno_id=:a AND intensivo_id=:c AND tipo='INTENSIVO' AND estado='VALIDO'");$stmt->execute([':a'=>$alumnoId,':c'=>$cursoId]);$tienePago=((int)$stmt->fetchColumn()>0);
        $pdo->commit();
        echo json_encode(['ok'=>true,'mensaje'=>'Alumno retirado del curso intensivo','tiene_pago_intensivo_valido'=>$tienePago,'nota'=>$tienePago?'El pago válido permanece en el historial. Si fue un error, invalídalo desde Pagos.':null],JSON_UNESCAPED_UNICODE);exit;
    }

    if($method==='POST'){
        $input=json_decode(file_get_contents('php://input'),true);
        if(!is_array($input)){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'JSON inválido']);exit;}
        $cursoId=trim((string)($input['curso_intensivo_id']??''));$alumnoId=trim((string)($input['alumno_id']??''));$horarioId=trim((string)($input['horario_id']??''));$observaciones=$input['observaciones']??null;$createdBy=trim((string)($input['created_by']??''));
        if($cursoId===''){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'El curso es obligatorio']);exit;}
        if($alumnoId===''){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'Selecciona un alumno']);exit;}
        if($horarioId===''){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'Selecciona un horario']);exit;}
        if($createdBy===''){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'La sesión administrativa no está disponible']);exit;}
        $stmt=$pdo->prepare("SELECT id FROM usuarios WHERE id=:id LIMIT 1");$stmt->execute([':id'=>$createdBy]);if(!$stmt->fetch()){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'El usuario administrativo no existe']);exit;}
        $stmt=$pdo->prepare("SELECT id,estado FROM cursos_intensivos WHERE id=:id LIMIT 1");$stmt->execute([':id'=>$cursoId]);$curso=$stmt->fetch();if(!$curso){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'El curso intensivo no existe']);exit;}if($curso['estado']==='TERMINADO'){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'No se pueden agregar alumnos a un curso terminado']);exit;}
        $stmt=$pdo->prepare("SELECT id FROM alumnos WHERE id=:id LIMIT 1");$stmt->execute([':id'=>$alumnoId]);if(!$stmt->fetch()){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'El alumno seleccionado no existe']);exit;}
        $stmt=$pdo->prepare("SELECT id FROM horarios WHERE id=:id AND activo=1 AND intensivo=1 LIMIT 1");$stmt->execute([':id'=>$horarioId]);if(!$stmt->fetch()){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'El horario seleccionado no está disponible para intensivos']);exit;}
        $stmt=$pdo->prepare("SELECT id FROM curso_intensivo_alumnos WHERE curso_intensivo_id=:c AND alumno_id=:a LIMIT 1");$stmt->execute([':c'=>$cursoId,':a'=>$alumnoId]);if($stmt->fetch()){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'El alumno ya está inscrito en este curso intensivo']);exit;}
        $id=$pdo->query("SELECT UUID()")->fetchColumn();
        $stmt=$pdo->prepare("INSERT INTO curso_intensivo_alumnos(id,curso_intensivo_id,alumno_id,horario_id,observaciones,created_by) VALUES(:id,:c,:a,:h,:o,:u)");$stmt->execute([':id'=>$id,':c'=>$cursoId,':a'=>$alumnoId,':h'=>$horarioId,':o'=>$observaciones,':u'=>$createdBy]);
        http_response_code(201);echo json_encode(['ok'=>true,'mensaje'=>'Alumno agregado al curso intensivo correctamente','id'=>$id],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);exit;
    }
    http_response_code(405);echo json_encode(['ok'=>false,'error'=>'Método no permitido'],JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();http_response_code(500);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
