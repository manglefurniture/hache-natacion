<?php
declare(strict_types=1);

function dashboard_p06_config_datetime(PDO $pdo,string $clave): ?string
{
    try{
        $st=$pdo->prepare("SELECT valor FROM configuracion WHERE clave=:c LIMIT 1");
        $st->execute([':c'=>$clave]);
        $valor=trim((string)($st->fetchColumn()?:''));
        if($valor==='')return null;
        $dt=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$valor,new DateTimeZone('America/Cancun'));
        return $dt&&$dt->format('Y-m-d H:i:s')===$valor?$valor:null;
    }catch(Throwable $e){
        return null;
    }
}

function dashboard_p06_period_bounds(string $inicio,string $fin): array
{
    return [$inicio.' 00:00:00',$fin.' 23:59:59'];
}

function dashboard_nuevos_alumnos(PDO $pdo,string $sedeId,string $inicio,string $fin): array
{
    $st=$pdo->prepare("SELECT id,nombre,fecha_inicio,estado_administrativo
        FROM alumnos
        WHERE sede_id=:s
          AND fecha_inicio BETWEEN :i AND :f
        ORDER BY fecha_inicio,nombre,id");
    $st->execute([':s'=>$sedeId,':i'=>$inicio,':f'=>$fin]);
    $rows=[];
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){
        $rows[]=[
            'alumno_id'=>(string)$row['id'],
            'nombre'=>(string)$row['nombre'],
            'fecha_inicio'=>(string)$row['fecha_inicio'],
            'estado_administrativo'=>(string)$row['estado_administrativo'],
        ];
    }
    return [
        'total'=>count($rows),
        'rows'=>$rows,
        'periodo'=>['inicio'=>$inicio,'fin'=>$fin],
        'unidad'=>'alumnos_por_fecha_inicio',
        'reactivaciones'=>'No crean un alta nueva: REACTIVAR no modifica fecha_inicio.',
    ];
}

function dashboard_bajas_registradas(PDO $pdo,string $sedeId,string $inicio,string $fin): array
{
    $cobertura=dashboard_p06_config_datetime($pdo,'dashboard_bajas_cobertura_desde');
    if($cobertura===null){
        return ['disponible'=>false,'total'=>null,'rows'=>[],'cobertura_desde'=>null,'motivo'=>'Cobertura de bajas todavía no inicializada.'];
    }

    [$desdePeriodo,$hasta]=dashboard_p06_period_bounds($inicio,$fin);
    $desde=max($desdePeriodo,$cobertura);
    if($desde>$hasta){
        return ['disponible'=>false,'total'=>null,'rows'=>[],'cobertura_desde'=>$cobertura,'motivo'=>'El periodo seleccionado es anterior al inicio de cobertura fiable.'];
    }

    $st=$pdo->prepare("SELECT id,entidad_id,detalle,created_at
        FROM auditoria_eventos
        WHERE accion='ALUMNO_BAJA'
          AND entidad='alumno'
          AND created_at BETWEEN :d AND :h
        ORDER BY created_at,id");
    $st->execute([':d'=>$desde,':h'=>$hasta]);

    $ids=[];
    $events=[];
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){
        $detalle=json_decode((string)($row['detalle']??''),true);
        if(!is_array($detalle)||(string)($detalle['sede_id']??'')!==$sedeId)continue;
        $alumnoId=trim((string)($row['entidad_id']??''));
        if($alumnoId!=='')$ids[$alumnoId]=true;
        $events[]=[
            'evento_id'=>(string)$row['id'],
            'alumno_id'=>$alumnoId!==''?$alumnoId:null,
            'fecha_hora'=>(string)$row['created_at'],
        ];
    }

    $names=[];
    if($ids){
        $marks=implode(',',array_fill(0,count($ids),'?'));
        $q=$pdo->prepare("SELECT id,nombre FROM alumnos WHERE id IN ($marks)");
        $q->execute(array_keys($ids));
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)$names[(string)$row['id']]=(string)$row['nombre'];
    }
    foreach($events as &$event)$event['nombre']=$event['alumno_id']!==null?($names[$event['alumno_id']]??'Alumno eliminado'):'Alumno eliminado';
    unset($event);

    return [
        'disponible'=>true,
        'total'=>count($events),
        'rows'=>$events,
        'periodo'=>['inicio'=>$inicio,'fin'=>$fin],
        'cobertura_desde'=>$cobertura,
        'unidad'=>'eventos_de_baja',
        'fuente'=>'auditoria_eventos.ALUMNO_BAJA',
    ];
}

function dashboard_asistencia_periodo(PDO $pdo,string $sedeId,string $inicio,string $fin): array
{
    $cobertura=dashboard_p06_config_datetime($pdo,'dashboard_asistencia_cobertura_desde');
    if($cobertura===null){
        return ['disponible'=>false,'porcentaje'=>null,'rows'=>[],'cobertura_desde'=>null,'motivo'=>'Cobertura persistida de asistencia todavía no inicializada.'];
    }

    try{
        $capturadas=$pdo->prepare("SELECT COUNT(*)
            FROM sesion_asistencia_cobertura c
            INNER JOIN sesiones s ON s.id=c.sesion_id
            INNER JOIN horarios h ON h.id=s.horario_id
            WHERE h.sede_id=:site
              AND s.fecha BETWEEN :i AND :f
              AND s.estado='REALIZADA'");
        $capturadas->execute([':site'=>$sedeId,':i'=>$inicio,':f'=>$fin]);
        $sesionesCapturadas=(int)$capturadas->fetchColumn();

        $st=$pdo->prepare("SELECT
                s.id,s.fecha,h.hora_inicio,h.hora_fin,
                c.expected_count,c.marked_count,c.captured_at,
                COALESCE(SUM(aa.estado='PRESENTE'),0) presentes,
                COALESCE(SUM(aa.estado='AUSENTE_JUSTIFICADA'),0) justificadas,
                COALESCE(SUM(aa.estado='AUSENTE_NO_JUSTIFICADA'),0) injustificadas
            FROM sesion_asistencia_cobertura c
            INNER JOIN sesiones s ON s.id=c.sesion_id
            INNER JOIN horarios h ON h.id=s.horario_id
            LEFT JOIN asistencias aa ON aa.sesion_id=s.id
            WHERE h.sede_id=:site
              AND s.fecha BETWEEN :i AND :f
              AND s.estado='REALIZADA'
              AND c.complete=1
              AND c.expected_count>0
            GROUP BY s.id,s.fecha,h.hora_inicio,h.hora_fin,c.expected_count,c.marked_count,c.captured_at
            ORDER BY s.fecha,h.hora_inicio,s.id");
        $st->execute([':site'=>$sedeId,':i'=>$inicio,':f'=>$fin]);

        $rows=[];$esperados=0;$presentes=0;$justificadas=0;$injustificadas=0;
        foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){
            $e=(int)$row['expected_count'];$p=(int)$row['presentes'];$j=(int)$row['justificadas'];$n=(int)$row['injustificadas'];
            $esperados+=$e;$presentes+=$p;$justificadas+=$j;$injustificadas+=$n;
            $rows[]=[
                'sesion_id'=>(string)$row['id'],
                'fecha'=>(string)$row['fecha'],
                'hora_inicio'=>(string)$row['hora_inicio'],
                'hora_fin'=>(string)$row['hora_fin'],
                'esperados'=>$e,
                'presentes'=>$p,
                'ausencias_justificadas'=>$j,
                'ausencias_no_justificadas'=>$n,
                'captured_at'=>(string)$row['captured_at'],
            ];
        }

        if($esperados===0){
            return [
                'disponible'=>false,
                'porcentaje'=>null,
                'rows'=>[],
                'sesiones_completas'=>0,
                'sesiones_capturadas'=>$sesionesCapturadas,
                'cobertura_desde'=>$cobertura,
                'motivo'=>'Todavía no hay sesiones no canceladas con registro completo dentro del periodo.',
            ];
        }

        return [
            'disponible'=>true,
            'porcentaje'=>round(($presentes/$esperados)*100,1),
            'presentes'=>$presentes,
            'esperados'=>$esperados,
            'ausencias_justificadas'=>$justificadas,
            'ausencias_no_justificadas'=>$injustificadas,
            'sesiones_completas'=>count($rows),
            'sesiones_capturadas'=>$sesionesCapturadas,
            'rows'=>$rows,
            'periodo'=>['inicio'=>$inicio,'fin'=>$fin],
            'cobertura_desde'=>$cobertura,
            'unidad'=>'marcas_en_sesiones_con_cobertura_completa',
        ];
    }catch(Throwable $e){
        return ['disponible'=>false,'porcentaje'=>null,'rows'=>[],'cobertura_desde'=>$cobertura,'motivo'=>'La cobertura persistida de asistencia no está disponible.'];
    }
}
