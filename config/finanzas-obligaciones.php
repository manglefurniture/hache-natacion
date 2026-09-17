<?php
declare(strict_types=1);

require_once __DIR__.'/periodos-financieros.php';
require_once __DIR__.'/finanzas-correcciones.php';

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

/**
 * Autoridad de lectura F2 para obligaciones y saldos de un periodo/sede.
 * El dashboard y Finanzas internas deben reconciliar contra este mismo resultado.
 *
 * @return array{rango:array,obligaciones:array,resumen:array}
 */
function finanzas_obligaciones_periodo(PDO $pdo,array $sede,string $periodo,bool $permitirCorrecciones=false):array
{
    financiero_validar_periodo($periodo);
    [$anio,$mes]=array_map('intval',explode('-',$periodo));
    $rango=financiero_rango($pdo,(string)$sede['id'],$periodo);
    $obligaciones=[];

    $sql="SELECT m.id obligacion_id,m.alumno_id,a.nombre alumno,
                 CONCAT(LPAD(m.mes,2,'0'),'/',m.anio) referencia,
                 CONCAT(m.periodo_inicio,' → ',m.periodo_fin) periodo_obligacion,
                 m.periodo_inicio fecha_referencia,
                 m.importe_a_cobrar total_obligacion,m.importe_cobrado,m.estado obligacion_estado,m.observacion,
                 (SELECT COUNT(*) FROM pagos px WHERE px.mensualidad_id=m.id) pagos_totales,
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
        $o['correccion_disponible']=$permitirCorrecciones&&finanzas_mensualidad_corregible($r);
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

    return ['rango'=>$rango,'obligaciones'=>$obligaciones,'resumen'=>$resumen];
}
