<?php

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
$config = require __DIR__ . '/../config/database.php';

function periodoMensualidad(string $sedeClave,?string $ciclo,int $anio,int $mes):array{
    if($sedeClave==='PALAPAS'&&$ciclo==='P15'){
        $inicio=new DateTimeImmutable(sprintf('%04d-%02d-15',$anio,$mes));$fin=$inicio->modify('+1 month')->modify('-1 day');
    }else{$inicio=new DateTimeImmutable(sprintf('%04d-%02d-01',$anio,$mes));$fin=$inicio->modify('last day of this month');}
    return ['mes'=>$mes,'anio'=>$anio,'inicio'=>$inicio->format('Y-m-d'),'fin'=>$fin->format('Y-m-d'),'etiqueta'=>$inicio->format('d/m/Y').' al '.$fin->format('d/m/Y'),'ciclo'=>$ciclo];
}

try {
    $pdo = new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $pdo->exec("UPDATE alumnos SET plan_actual_id=plan_programado_id,plan_programado_id=NULL,plan_programado_desde=NULL,updated_at=NOW() WHERE plan_programado_id IS NOT NULL AND plan_programado_desde IS NOT NULL AND plan_programado_desde<=CURDATE()");

    $alumnoId=trim((string)($_GET['alumno_id']??''));
    $mes=(int)($_GET['mes']??date('n'));$anio=(int)($_GET['anio']??date('Y'));
    if($alumnoId===''){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'alumno_id es obligatorio']);exit;}
    if($mes<1||$mes>12||$anio<2000||$anio>2100){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'Periodo inválido']);exit;}

    $stmt=$pdo->prepare("SELECT a.id,a.sede_id,s.clave sede_clave,a.ciclo_pago,a.nombre,a.plan_actual_id,a.plan_programado_id,a.plan_programado_desde,p.nombre plan_nombre,p.precio plan_precio,pp.nombre plan_programado_nombre,pp.precio plan_programado_precio FROM alumnos a INNER JOIN sedes s ON s.id=a.sede_id LEFT JOIN planes p ON p.id=a.plan_actual_id LEFT JOIN planes pp ON pp.id=a.plan_programado_id WHERE a.id=:id LIMIT 1");
    $stmt->execute([':id'=>$alumnoId]);$alumno=$stmt->fetch();if(!$alumno){http_response_code(404);echo json_encode(['ok'=>false,'error'=>'Alumno no encontrado']);exit;}
    $sedeClave=strtoupper((string)$alumno['sede_clave']);$ciclo=$alumno['ciclo_pago']!==null?strtoupper((string)$alumno['ciclo_pago']):null;
    if($sedeClave==='PALAPAS'&&!in_array($ciclo,['P1','P15'],true)&&!empty($alumno['plan_actual_id'])){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'El alumno regular de Palapas no tiene ciclo P1/P15 definido']);exit;}
    $periodo=periodoMensualidad($sedeClave,$ciclo,$anio,$mes);$periodoInicio=new DateTimeImmutable($periodo['inicio']);
    $planes=$pdo->query("SELECT id,nombre,sesiones_semana,precio FROM planes WHERE activo=1 ORDER BY sesiones_semana,nombre")->fetchAll();

    $stmt=$pdo->prepare("SELECT p.fecha,p.folio FROM pagos p WHERE p.alumno_id=:id AND p.tipo='INSCRIPCION' AND p.estado='VALIDO' ORDER BY p.fecha DESC LIMIT 1");$stmt->execute([':id'=>$alumnoId]);$ultimaInscripcion=$stmt->fetch()?:null;
    $stmt=$pdo->prepare("SELECT id,estado,fecha_pago,importe_cobrado,plan_id,periodo_inicio,periodo_fin FROM mensualidades WHERE alumno_id=:id AND mes=:mes AND anio=:anio LIMIT 1");$stmt->execute([':id'=>$alumnoId,':mes'=>$mes,':anio'=>$anio]);$mensualidadPeriodo=$stmt->fetch()?:null;
    $stmt=$pdo->prepare("SELECT mes,anio,estado,importe_a_cobrar,periodo_inicio,periodo_fin FROM mensualidades WHERE alumno_id=:id AND estado='PENDIENTE' AND (anio<:anio1 OR (anio=:anio2 AND mes<:mes)) ORDER BY anio DESC,mes DESC LIMIT 3");$stmt->execute([':id'=>$alumnoId,':anio1'=>$anio,':anio2'=>$anio,':mes'=>$mes]);$pendientes=$stmt->fetchAll();
    $stmt=$pdo->prepare("SELECT ci.id,ci.fecha_inicio,ci.fecha_fin,ci.precio,ci.estado FROM curso_intensivo_alumnos cia INNER JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id WHERE cia.alumno_id=:id AND ci.estado IN ('PROGRAMADO','EN_CURSO') ORDER BY ci.fecha_inicio DESC LIMIT 1");$stmt->execute([':id'=>$alumnoId]);$intensivo=$stmt->fetch()?:null;

    $inscripcionPermitida=true;$proximaInscripcion=null;
    if($ultimaInscripcion){$ultima=new DateTimeImmutable(substr($ultimaInscripcion['fecha'],0,10));$permitida=$ultima->modify('first day of this month')->modify('+3 months');$hoy=new DateTimeImmutable('today');$inscripcionPermitida=$hoy>=$permitida;$proximaInscripcion=$permitida->format('Y-m-d');}

    $planPeriodo=['id'=>$alumno['plan_actual_id'],'nombre'=>$alumno['plan_nombre'],'precio'=>$alumno['plan_precio']];
    if(!empty($alumno['plan_programado_id'])&&!empty($alumno['plan_programado_desde'])&&$periodoInicio>=new DateTimeImmutable($alumno['plan_programado_desde'])){$planPeriodo=['id'=>$alumno['plan_programado_id'],'nombre'=>$alumno['plan_programado_nombre'],'precio'=>$alumno['plan_programado_precio']];}

    echo json_encode(['ok'=>true,'periodo'=>$periodo,'alumno'=>$alumno,'plan_periodo'=>$planPeriodo,'planes'=>$planes,'ultima_inscripcion'=>$ultimaInscripcion,'inscripcion_permitida'=>$inscripcionPermitida,'proxima_inscripcion'=>$proximaInscripcion,'mensualidad_periodo'=>$mensualidadPeriodo,'mensualidades_pendientes'=>$pendientes,'intensivo_activo'=>$intensivo],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
} catch(Throwable $e){http_response_code(500);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
