<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

require_once __DIR__.'/../config/auth.php';
require_once __DIR__.'/../config/resumen-diario.php';

$me=auth_require(['ADMIN','VERIFICADOR']);

function f9_out(array $data,int $status=200): never
{
    http_response_code($status);
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

function f9_fecha(string $value): string
{
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('America/Cancun'));
    if(!$date||$date->format('Y-m-d')!==$value){
        f9_out(['ok'=>false,'error'=>'Fecha inválida'],422);
    }
    return $value;
}

if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){
    header('Allow: GET');
    f9_out(['ok'=>false,'error'=>'Método no permitido'],405);
}

$config=require __DIR__.'/../config/database.php';

try{
    $pdo=new PDO(
        "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",
        $config['user'],
        $config['password'],
        [
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,
        ],
    );

    $instante=hache_instante_operativo();
    $hoy=$instante->format('Y-m-d');
    $fechaRaw=trim((string)($_GET['fecha']??''));
    $fecha=f9_fecha($fechaRaw!==''?$fechaRaw:$hoy);
    $estadoFecha=hache_resumen_diario_estado_fecha($fecha,$hoy);

    $clave=auth_resolve_sede_clave((string)($_GET['sede']??''));
    $st=$pdo->prepare("SELECT id,clave,nombre FROM sedes WHERE clave=:c AND activo=1 LIMIT 1");
    $st->execute([':c'=>$clave]);
    $site=$st->fetch();
    if(!$site)f9_out(['ok'=>false,'error'=>'Sede inválida'],422);

    $sede=[
        'id'=>(string)$site['id'],
        'clave'=>(string)$site['clave'],
        'nombre'=>(string)$site['nombre'],
    ];
    $sedeId=$sede['id'];
    $includeGlobalProspects=(string)($me['rol']??'')==='ADMIN';

    $pendientesActuales=hache_resumen_diario_pendientes_actuales(
        $pdo,
        $sede,
        $includeGlobalProspects,
        $instante,
    );
    $incidencias=hache_resumen_diario_incidencias($pdo,$sedeId,$fecha);

    $alumnosActivos=dashboard_alumnos_activos($pdo,$sedeId,$fecha);
    $clasesPrevistas=hache_resumen_diario_clases_previstas($pdo,$sedeId,$fecha,$hoy);

    $apertura=[
        'alumnos_activos'=>array_merge($alumnosActivos,[
            'disponible'=>true,
            'href'=>'/dashboard.php?sede='.rawurlencode($clave),
            'contrato'=>'Definición F6; no equivale a estado administrativo ni derecho de acceso.',
        ]),
        'clases_previstas'=>$clasesPrevistas,
        'pendientes_actuales'=>$pendientesActuales,
        'incidencias'=>$incidencias,
    ];

    if($estadoFecha==='FUTURO'){
        $cierre=[
            'disponible'=>false,
            'motivo'=>'La fecha seleccionada es futura; todavía no existen hechos de cierre operativo para ese día.',
            'sesiones'=>null,
            'asistencia'=>null,
            'cobros'=>null,
            'altas'=>null,
            'pendientes_actuales'=>$pendientesActuales,
            'pendientes_nuevos'=>[
                'disponible'=>false,
                'total'=>null,
                'motivo'=>'F9 no dispone de una fecha durable común de primera detección para todas las causas de F1/F5.',
            ],
            'incidencias'=>$incidencias,
            'correcciones'=>null,
        ];
    }else{
        $operacion=dashboard_operacion_fecha($pdo,$sedeId,$fecha);
        $asistencia=dashboard_asistencia_periodo($pdo,$sedeId,$fecha,$fecha);
        $cobros=hache_resumen_diario_cobros($pdo,$sedeId,$fecha);
        $altas=dashboard_nuevos_alumnos($pdo,$sedeId,$fecha,$fecha);
        $correcciones=hache_resumen_diario_correcciones($pdo,$sedeId,$fecha);

        $cierre=[
            'disponible'=>true,
            'sesiones'=>array_merge($operacion,[
                'href'=>'/sesiones.php?fecha='.rawurlencode($fecha).'&sede='.rawurlencode($clave),
            ]),
            'asistencia'=>$asistencia,
            'cobros'=>$cobros,
            'altas'=>array_merge($altas,[
                'href'=>'/alumnos.php?sede='.rawurlencode($clave),
            ]),
            'pendientes_actuales'=>$pendientesActuales,
            'pendientes_nuevos'=>[
                'disponible'=>false,
                'total'=>null,
                'motivo'=>'F9 no dispone de una fecha durable común de primera detección para todas las causas de F1/F5; no se reclasifican causas antiguas como nuevas.',
            ],
            'incidencias'=>$incidencias,
            'correcciones'=>$correcciones,
        ];
    }

    f9_out([
        'ok'=>true,
        'fecha'=>$fecha,
        'hoy'=>$hoy,
        'estado_fecha'=>$estadoFecha,
        'actualizado_en'=>$instante->format(DateTimeInterface::ATOM),
        'sede'=>[
            'clave'=>$sede['clave'],
            'nombre'=>$sede['nombre'],
        ],
        'snapshot'=>false,
        'tipo_lectura'=>'VIVA_RECONCILIABLE',
        'apertura'=>$apertura,
        'cierre'=>$cierre,
        'contratos'=>[
            'corte'=>'actualizado_en es el instante operativo de esta consulta en America/Cancun; no se persiste una instantánea.',
            'historia'=>'Una lectura posterior del mismo día puede cambiar si existen correcciones durables. F9 no reconstruye cómo se veía el sistema a una hora pasada.',
            'mutaciones'=>'Este endpoint es de solo lectura: no crea sesiones, no reconcilia pagos y no cierra sesiones, periodos ni pendientes.',
            'pendientes_nuevos'=>'No disponible hasta existir una autoridad común de primera detección durable.',
            'cobros'=>'Se agrupan por fecha efectiva del pago; solo VALIDO suma al total y los invalidados permanecen visibles.',
        ],
        'fuentes'=>[
            'dashboard'=>'F6: alumnos, operación, asistencia y altas',
            'pendientes'=>'F1/F5: causas activas y gestión',
            'finanzas'=>'pagos: fecha efectiva + estado',
            'profesores'=>'F7: incidencias y sustituciones con cobertura forward-only',
            'auditoria'=>'F8: correcciones durables cuando la fuente permite relacionarlas con el día consultado',
        ],
    ]);
}catch(Throwable $e){
    error_log('[resumen-diario] '.$e->getMessage());
    f9_out(['ok'=>false,'error'=>'No se pudo cargar el resumen operativo diario'],500);
}
