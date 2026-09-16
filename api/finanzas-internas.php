<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/auth.php';
require_once __DIR__.'/../config/periodos-financieros.php';
require_once __DIR__.'/../config/finanzas-correcciones.php';
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

function finanzas_normalizar_obligacion(array $r,string $tipo):array
{
    $total=round((float)$r['total_obligacion'],2);
    $pagado=round((float)$r['pagado_valido'],2);
    $saldo=max(0.0,round($total-$pagado,2));
    return [
        'tipo'=>$tipo,
        'obligacion_id'=>(string)$r['obligacion_id'],
        'alumno_id'=>(string)$r['alumno_id'],
        'alumno'=>(string)$r['alumno'],
        'referencia'=>(string)$r['referencia'],
        'periodo_obligacion'=>(string)$r['periodo_obligacion'],
        'fecha_referencia'=>(string)$r['fecha_referencia'],
        'total'=>$total,
        'pagado'=>$pagado,
        'saldo'=>$saldo,
        'estado_pago'=>$saldo<=0.009?'PAGADO':($pagado>0.009?'ANTICIPO':'PENDIENTE'),
        'pagos_validos'=>(int)$r['pagos_validos'],
        'pagos_invalidados'=>(int)$r['pagos_invalidados'],
        'ultima_fecha_pago'=>$r['ultima_fecha_pago']!==null?(string)$r['ultima_fecha_pago']:null,
        'metodos'=>$r['metodos']!==null&&$r['metodos']!==''?explode(',',(string)$r['metodos']):[],
        'correccion_disponible'=>false,
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
    [$anio,$mes]=array_map('intval',explode('-',$periodo));
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

    $obligaciones=[];

    $sql="SELECT m.id obligacion_id,m.alumno_id,a.nombre alumno,
                 CONCAT(LPAD(m.mes,2,'0'),'/',m.anio) referencia,
                 CONCAT(m.periodo_inicio,' → ',m.periodo_fin) periodo_obligacion,
                 m.periodo_inicio fecha_referencia,
                 m.importe_a_cobrar total_obligacion,m.importe_cobrado,m.estado obligacion_estado,m.observacion,
                 COUNT(p.id) pagos_totales,
                 EXISTS(SELECT 1 FROM curso_intensivo_alumnos cia INNER JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id WHERE cia.alumno_id=m.alumno_id AND ci.sede_id=m.sede_id AND ci.fecha_inicio<=m.periodo_fin AND ci.fecha_fin>=m.periodo_inicio) intensivo_solapado,
                 COALESCE(SUM(CASE WHEN p.estado='VALIDO' THEN p.importe ELSE 0 END),0) pagado_valido,
                 COALESCE(SUM(p.estado='VALIDO'),0) pagos_validos,
                 COALESCE(SUM(p.estado='INVALIDADO'),0) pagos_invalidados,
                 MAX(CASE WHEN p.estado='VALIDO' THEN p.fecha END) ultima_fecha_pago,
                 GROUP_CONCAT(DISTINCT CASE WHEN p.estado='VALIDO' THEN p.metodo END ORDER BY p.metodo SEPARATOR ',') metodos
          FROM mensualidades m
          INNER JOIN alumnos a ON a.id=m.alumno_id
          LEFT JOIN pagos p ON p.mensualidad_id=m.id AND p.tipo='MENSUALIDAD'
          WHERE m.sede_id=:s AND m.mes=:mes AND m.anio=:anio
          GROUP BY m.id,m.alumno_id,a.nombre,m.mes,m.anio,m.periodo_inicio,m.periodo_fin,m.importe_a_cobrar,m.importe_cobrado,m.estado,m.observacion";
    $st=$pdo->prepare($sql);$st->execute([':s'=>$sede['id'],':mes'=>$mes,':anio'=>$anio]);
    foreach($st->fetchAll() as $r){
        $o=finanzas_normalizar_obligacion($r,'MENSUALIDAD');
        $o['correccion_disponible']=($viewer['rol']??'')==='ADMIN'&&finanzas_mensualidad_corregible($r);
        $obligaciones[]=$o;
    }

    $sql="SELECT i.id obligacion_id,i.alumno_id,a.nombre alumno,
                 DATE_FORMAT(i.fecha,'%d/%m/%Y') referencia,
                 DATE_FORMAT(i.fecha,'%Y-%m-%d') periodo_obligacion,
                 i.fecha fecha_referencia,
                 i.importe total_obligacion,
                 COALESCE(SUM(CASE WHEN p.estado='VALIDO' THEN p.importe ELSE 0 END),0) pagado_valido,
                 COALESCE(SUM(p.estado='VALIDO'),0) pagos_validos,
                 COALESCE(SUM(p.estado='INVALIDADO'),0) pagos_invalidados,
                 MAX(CASE WHEN p.estado='VALIDO' THEN p.fecha END) ultima_fecha_pago,
                 GROUP_CONCAT(DISTINCT CASE WHEN p.estado='VALIDO' THEN p.metodo END ORDER BY p.metodo SEPARATOR ',') metodos
          FROM inscripciones i
          INNER JOIN alumnos a ON a.id=i.alumno_id
          LEFT JOIN pagos p ON p.inscripcion_id=i.id AND p.tipo='INSCRIPCION'
          WHERE i.sede_id=:s AND i.fecha BETWEEN :d AND :h
          GROUP BY i.id,i.alumno_id,a.nombre,i.fecha,i.importe";
    $st=$pdo->prepare($sql);$st->execute([':s'=>$sede['id'],':d'=>$rango['inicio'],':h'=>$rango['cierre']]);
    foreach($st->fetchAll() as $r)$obligaciones[]=finanzas_normalizar_obligacion($r,'INSCRIPCION');

    $sql="SELECT ci.id obligacion_id,cia.alumno_id,a.nombre alumno,
                 CONCAT(DATE_FORMAT(ci.fecha_inicio,'%d/%m/%Y'),' → ',DATE_FORMAT(ci.fecha_fin,'%d/%m/%Y')) referencia,
                 CONCAT(ci.fecha_inicio,' → ',ci.fecha_fin) periodo_obligacion,
                 ci.fecha_inicio fecha_referencia,
                 ci.precio total_obligacion,
                 COALESCE(SUM(CASE WHEN p.estado='VALIDO' THEN p.importe ELSE 0 END),0) pagado_valido,
                 COALESCE(SUM(p.estado='VALIDO'),0) pagos_validos,
                 COALESCE(SUM(p.estado='INVALIDADO'),0) pagos_invalidados,
                 MAX(CASE WHEN p.estado='VALIDO' THEN p.fecha END) ultima_fecha_pago,
                 GROUP_CONCAT(DISTINCT CASE WHEN p.estado='VALIDO' THEN p.metodo END ORDER BY p.metodo SEPARATOR ',') metodos
          FROM curso_intensivo_alumnos cia
          INNER JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id
          INNER JOIN alumnos a ON a.id=cia.alumno_id
          LEFT JOIN pagos p ON p.intensivo_id=ci.id AND p.alumno_id=cia.alumno_id AND p.tipo='INTENSIVO'
          WHERE ci.sede_id=:s AND ci.fecha_inicio BETWEEN :d AND :h
          GROUP BY ci.id,cia.alumno_id,a.nombre,ci.fecha_inicio,ci.fecha_fin,ci.precio";
    $st=$pdo->prepare($sql);$st->execute([':s'=>$sede['id'],':d'=>$rango['inicio'],':h'=>$rango['cierre']]);
    foreach($st->fetchAll() as $r)$obligaciones[]=finanzas_normalizar_obligacion($r,'INTENSIVO');

    usort($obligaciones,static function(array $a,array $b):int{
        $saldoCmp=$b['saldo']<=>$a['saldo'];
        if($saldoCmp!==0)return $saldoCmp;
        return strcmp($b['fecha_referencia'],$a['fecha_referencia']);
    });

    $resumen=['obligaciones'=>count($obligaciones),'total'=>0.0,'pagado'=>0.0,'saldo'=>0.0,'con_saldo'=>0];
    foreach($obligaciones as $o){
        $resumen['total']+=$o['total'];
        $resumen['pagado']+=$o['pagado'];
        $resumen['saldo']+=$o['saldo'];
        if($o['saldo']>0.009)$resumen['con_saldo']++;
    }
    foreach(['total','pagado','saldo'] as $k)$resumen[$k]=round((float)$resumen[$k],2);

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
        'obligaciones_resumen'=>$resumen,
        'obligaciones'=>$obligaciones,
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
