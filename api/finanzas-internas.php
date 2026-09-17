<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/auth.php';
require_once __DIR__.'/../config/periodos-financieros.php';
require_once __DIR__.'/../config/finanzas-obligaciones.php';
$viewer=auth_require(['ADMIN','VERIFICADOR']);
$config=require __DIR__.'/../config/database.php';

function finanzas_out(array $data,int $status=200):never
{
    http_response_code($status);
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
    exit;
}

function finanzas_sede(PDO $pdo,string $clave):array
{
    $st=$pdo->prepare('SELECT id,clave,nombre,socio,porcentaje_mensualidad_socio,porcentaje_intensivo_socio,porcentaje_inscripcion_socio,minimo_mensual_socio FROM sedes WHERE clave=:c AND activo=1 LIMIT 1');
    $st->execute([':c'=>$clave]);
    $s=$st->fetch(PDO::FETCH_ASSOC);
    if(!$s)finanzas_out(['ok'=>false,'error'=>'Sede inválida'],422);
    return $s;
}

function finanzas_reparto(array $sede,array $totales):array
{
    $mens=(float)$totales['mensualidades_total'];
    $int=(float)$totales['intensivos_total'];
    $ins=(float)$totales['inscripciones_total'];
    $pm=(float)$sede['porcentaje_mensualidad_socio']/100;
    $pi=(float)$sede['porcentaje_intensivo_socio']/100;
    $pn=(float)$sede['porcentaje_inscripcion_socio']/100;
    $socio=$mens*$pm+$int*$pi+$ins*$pn;
    return [
        'socio_nombre'=>$sede['socio'],
        'total'=>(float)$totales['total'],
        'socio'=>$socio,
        'hache'=>(float)$totales['total']-$socio,
        'reglas'=>[
            'mensualidad_socio'=>(float)$sede['porcentaje_mensualidad_socio'],
            'intensivo_socio'=>(float)$sede['porcentaje_intensivo_socio'],
            'inscripcion_socio'=>(float)$sede['porcentaje_inscripcion_socio'],
            'minimo_mensual_socio'=>$sede['minimo_mensual_socio']!==null?(float)$sede['minimo_mensual_socio']:null,
        ],
    ];
}

try{
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')finanzas_out(['ok'=>false,'error'=>'Método no permitido'],405);
    $pdo=new PDO(
        "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",
        $config['user'],$config['password'],
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]
    );

    $clave=auth_resolve_sede_clave((string)($_GET['sede']??''));
    $sede=finanzas_sede($pdo,$clave);
    $periodo=financiero_validar_periodo((string)($_GET['periodo']??date('Y-m')));
    $totales=financiero_totales($pdo,$sede,$periodo);
    $rango=$totales['rango'];
    $reparto=finanzas_reparto($sede,$totales);

    $st=$pdo->prepare('SELECT c.*,u.usuario cerrado_por_usuario FROM cierres_mensuales c JOIN usuarios u ON u.id=c.cerrado_por WHERE c.sede_id=:s AND c.periodo=:p LIMIT 1');
    $st->execute([':s'=>$sede['id'],':p'=>$periodo.'-01']);
    $cierre=$st->fetch(PDO::FETCH_ASSOC)?:null;
    $comparacion=null;
    if($cierre){
        $comparacion=[
            'total_actual'=>(float)$totales['total'],
            'total_cierre'=>(float)$cierre['total_cobrado'],
            'diferencia_total'=>round((float)$totales['total']-(float)$cierre['total_cobrado'],2),
            'hache_actual'=>$reparto['hache'],
            'hache_cierre'=>(float)$cierre['participacion_hache'],
            'diferencia_hache'=>round($reparto['hache']-(float)$cierre['participacion_hache'],2),
            'socio_actual'=>$reparto['socio'],
            'socio_cierre'=>(float)$cierre['participacion_proa'],
            'diferencia_socio'=>round($reparto['socio']-(float)$cierre['participacion_proa'],2),
        ];
    }

    $lecturaObligaciones=finanzas_obligaciones_periodo(
        $pdo,
        $sede,
        $periodo,
        ($viewer['rol']??'')==='ADMIN',
    );

    finanzas_out([
        'ok'=>true,
        'sede'=>['clave'=>$sede['clave'],'nombre'=>$sede['nombre'],'socio'=>$sede['socio']],
        'periodo'=>['clave'=>$periodo,'rango'=>$rango],
        'ingresos'=>[
            'pagos'=>(int)$totales['pagos_count'],
            'total'=>(float)$totales['total'],
            'mensualidades'=>(float)$totales['mensualidades_total'],
            'inscripciones'=>(float)$totales['inscripciones_total'],
            'intensivos'=>(float)$totales['intensivos_total'],
        ],
        'reparto'=>$reparto,
        'cierre'=>$cierre,
        'comparacion_cierre'=>$comparacion,
        'obligaciones_resumen'=>$lecturaObligaciones['resumen'],
        'obligaciones'=>$lecturaObligaciones['obligaciones'],
        'contrato'=>[
            'saldo'=>'Solo se cuantifica desde una obligación registrada y pagos VALIDOS.',
            'periodos'=>'Periodo de obligación, periodo financiero y fecha de cobro se muestran por separado.',
            'cierre'=>'El cierre guardado es histórico e inmutable; la diferencia muestra el cálculo actual sin sobrescribirlo.',
        ],
    ]);
}catch(InvalidArgumentException $e){
    finanzas_out(['ok'=>false,'error'=>$e->getMessage()],422);
}catch(Throwable $e){
    error_log('[finanzas-internas] '.$e->getMessage());
    finanzas_out(['ok'=>false,'error'=>'No se pudo cargar la lectura financiera interna'],500);
}
