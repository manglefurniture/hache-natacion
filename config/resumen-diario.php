<?php

declare(strict_types=1);

require_once __DIR__.'/dashboard-tiempo.php';
require_once __DIR__.'/dashboard-alumnos.php';
require_once __DIR__.'/dashboard-operacion.php';
require_once __DIR__.'/dashboard-p06.php';
require_once __DIR__.'/centro-pendientes-compuesto.php';

function hache_resumen_diario_estado_fecha(string $fecha,string $hoy): string
{
    if($fecha>$hoy)return 'FUTURO';
    if($fecha===$hoy)return 'HOY';
    return 'PASADO';
}

function hache_resumen_diario_clases_previstas(PDO $pdo,string $sedeId,string $fecha,string $hoy): array
{
    $estadoFecha=hache_resumen_diario_estado_fecha($fecha,$hoy);
    if($estadoFecha==='PASADO'){
        return [
            'disponible'=>false,
            'total'=>null,
            'rows'=>[],
            'fuente'=>'horarios + asignaciones actuales',
            'motivo'=>'No existe snapshot durable de la planificación histórica; no se reconstruyen clases previstas de un día pasado desde asignaciones actuales.',
        ];
    }

    $dia=DateTimeImmutable::createFromFormat('!Y-m-d',$fecha,new DateTimeZone('America/Cancun'));
    if(!$dia)throw new InvalidArgumentException('Fecha operativa inválida');
    if((int)$dia->format('N')>=6){
        return [
            'disponible'=>true,
            'total'=>0,
            'rows'=>[],
            'fuente'=>'horarios + alumnos + cursos_intensivos',
            'alcance'=>'La operación programada solo genera sesiones de lunes a viernes.',
        ];
    }

    $sql="SELECT h.id,h.hora_inicio,h.hora_fin,
        EXISTS(
            SELECT 1 FROM alumnos a
            WHERE a.sede_id=:sr1
              AND a.horario_preferido_id=h.id
              AND a.estado_administrativo IN ('ACTIVO','PENDIENTE')
        ) tiene_regulares,
        EXISTS(
            SELECT 1
            FROM curso_intensivo_alumnos cia
            INNER JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id
            WHERE cia.horario_id=h.id
              AND ci.sede_id=:si1
              AND ci.estado IN ('PROGRAMADO','EN_CURSO')
              AND :fi1 BETWEEN ci.fecha_inicio AND ci.fecha_fin
        ) tiene_intensivo
        FROM horarios h
        WHERE h.sede_id=:sh
          AND h.activo=1
          AND (
            EXISTS(
                SELECT 1 FROM alumnos a2
                WHERE a2.sede_id=:sr2
                  AND a2.horario_preferido_id=h.id
                  AND a2.estado_administrativo IN ('ACTIVO','PENDIENTE')
            )
            OR EXISTS(
                SELECT 1
                FROM curso_intensivo_alumnos cia2
                INNER JOIN cursos_intensivos ci2 ON ci2.id=cia2.curso_intensivo_id
                WHERE cia2.horario_id=h.id
                  AND ci2.sede_id=:si2
                  AND ci2.estado IN ('PROGRAMADO','EN_CURSO')
                  AND :fi2 BETWEEN ci2.fecha_inicio AND ci2.fecha_fin
            )
          )
        ORDER BY h.hora_inicio,h.id";
    $st=$pdo->prepare($sql);
    $st->execute([
        ':sr1'=>$sedeId,':si1'=>$sedeId,':fi1'=>$fecha,
        ':sh'=>$sedeId,
        ':sr2'=>$sedeId,':si2'=>$sedeId,':fi2'=>$fecha,
    ]);

    $rows=[];
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){
        $fuentes=[];
        if((int)$row['tiene_regulares']===1)$fuentes[]='REGULAR';
        if((int)$row['tiene_intensivo']===1)$fuentes[]='INTENSIVO';
        $rows[]=[
            'horario_id'=>(string)$row['id'],
            'hora_inicio'=>(string)$row['hora_inicio'],
            'hora_fin'=>(string)$row['hora_fin'],
            'fuentes'=>$fuentes,
        ];
    }

    return [
        'disponible'=>true,
        'total'=>count($rows),
        'rows'=>$rows,
        'fuente'=>'horarios + alumnos + cursos_intensivos',
        'alcance'=>'Lectura pura de horarios que cumplen la misma condición de planificación usada para generar sesiones; esta función no crea sesiones.',
    ];
}

function hache_resumen_diario_cobros(PDO $pdo,string $sedeId,string $fecha): array
{
    $inicio=$fecha.' 00:00:00';
    $dia=DateTimeImmutable::createFromFormat('!Y-m-d',$fecha,new DateTimeZone('America/Cancun'));
    if(!$dia)throw new InvalidArgumentException('Fecha operativa inválida');
    $fin=$dia->modify('+1 day')->format('Y-m-d').' 00:00:00';

    $st=$pdo->prepare("SELECT p.id,p.folio,p.alumno_id,a.nombre alumno_nombre,p.tipo,p.importe,p.metodo,p.fecha,p.estado
        FROM pagos p
        INNER JOIN alumnos a ON a.id=p.alumno_id
        WHERE a.sede_id=:s
          AND p.fecha>=:d
          AND p.fecha<:h
        ORDER BY p.fecha,p.folio");
    $st->execute([':s'=>$sedeId,':d'=>$inicio,':h'=>$fin]);

    $validos=0;$invalidos=0;$totalValido=0.0;$totalInvalidado=0.0;$rows=[];
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){
        $estado=(string)$row['estado'];
        $importe=(float)$row['importe'];
        if($estado==='VALIDO'){
            $validos++;
            $totalValido+=$importe;
        }else{
            $invalidos++;
            $totalInvalidado+=$importe;
        }
        $rows[]=[
            'pago_id'=>(string)$row['id'],
            'folio'=>(int)$row['folio'],
            'alumno_id'=>(string)$row['alumno_id'],
            'alumno'=>(string)$row['alumno_nombre'],
            'tipo'=>(string)$row['tipo'],
            'importe'=>$importe,
            'metodo'=>(string)$row['metodo'],
            'fecha'=>(string)$row['fecha'],
            'estado'=>$estado,
            'href'=>'/pago-detalle.php?folio='.rawurlencode((string)$row['folio']),
        ];
    }

    return [
        'disponible'=>true,
        'fecha'=>$fecha,
        'pagos'=>count($rows),
        'validos'=>$validos,
        'total_valido'=>round($totalValido,2),
        'invalidados'=>$invalidos,
        'total_invalidado'=>round($totalInvalidado,2),
        'rows'=>$rows,
        'fuente'=>'pagos.fecha + pagos.estado',
        'contrato'=>'Solo estado VALIDO suma al cobro del día. Un pago invalidado conserva su fila y permanece visible como revisión del hecho fechado.',
    ];
}

function hache_resumen_diario_pendientes_actuales(PDO $pdo,array $sede,bool $includeGlobalProspects,DateTimeImmutable $referencia): array
{
    $fuentes=centro_pendientes_compuesto_fuentes_activas($pdo,$sede,$includeGlobalProspects,$referencia);
    $historico=centro_pendientes_compuesto_historico($pdo,(string)$sede['id'],$includeGlobalProspects);
    $rows=[];
    $resumen=[
        'total'=>0,
        'pendientes'=>0,
        'atendidos'=>0,
        'financieros'=>0,
        'reposiciones'=>0,
        'prospectos'=>0,
        'otros'=>0,
    ];
    $tiposFinancieros=[
        'MENSUALIDAD_REGULAR_SIN_COBERTURA',
        'INSCRIPCION_REGULAR_SIN_COBERTURA',
        'SALDO_INTENSIVO_PENDIENTE',
    ];

    foreach($fuentes as $identidad=>$pendiente){
        $estado=centro_pendientes_estado_efectivo($historico[$identidad]??null,true);
        $tipo=(string)($pendiente['tipo']??'');
        $resumen['total']++;
        if($estado==='ATENDIDO')$resumen['atendidos']++;else$resumen['pendientes']++;
        if(in_array($tipo,$tiposFinancieros,true))$resumen['financieros']++;
        elseif($tipo==='REPOSICION_REGULAR_DISPONIBLE')$resumen['reposiciones']++;
        elseif($tipo==='PROSPECTO_SIN_SEGUIMIENTO')$resumen['prospectos']++;
        else$resumen['otros']++;

        $rows[]=[
            'tipo'=>$tipo,
            'nombre'=>centro_pendientes_compuesto_descripcion_tipo($tipo),
            'estado'=>$estado,
            'alumno_id'=>isset($pendiente['alumno_id'])&&$pendiente['alumno_id']!==null?(string)$pendiente['alumno_id']:null,
            'alumno'=>isset($pendiente['alumno_nombre'])&&$pendiente['alumno_nombre']!==null?(string)$pendiente['alumno_nombre']:null,
            'sede'=>isset($pendiente['sede_nombre'])?(string)$pendiente['sede_nombre']:null,
            'fecha_referencia'=>isset($pendiente['fecha_referencia'])&&$pendiente['fecha_referencia']!==null?(string)$pendiente['fecha_referencia']:null,
            'explicacion'=>(string)($pendiente['explicacion']??''),
            'href'=>(string)($pendiente['href']??'/pendientes.php'),
        ];
    }

    return [
        'disponible'=>true,
        'actualizado_en'=>$referencia->format(DateTimeInterface::ATOM),
        'resumen'=>$resumen,
        'rows'=>$rows,
        'prospectos_globales_incluidos'=>$includeGlobalProspects,
        'fuente'=>'F1/F5 centro_pendientes_compuesto',
        'alcance'=>'Causas activas al instante de consulta. No representa un snapshot histórico del día seleccionado.',
    ];
}

function hache_resumen_diario_incidencias(PDO $pdo,string $sedeId,string $fecha): array
{
    try{
        $st=$pdo->prepare("SELECT se.id,se.estado,se.motivo_cancelacion,h.hora_inicio,h.hora_fin
            FROM sesiones se
            INNER JOIN horarios h ON h.id=se.horario_id
            WHERE se.fecha=:f AND h.sede_id=:s AND se.estado='CANCELADA'
            ORDER BY h.hora_inicio,se.id");
        $st->execute([':f'=>$fecha,':s'=>$sedeId]);
        $sesiones=[];
        foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){
            $sesiones[]=[
                'sesion_id'=>(string)$row['id'],
                'hora_inicio'=>(string)$row['hora_inicio'],
                'hora_fin'=>(string)$row['hora_fin'],
                'motivo'=>$row['motivo_cancelacion']!==null?(string)$row['motivo_cancelacion']:null,
                'href'=>'/sesiones.php?fecha='.rawurlencode($fecha),
            ];
        }

        $st=$pdo->prepare("SELECT pc.id,pc.profesor_id,p.nombre profesor,pc.sesion_id,pc.motivo,pc.source,pc.created_at,
                h.hora_inicio,h.hora_fin
            FROM profesor_cancelaciones pc
            INNER JOIN profesores p ON p.id=pc.profesor_id
            INNER JOIN sesiones se ON se.id=pc.sesion_id
            INNER JOIN horarios h ON h.id=se.horario_id
            WHERE se.fecha=:f AND h.sede_id=:s
            ORDER BY h.hora_inicio,pc.created_at,pc.id");
        $st->execute([':f'=>$fecha,':s'=>$sedeId]);
        $profesores=[];
        foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){
            $profesores[]=[
                'incidencia_id'=>(string)$row['id'],
                'profesor_id'=>(string)$row['profesor_id'],
                'profesor'=>(string)$row['profesor'],
                'sesion_id'=>(string)$row['sesion_id'],
                'hora_inicio'=>(string)$row['hora_inicio'],
                'hora_fin'=>(string)$row['hora_fin'],
                'motivo'=>(string)$row['motivo'],
                'origen'=>(string)$row['source'],
                'created_at'=>(string)$row['created_at'],
                'href'=>'/profesor-actividad.php?desde='.rawurlencode($fecha).'&hasta='.rawurlencode($fecha),
            ];
        }

        $st=$pdo->prepare("SELECT ps.id,ps.sesion_id,po.id original_id,po.nombre original_nombre,
                pr.id sustituto_id,pr.nombre sustituto_nombre,ps.motivo,ps.origen,ps.created_at,h.hora_inicio,h.hora_fin
            FROM profesor_sustituciones ps
            INNER JOIN profesores po ON po.id=ps.profesor_original_id
            INNER JOIN profesores pr ON pr.id=ps.profesor_sustituto_id
            INNER JOIN sesiones se ON se.id=ps.sesion_id
            INNER JOIN horarios h ON h.id=se.horario_id
            WHERE se.fecha=:f AND h.sede_id=:s AND ps.estado='ACTIVA'
            ORDER BY h.hora_inicio,ps.created_at,ps.id");
        $st->execute([':f'=>$fecha,':s'=>$sedeId]);
        $sustituciones=[];
        foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){
            $sustituciones[]=[
                'sustitucion_id'=>(string)$row['id'],
                'sesion_id'=>(string)$row['sesion_id'],
                'hora_inicio'=>(string)$row['hora_inicio'],
                'hora_fin'=>(string)$row['hora_fin'],
                'profesor_original_id'=>(string)$row['original_id'],
                'profesor_original'=>(string)$row['original_nombre'],
                'profesor_sustituto_id'=>(string)$row['sustituto_id'],
                'profesor_sustituto'=>(string)$row['sustituto_nombre'],
                'motivo'=>(string)$row['motivo'],
                'origen'=>(string)$row['origen'],
                'created_at'=>(string)$row['created_at'],
                'href'=>'/profesor-sustituciones.php?desde='.rawurlencode($fecha).'&hasta='.rawurlencode($fecha),
            ];
        }

        $coverage=[];
        try{
            $q=$pdo->query("SELECT clave,valor FROM configuracion WHERE clave IN ('profesores_asignaciones_cobertura_desde','profesores_sustituciones_cobertura_desde')");
            foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)$coverage[(string)$row['clave']]=(string)$row['valor'];
        }catch(Throwable){}

        return [
            'disponible'=>true,
            'sesiones_canceladas'=>['total'=>count($sesiones),'rows'=>$sesiones],
            'profesores'=>['total'=>count($profesores),'rows'=>$profesores],
            'sustituciones_activas'=>['total'=>count($sustituciones),'rows'=>$sustituciones],
            'cobertura'=>$coverage,
            'fuente'=>'sesiones + profesor_cancelaciones + profesor_sustituciones',
            'alcance'=>'Una incidencia individual de profesor no equivale por sí sola a una clase cancelada; las sustituciones son explícitas.',
        ];
    }catch(Throwable $e){
        return [
            'disponible'=>false,
            'sesiones_canceladas'=>['total'=>null,'rows'=>[]],
            'profesores'=>['total'=>null,'rows'=>[]],
            'sustituciones_activas'=>['total'=>null,'rows'=>[]],
            'cobertura'=>[],
            'motivo'=>'Las fuentes operativas de incidencias no están disponibles para esta lectura.',
        ];
    }
}

function hache_resumen_diario_correcciones(PDO $pdo,string $sedeId,string $fecha): array
{
    $inicio=$fecha.' 00:00:00';
    $dia=DateTimeImmutable::createFromFormat('!Y-m-d',$fecha,new DateTimeZone('America/Cancun'));
    if(!$dia)throw new InvalidArgumentException('Fecha operativa inválida');
    $fin=$dia->modify('+1 day')->format('Y-m-d').' 00:00:00';

    $edicionesPago=[];
    try{
        $st=$pdo->prepare("SELECT h.id,h.fecha_hora,h.referencia_id,p.folio
            FROM historial h
            INNER JOIN pagos p ON p.id=h.referencia_id
            INNER JOIN alumnos a ON a.id=p.alumno_id
            WHERE h.tipo='PAGO'
              AND h.referencia_tipo='PAGO'
              AND a.sede_id=:s
              AND p.fecha>=:d
              AND p.fecha<:h
            ORDER BY h.fecha_hora,h.id");
        $st->execute([':s'=>$sedeId,':d'=>$inicio,':h'=>$fin]);
        foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){
            $edicionesPago[]=[
                'historial_id'=>(string)$row['id'],
                'pago_id'=>(string)$row['referencia_id'],
                'folio'=>(int)$row['folio'],
                'registrado_en'=>(string)$row['fecha_hora'],
                'href'=>'/auditoria.php',
            ];
        }
    }catch(Throwable){}

    $asistencias=[];
    try{
        $st=$pdo->prepare("SELECT ae.id,ae.entidad_id,ae.created_at,se.id sesion_id
            FROM auditoria_eventos ae
            INNER JOIN asistencias aa ON aa.id=ae.entidad_id
            INNER JOIN sesiones se ON se.id=aa.sesion_id
            INNER JOIN horarios ho ON ho.id=se.horario_id
            WHERE ae.accion='ASISTENCIA_CORREGIDA'
              AND ho.sede_id=:s
              AND se.fecha=:f
            ORDER BY ae.created_at,ae.id");
        $st->execute([':s'=>$sedeId,':f'=>$fecha]);
        foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){
            $asistencias[]=[
                'evento_id'=>(string)$row['id'],
                'asistencia_id'=>(string)$row['entidad_id'],
                'sesion_id'=>(string)$row['sesion_id'],
                'registrado_en'=>(string)$row['created_at'],
                'href'=>'/auditoria.php',
            ];
        }
    }catch(Throwable){}

    return [
        'disponible'=>true,
        'ediciones_pago'=>['total'=>count($edicionesPago),'rows'=>$edicionesPago],
        'correcciones_asistencia'=>['total'=>count($asistencias),'rows'=>$asistencias],
        'fuente'=>'historial PAGO + auditoria_eventos ASISTENCIA_CORREGIDA',
        'alcance'=>'La lectura es viva: estas evidencias señalan revisiones durables relacionadas con hechos que actualmente pertenecen al día consultado; no reconstruyen un snapshot anterior.',
    ];
}
