<?php
declare(strict_types=1);

/**
 * Contrato histórico de "Mensualidades pagadas" del dashboard F6.
 *
 * Cuenta registros de mensualidad PAGADA de la sede cuya vigencia contiene
 * la fecha operativa. El total monetario suma importe_cobrado de esas mismas filas.
 * La consulta es de solo lectura y el detalle compone exactamente los agregados.
 */
function dashboard_mensualidades_pagadas(PDO $pdo,string $sedeId,string $fecha):array
{
    $st=$pdo->prepare("SELECT m.id,m.alumno_id,a.nombre,m.periodo_inicio,m.periodo_fin,m.importe_cobrado,m.fecha_pago
        FROM mensualidades m
        INNER JOIN alumnos a ON a.id=m.alumno_id AND a.sede_id=m.sede_id
        WHERE m.sede_id=:s
          AND m.estado='PAGADA'
          AND :f BETWEEN m.periodo_inicio AND m.periodo_fin
        ORDER BY a.nombre,m.id");
    $st->execute([':s'=>$sedeId,':f'=>$fecha]);

    $rows=[];
    $total=0.0;
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){
        $importe=(float)($row['importe_cobrado']??0);
        $total+=$importe;
        $rows[]=[
            'id'=>(string)$row['id'],
            'alumno_id'=>(string)$row['alumno_id'],
            'nombre'=>(string)$row['nombre'],
            'periodo_inicio'=>(string)$row['periodo_inicio'],
            'periodo_fin'=>(string)$row['periodo_fin'],
            'importe_cobrado'=>$importe,
            'fecha_pago'=>$row['fecha_pago']!==null?(string)$row['fecha_pago']:null,
        ];
    }

    return [
        'cantidad'=>count($rows),
        'total'=>$total,
        'rows'=>$rows,
        'fecha'=>$fecha,
        'unidad'=>'mensualidades_pagadas_vigentes',
        'fuente'=>'mensualidades.estado + periodo_inicio/periodo_fin',
    ];
}

/**
 * Contrato histórico de "Avisos hoy" del dashboard F6.
 *
 * Cuenta avisos de ausencia ACTIVOS de alumnos de la sede cuando la fecha
 * operativa cae dentro de su rango. Un alumno con dos avisos superpuestos cuenta
 * dos avisos, igual que el agregado histórico.
 */
function dashboard_avisos_ausencia_activos(PDO $pdo,string $sedeId,string $fecha):array
{
    $st=$pdo->prepare("SELECT aa.id,aa.alumno_id,a.nombre,aa.fecha_desde,aa.fecha_hasta
        FROM avisos_ausencia aa
        INNER JOIN alumnos a ON a.id=aa.alumno_id
        WHERE a.sede_id=:s
          AND aa.estado='ACTIVO'
          AND :f BETWEEN aa.fecha_desde AND aa.fecha_hasta
        ORDER BY a.nombre,aa.fecha_desde,aa.id");
    $st->execute([':s'=>$sedeId,':f'=>$fecha]);

    $rows=[];
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){
        $rows[]=[
            'id'=>(string)$row['id'],
            'alumno_id'=>(string)$row['alumno_id'],
            'nombre'=>(string)$row['nombre'],
            'fecha_desde'=>(string)$row['fecha_desde'],
            'fecha_hasta'=>(string)$row['fecha_hasta'],
        ];
    }

    return [
        'total'=>count($rows),
        'rows'=>$rows,
        'fecha'=>$fecha,
        'unidad'=>'avisos_activos',
        'fuente'=>'avisos_ausencia.estado + fecha_desde/fecha_hasta',
    ];
}
