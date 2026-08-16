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
    $alumnoId = trim((string)($_GET['alumno_id'] ?? ''));
    if ($alumnoId === '') { http_response_code(422); echo json_encode(['ok'=>false,'error'=>'alumno_id es obligatorio']); exit; }

    $stmt=$pdo->prepare("SELECT a.id,a.nombre,a.plan_actual_id,p.nombre plan_nombre,p.precio plan_precio FROM alumnos a LEFT JOIN planes p ON p.id=a.plan_actual_id WHERE a.id=:id LIMIT 1");
    $stmt->execute([':id'=>$alumnoId]); $alumno=$stmt->fetch();
    if(!$alumno){http_response_code(404);echo json_encode(['ok'=>false,'error'=>'Alumno no encontrado']);exit;}

    $planes=$pdo->query("SELECT id,nombre,sesiones_semana,precio FROM planes WHERE activo=1 ORDER BY sesiones_semana,nombre")->fetchAll();

    $stmt=$pdo->prepare("SELECT p.fecha,p.folio FROM pagos p WHERE p.alumno_id=:id AND p.tipo='INSCRIPCION' AND p.estado='VALIDO' ORDER BY p.fecha DESC LIMIT 1");
    $stmt->execute([':id'=>$alumnoId]); $ultimaInscripcion=$stmt->fetch() ?: null;

    $stmt=$pdo->prepare("SELECT id,estado,fecha_pago,importe_cobrado FROM mensualidades WHERE alumno_id=:id AND mes=:mes AND anio=:anio LIMIT 1");
    $stmt->execute([':id'=>$alumnoId,':mes'=>(int)date('n'),':anio'=>(int)date('Y')]); $mensualidadActual=$stmt->fetch() ?: null;

    $stmt=$pdo->prepare("SELECT mes,anio,estado,importe_a_cobrar FROM mensualidades WHERE alumno_id=:id AND estado='PENDIENTE' AND (anio<:anio OR (anio=:anio AND mes<:mes)) ORDER BY anio DESC,mes DESC LIMIT 3");
    $stmt->execute([':id'=>$alumnoId,':anio'=>(int)date('Y'),':mes'=>(int)date('n')]); $pendientes=$stmt->fetchAll();

    $stmt=$pdo->prepare("SELECT ci.id,ci.fecha_inicio,ci.fecha_fin,ci.precio,ci.estado FROM curso_intensivo_alumnos cia INNER JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id WHERE cia.alumno_id=:id AND ci.estado IN ('PROGRAMADO','EN_CURSO') ORDER BY ci.fecha_inicio DESC LIMIT 1");
    $stmt->execute([':id'=>$alumnoId]); $intensivo=$stmt->fetch() ?: null;

    $inscripcionPermitida=true; $proximaInscripcion=null;
    if($ultimaInscripcion){
        $ultima=new DateTimeImmutable(substr($ultimaInscripcion['fecha'],0,10));
        $inicioMes=$ultima->modify('first day of this month');
        $permitida=$inicioMes->modify('+3 months');
        $hoy=new DateTimeImmutable('today');
        $inscripcionPermitida=$hoy >= $permitida;
        $proximaInscripcion=$permitida->format('Y-m-d');
    }

    echo json_encode(['ok'=>true,'alumno'=>$alumno,'planes'=>$planes,'ultima_inscripcion'=>$ultimaInscripcion,'inscripcion_permitida'=>$inscripcionPermitida,'proxima_inscripcion'=>$proximaInscripcion,'mensualidad_actual'=>$mensualidadActual,'mensualidades_pendientes'=>$pendientes,'intensivo_activo'=>$intensivo],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
} catch(Throwable $e){http_response_code(500);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
