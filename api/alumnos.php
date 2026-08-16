<?php

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
$config = require __DIR__ . '/../config/database.php';

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",
        $config['user'], $config['password'],
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]
    );

    // Hace efectivo un cambio de plan programado cuando llega su mes natural.
    $pdo->exec("UPDATE alumnos SET plan_actual_id=plan_programado_id, plan_programado_id=NULL, plan_programado_desde=NULL, updated_at=NOW() WHERE plan_programado_id IS NOT NULL AND plan_programado_desde IS NOT NULL AND plan_programado_desde<=CURDATE()");

    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $stmt = $pdo->query("
            SELECT a.id,a.nombre,a.fecha_nacimiento,a.whatsapp,a.correo,a.fecha_inicio,
                   a.horario_preferido_id,a.plan_actual_id,a.plan_programado_id,a.plan_programado_desde,
                   p.nombre AS plan_nombre,p.precio AS plan_precio,
                   pp.nombre AS plan_programado_nombre,pp.precio AS plan_programado_precio,
                   a.estado_administrativo,a.observaciones,a.created_at,a.updated_at
            FROM alumnos a
            LEFT JOIN planes p ON p.id=a.plan_actual_id
            LEFT JOIN planes pp ON pp.id=a.plan_programado_id
            ORDER BY a.nombre ASC
        ");
        $alumnos=$stmt->fetchAll();
        echo json_encode(['ok'=>true,'total'=>count($alumnos),'alumnos'=>$alumnos],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);exit;
    }

    if ($method === 'POST') {
        $input=json_decode(file_get_contents('php://input'),true);
        if(!is_array($input)){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'JSON inválido']);exit;}

        $nombre=trim((string)($input['nombre']??''));
        $fechaNacimiento=trim((string)($input['fecha_nacimiento']??''));
        $whatsapp=trim((string)($input['whatsapp']??''));
        $correo=trim((string)($input['correo']??''));
        $fechaInicio=trim((string)($input['fecha_inicio']??''));
        $tipoIngreso=strtoupper(trim((string)($input['tipo_ingreso']??'REGULAR')));
        $horarioId=$input['horario_preferido_id']??null;
        $planId=$input['plan_actual_id']??null;
        $observaciones=$input['observaciones']??null;

        if($nombre===''){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'El nombre es obligatorio']);exit;}
        if($whatsapp===''){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'El WhatsApp es obligatorio']);exit;}
        if($fechaInicio===''){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'La fecha de inicio es obligatoria']);exit;}
        if(!in_array($tipoIngreso,['REGULAR','INTENSIVO'],true)){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'Tipo de ingreso inválido']);exit;}

        if($tipoIngreso==='REGULAR'){
            if(empty($horarioId)||empty($planId)){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'Plan y horario son obligatorios para alumnos regulares']);exit;}
            $stmt=$pdo->prepare("SELECT id FROM horarios WHERE id=:id AND activo=1 AND regular=1 LIMIT 1");$stmt->execute([':id'=>$horarioId]);if(!$stmt->fetch()){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'Horario regular inválido']);exit;}
            $stmt=$pdo->prepare("SELECT id FROM planes WHERE id=:id AND activo=1 LIMIT 1");$stmt->execute([':id'=>$planId]);if(!$stmt->fetch()){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'Plan inválido']);exit;}
        } else {$horarioId=null;$planId=null;}

        $id=$pdo->query('SELECT UUID()')->fetchColumn();
        $stmt=$pdo->prepare("INSERT INTO alumnos(id,nombre,fecha_nacimiento,whatsapp,correo,fecha_inicio,horario_preferido_id,plan_actual_id,estado_administrativo,observaciones) VALUES(:id,:nombre,:nac,:wa,:correo,:inicio,:horario,:plan,'PENDIENTE',:obs)");
        $stmt->execute([
            ':id'=>$id,':nombre'=>$nombre,':nac'=>$fechaNacimiento!==''?$fechaNacimiento:null,
            ':wa'=>$whatsapp,':correo'=>$correo!==''?$correo:null,':inicio'=>$fechaInicio,
            ':horario'=>$horarioId,':plan'=>$planId,':obs'=>$observaciones
        ]);

        $stmt=$pdo->prepare("SELECT a.*,p.nombre plan_nombre,p.precio plan_precio FROM alumnos a LEFT JOIN planes p ON p.id=a.plan_actual_id WHERE a.id=:id LIMIT 1");$stmt->execute([':id'=>$id]);
        http_response_code(201);echo json_encode(['ok'=>true,'mensaje'=>'Alumno creado correctamente. Queda pendiente hasta registrar su primer pago válido.','tipo_ingreso'=>$tipoIngreso,'alumno'=>$stmt->fetch()],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);exit;
    }

    http_response_code(405);echo json_encode(['ok'=>false,'error'=>'Método no permitido']);
} catch(Throwable $e){http_response_code(500);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
