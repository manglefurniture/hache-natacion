<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/auth.php';
require_once __DIR__.'/../config/reglas-acceso.php';
require_once __DIR__.'/../config/intensivos-estado.php';
$me=auth_require(['ADMIN','VERIFICADOR']);
$config=require __DIR__.'/../config/database.php';

function pago_contexto_out(array $data,int $status=200): never
{
    http_response_code($status);
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
    exit;
}

try{
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')pago_contexto_out(['ok'=>false,'error'=>'Método no permitido'],405);
    $pdo=new PDO(
        "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",
        $config['user'],$config['password'],
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]
    );
    $sedeClave=auth_active_sede_clave();
    $st=$pdo->prepare("SELECT id FROM sedes WHERE clave=:c AND activo=1 LIMIT 1");
    $st->execute([':c'=>$sedeClave]);$sedeId=(string)$st->fetchColumn();
    if($sedeId==='')pago_contexto_out(['ok'=>false,'error'=>'Sede activa inválida'],422);
    if($me['rol']==='ADMIN')regla_promover_planes_programados_sede($pdo,$sedeId);

    $alumnoId=trim((string)($_GET['alumno_id']??''));
    $cursoId=trim((string)($_GET['curso_id']??''));
    $mes=(int)($_GET['mes']??date('n'));$anio=(int)($_GET['anio']??date('Y'));
    if($alumnoId==='')pago_contexto_out(['ok'=>false,'error'=>'alumno_id es obligatorio'],422);
    if($mes<1||$mes>12||$anio<2000||$anio>2100)pago_contexto_out(['ok'=>false,'error'=>'Periodo inválido'],422);

    $st=$pdo->prepare("SELECT a.id,a.sede_id,s.clave sede_clave,a.ciclo_pago,a.nombre,a.plan_actual_id,a.plan_programado_id,a.plan_programado_desde,
        p.nombre plan_nombre,p.precio plan_precio,pp.nombre plan_programado_nombre,pp.precio plan_programado_precio
        FROM alumnos a INNER JOIN sedes s ON s.id=a.sede_id
        LEFT JOIN planes p ON p.id=a.plan_actual_id AND p.sede_id=a.sede_id
        LEFT JOIN planes pp ON pp.id=a.plan_programado_id AND pp.sede_id=a.sede_id
        WHERE a.id=:id AND a.sede_id=:s LIMIT 1");
    $st->execute([':id'=>$alumnoId,':s'=>$sedeId]);$alumno=$st->fetch();
    if(!$alumno)pago_contexto_out(['ok'=>false,'error'=>'Alumno no encontrado en la sede activa'],404);

    $ciclo=$alumno['ciclo_pago']!==null?strtoupper((string)$alumno['ciclo_pago']):null;
    if($sedeClave==='PALAPAS'&&!in_array($ciclo,['P1','P15'],true)&&!empty($alumno['plan_actual_id']))pago_contexto_out(['ok'=>false,'error'=>'El alumno regular de Palapas no tiene ciclo P1/P15 definido'],422);
    $referencia=new DateTimeImmutable(sprintf('%04d-%02d-%02d',$anio,$mes,($sedeClave==='PALAPAS'&&$ciclo==='P15')?15:1));
    $periodo=regla_periodo_regular_actual($sedeClave,$ciclo,$referencia);
    $periodo['etiqueta']=(new DateTimeImmutable($periodo['inicio']))->format('d/m/Y').' al '.(new DateTimeImmutable($periodo['fin']))->format('d/m/Y');
    $periodo['ciclo']=$ciclo;
    $periodoInicio=new DateTimeImmutable($periodo['inicio']);

    $st=$pdo->prepare("SELECT id,nombre,sesiones_semana,precio FROM planes WHERE activo=1 AND sede_id=:s ORDER BY sesiones_semana,nombre");
    $st->execute([':s'=>$sedeId]);$planes=$st->fetchAll();

    $st=$pdo->prepare("SELECT p.fecha,p.folio FROM pagos p INNER JOIN inscripciones i ON i.id=p.inscripcion_id
        WHERE p.alumno_id=:a AND i.sede_id=:s AND p.tipo='INSCRIPCION' AND p.estado='VALIDO' ORDER BY p.fecha DESC LIMIT 1");
    $st->execute([':a'=>$alumnoId,':s'=>$sedeId]);$ultimaInscripcion=$st->fetch()?:null;

    $st=$pdo->prepare("SELECT id,estado,fecha_pago,importe_cobrado,plan_id,periodo_inicio,periodo_fin FROM mensualidades
        WHERE alumno_id=:a AND sede_id=:s AND periodo_inicio=:pi AND periodo_fin=:pf LIMIT 1");
    $st->execute([':a'=>$alumnoId,':s'=>$sedeId,':pi'=>$periodo['inicio'],':pf'=>$periodo['fin']]);$mensualidadPeriodo=$st->fetch()?:null;

    $st=$pdo->prepare("SELECT mes,anio,estado,importe_a_cobrar,periodo_inicio,periodo_fin FROM mensualidades
        WHERE alumno_id=:a AND sede_id=:s AND estado='PENDIENTE' AND periodo_fin<:pi ORDER BY periodo_inicio DESC LIMIT 3");
    $st->execute([':a'=>$alumnoId,':s'=>$sedeId,':pi'=>$periodo['inicio']]);$pendientes=$st->fetchAll();

    $intensivoSql="SELECT ci.id,ci.fecha_inicio,ci.fecha_fin,ci.precio,ci.estado,
        EXISTS(SELECT 1 FROM pagos pg WHERE pg.alumno_id=cia.alumno_id AND pg.intensivo_id=ci.id AND pg.tipo='INTENSIVO' AND pg.estado='VALIDO') AS pagado
        FROM curso_intensivo_alumnos cia
        INNER JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id
        WHERE cia.alumno_id=:a AND ci.sede_id=:s AND ci.fecha_fin>=CURDATE()";
    $intensivoParams=[':a'=>$alumnoId,':s'=>$sedeId];
    if($cursoId!==''){$intensivoSql.=" AND ci.id=:c";$intensivoParams[':c']=$cursoId;}
    $intensivoSql.=" ORDER BY ci.fecha_inicio DESC LIMIT 1";
    $st=$pdo->prepare($intensivoSql);$st->execute($intensivoParams);$intensivo=$st->fetch()?:null;
    if($intensivo){
        $intensivo['estado']=intensivo_estado_por_fechas((string)$intensivo['fecha_inicio'],(string)$intensivo['fecha_fin']);
        $intensivo['pagado']=(int)($intensivo['pagado']??0)===1;
    }

    $inscripcionPermitida=true;$proximaInscripcion=null;
    if($ultimaInscripcion){
        $ultima=new DateTimeImmutable(substr((string)$ultimaInscripcion['fecha'],0,10));
        $permitida=$ultima->modify('first day of this month')->modify('+3 months');
        $inscripcionPermitida=new DateTimeImmutable('today')>=$permitida;
        $proximaInscripcion=$permitida->format('Y-m-d');
    }

    $planPeriodo=['id'=>$alumno['plan_actual_id'],'nombre'=>$alumno['plan_nombre'],'precio'=>$alumno['plan_precio']];
    if(!empty($alumno['plan_programado_id'])&&!empty($alumno['plan_programado_desde'])&&$periodoInicio>=new DateTimeImmutable((string)$alumno['plan_programado_desde'])){
        $planPeriodo=['id'=>$alumno['plan_programado_id'],'nombre'=>$alumno['plan_programado_nombre'],'precio'=>$alumno['plan_programado_precio']];
    }

    pago_contexto_out([
        'ok'=>true,'periodo'=>$periodo,'alumno'=>$alumno,'plan_periodo'=>$planPeriodo,'planes'=>$planes,
        'ultima_inscripcion'=>$ultimaInscripcion,'inscripcion_permitida'=>$inscripcionPermitida,'proxima_inscripcion'=>$proximaInscripcion,
        'mensualidad_periodo'=>$mensualidadPeriodo,'mensualidades_pendientes'=>$pendientes,'intensivo_activo'=>$intensivo,
    ]);
}catch(Throwable $e){
    error_log('pago-contexto: '.$e->getMessage());
    pago_contexto_out(['ok'=>false,'error'=>'No se pudo cargar el contexto de pago'],500);
}
