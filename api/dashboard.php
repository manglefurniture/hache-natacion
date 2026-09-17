<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/auth.php';
require_once __DIR__.'/../config/periodos-financieros.php';
require_once __DIR__.'/../config/finanzas-obligaciones.php';
require_once __DIR__.'/../config/dashboard-tiempo.php';
require_once __DIR__.'/../config/dashboard-operacion.php';
require_once __DIR__.'/../config/dashboard-alumnos.php';
require_once __DIR__.'/../config/dashboard-intensivos.php';
require_once __DIR__.'/../config/centro-pendientes-compuesto.php';

$me=auth_require(['ADMIN','VERIFICADOR']);
$config=require __DIR__.'/../config/database.php';
$pdo=new PDO(
    "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",
    $config['user'],
    $config['password'],
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false],
);

function out(array $d,int $c=200):never
{
    http_response_code($c);
    echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $clave=auth_resolve_sede_clave((string)($_GET['sede']??'MONTEVERDE'));
    $st=$pdo->prepare("SELECT id,nombre FROM sedes WHERE clave=:c AND activo=1 LIMIT 1");
    $st->execute([':c'=>$clave]);
    $s=$st->fetch();
    if(!$s)out(['ok'=>false,'error'=>'Sede inválida'],422);
    $sid=(string)$s['id'];
    $sede=['id'=>$sid,'clave'=>$clave,'nombre'=>(string)$s['nombre']];

    $tiempo=dashboard_contexto_temporal(
        $sid,
        static fn(string $sedeId,string $fecha):string=>financiero_periodo_para_fecha($pdo,$sedeId,$fecha),
    );
    $hoy=$tiempo['fecha'];
    $periodoVigente=$tiempo['periodo_vigente'];

    // F2 conserva la autoridad de ingresos, obligaciones y saldos por periodo financiero.
    $facturacion=financiero_totales($pdo,$sede,$periodoVigente);
    $rangoPeriodo=$facturacion['rango']??financiero_rango($pdo,$sid,$periodoVigente);
    $lecturaObligaciones=finanzas_obligaciones_periodo($pdo,$sede,$periodoVigente,false);
    $saldosPeriodo=$lecturaObligaciones['resumen'];

    // Definición histórica del dashboard, ahora con el mismo detalle reconciliable.
    // No equivale a estado administrativo ni a derecho de acceso.
    $alumnosActivos=dashboard_alumnos_activos($pdo,$sid,$hoy);
    $alumnos=(int)$alumnosActivos['total'];

    $st=$pdo->prepare("SELECT COUNT(*) FROM alumnos WHERE sede_id=:s AND estado_administrativo='PENDIENTE'");
    $st->execute([':s'=>$sid]);
    $alumnosPendientes=(int)$st->fetchColumn();

    $st=$pdo->prepare("SELECT COUNT(*) c,COALESCE(SUM(importe_cobrado),0) total FROM mensualidades WHERE sede_id=:s AND :hoy BETWEEN periodo_inicio AND periodo_fin AND estado='PAGADA'");
    $st->execute([':s'=>$sid,':hoy'=>$hoy]);
    $mens=$st->fetch();

    // Conserva la semántica histórica del indicador y comparte total + detalle.
    $intensivosActivos=dashboard_intensivos_activos($pdo,$sid);
    $intensivos=(int)$intensivosActivos['total'];

    $st=$pdo->prepare("SELECT COUNT(*) FROM avisos_ausencia aa JOIN alumnos a ON a.id=aa.alumno_id WHERE a.sede_id=:s AND aa.estado='ACTIVO' AND :hoy BETWEEN aa.fecha_desde AND aa.fecha_hasta");
    $st->execute([':s'=>$sid,':hoy'=>$hoy]);
    $avisos=(int)$st->fetchColumn();

    // Lectura pura: nunca llama api/sesiones.php porque ese GET puede crear sesiones para ADMIN.
    $operacionHoy=dashboard_operacion_fecha($pdo,$sid,$hoy);

    // F1 gestiona estados y F5 detecta las causas. Esta lectura usa las mismas fuentes puras,
    // sin consultar endpoints con posibles efectos secundarios ni recalcular reglas en paralelo.
    $includeGlobalProspects=(string)($me['rol']??'')==='ADMIN';
    $pendientesResumen=centro_pendientes_compuesto_resumen_activo($pdo,$sede,$includeGlobalProspects);
    $repos=(int)($pendientesResumen['por_tipo']['REPOSICION_REGULAR_DISPONIBLE']['total']??0);
    $mensualidadSinCobertura=(int)($pendientesResumen['por_tipo']['MENSUALIDAD_REGULAR_SIN_COBERTURA']['total']??0);

    $st=$pdo->prepare("SELECT h.hora_inicio,h.hora_fin,COUNT(DISTINCT a.id) alumnos
        FROM horarios h
        LEFT JOIN alumnos a ON a.horario_preferido_id=h.id AND a.sede_id=:sa AND a.estado_administrativo='ACTIVO'
        WHERE h.sede_id=:sh AND h.activo=1
        GROUP BY h.id,h.hora_inicio,h.hora_fin
        HAVING alumnos>0
        ORDER BY h.hora_inicio");
    $st->execute([':sa'=>$sid,':sh'=>$sid]);
    $horarios=$st->fetchAll();

    out([
        'ok'=>true,
        'sede'=>['clave'=>$clave,'nombre'=>$s['nombre']],
        'fecha'=>$hoy,
        'periodo_vigente'=>$periodoVigente,
        'rango_periodo'=>['inicio'=>$rangoPeriodo['inicio'],'cierre'=>$rangoPeriodo['cierre']],
        'facturacion'=>[
            'cantidad'=>(int)($facturacion['pagos_count']??0),
            'total'=>(float)($facturacion['total']??0),
        ],
        'saldos_periodo'=>[
            'obligaciones'=>(int)$saldosPeriodo['obligaciones'],
            'total'=>(float)$saldosPeriodo['total'],
            'pagado'=>(float)$saldosPeriodo['pagado'],
            'saldo'=>(float)$saldosPeriodo['saldo'],
            'con_saldo'=>(int)$saldosPeriodo['con_saldo'],
        ],
        'alumnos_activos'=>$alumnos,
        'alumnos_activos_detalle'=>$alumnosActivos,
        'alumnos_pendientes'=>$alumnosPendientes,
        'mensualidades'=>[
            'cantidad'=>(int)$mens['c'],
            'total'=>(float)$mens['total'],
        ],
        'intensivos'=>$intensivos,
        'intensivos_detalle'=>$intensivosActivos,
        'avisos_hoy'=>$avisos,
        'operacion_hoy'=>$operacionHoy,
        'reposiciones'=>$repos,
        'centro_pendientes'=>$pendientesResumen,
        'alertas_f5'=>[
            'total'=>(int)$pendientesResumen['total'],
            'sede'=>(int)$pendientesResumen['sede'],
            'globales'=>(int)$pendientesResumen['globales'],
            'por_tipo'=>$pendientesResumen['por_tipo'],
            'mensualidad_sin_cobertura'=>$mensualidadSinCobertura,
        ],
        'horarios'=>$horarios,
        'contratos'=>[
            'alumnos_activos'=>'Mensualidad PAGADA vigente o intensivo vigente con algún pago VALIDO, excluyendo BAJA; no equivale a derecho de acceso.',
            'facturacion'=>'F2: ingresos atribuidos al periodo financiero vigente según la regla de cada concepto.',
            'saldos_periodo'=>'F2: suma de obligaciones registradas del periodo menos pagos VALIDOS; el detalle reconciliable vive en Finanzas internas.',
            'operacion_hoy'=>'Sesiones y marcas ya registradas para la fecha operativa y atribuibles a la sede por horario. Cero sesiones registradas no significa cero clases planificadas; F6 no genera sesiones ni calcula porcentaje de asistencia sin denominador estable.',
            'intensivos'=>'Cursos de la sede con estado registrado PROGRAMADO o EN_CURSO; F6 no reconcilia ni modifica estados al consultar.',
            'alertas_f5'=>'F5: causas activas compartidas con F1; los casos globales de prospectos solo se incluyen para ADMIN.',
            'centro_pendientes'=>'F1: causas activas separadas entre PENDIENTE y ATENDIDO; históricos resueltos no inflan el total operativo.',
        ],
    ]);
} catch(Throwable $e) {
    error_log('[dashboard] '.$e->getMessage());
    out(['ok'=>false,'error'=>'No se pudo cargar el dashboard'],500);
}
