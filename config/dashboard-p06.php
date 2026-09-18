<?php
declare(strict_types=1);

function dashboard_p06_config_datetime_utc(PDO $pdo,string $clave): ?string
{
    try{
        $st=$pdo->prepare("SELECT valor FROM configuracion WHERE clave=:c LIMIT 1");
        $st->execute([':c'=>$clave]);
        $valor=trim((string)($st->fetchColumn()?:''));
        if($valor==='')return null;
        $dt=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$valor,new DateTimeZone('UTC'));
        return $dt&&$dt->format('Y-m-d H:i:s')===$valor?$valor:null;
    }catch(Throwable $e){
        return null;
    }
}

function dashboard_p06_utc_to_cancun(string $utc): string
{
    $dt=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$utc,new DateTimeZone('UTC'));
    return $dt?$dt->setTimezone(new DateTimeZone('America/Cancun'))->format('Y-m-d H:i:s'):$utc;
}

function dashboard_p06_period_bounds_utc(string $inicio,string $fin): array
{
    $zona=new DateTimeZone('America/Cancun');
    $desde=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$inicio.' 00:00:00',$zona);
    $hasta=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$fin.' 23:59:59',$zona);
    if(!$desde||!$hasta)throw new InvalidArgumentException('Rango de periodo inválido');
    return [
        $desde->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        $hasta->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
    ];
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
    $coberturaUtc=dashboard_p06_config_datetime_utc($pdo,'dashboard_bajas_cobertura_desde');
    if($coberturaUtc===null){
        return ['disponible'=>false,'total'=>null,'rows'=>[],'cobertura_desde'=>null,'motivo'=>'Cobertura de bajas todavía no inicializada.'];
    }

    $coberturaLocal=dashboard_p06_utc_to_cancun($coberturaUtc);
    [$desdePeriodo,$hasta]=dashboard_p06_period_bounds_utc($inicio,$fin);
    $desde=max($desdePeriodo,$coberturaUtc);
    if($desde>$hasta){
        return ['disponible'=>false,'total'=>null,'rows'=>[],'cobertura_desde'=>$coberturaLocal,'cobertura_desde_utc'=>$coberturaUtc,'motivo'=>'El periodo seleccionado es anterior al inicio de cobertura fiable.'];
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
        $fechaUtc=(string)$row['created_at'];
        $events[]=[
            'evento_id'=>(string)$row['id'],
            'alumno_id'=>$alumnoId!==''?$alumnoId:null,
            'fecha_hora'=>dashboard_p06_utc_to_cancun($fechaUtc),
            'fecha_hora_utc'=>$fechaUtc,
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
        'cobertura_desde'=>$coberturaLocal,
        'cobertura_desde_utc'=>$coberturaUtc,
        'unidad'=>'eventos_de_baja',
        'fuente'=>'auditoria_eventos.ALUMNO_BAJA',
    ];
}

function dashboard_asistencia_periodo(PDO $pdo,string $sedeId,string $inicio,string $fin): array
{
    $coberturaUtc=dashboard_p06_config_datetime_utc($pdo,'dashboard_asistencia_cobertura_desde');
    if($coberturaUtc===null){
        return ['disponible'=>false,'porcentaje'=>null,'rows'=>[],'cobertura_desde'=>null,'motivo'=>'Cobertura persistida de asistencia todavía no inicializada.'];
    }
    $coberturaLocal=dashboard_p06_utc_to_cancun($coberturaUtc);

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
                c.expected_count,c.marked_count,c.present_count,c.justified_count,c.unjustified_count,c.captured_at
            FROM sesion_asistencia_cobertura c
            INNER JOIN sesiones s ON s.id=c.sesion_id
            INNER JOIN horarios h ON h.id=s.horario_id
            WHERE h.sede_id=:site
              AND s.fecha BETWEEN :i AND :f
              AND s.estado='REALIZADA'
              AND c.complete=1
              AND c.expected_count>0
            ORDER BY s.fecha,h.hora_inicio,s.id");
        $st->execute([':site'=>$sedeId,':i'=>$inicio,':f'=>$fin]);

        $rows=[];$esperados=0;$presentes=0;$justificadas=0;$injustificadas=0;
        foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){
            $e=(int)$row['expected_count'];$p=(int)$row['present_count'];$j=(int)$row['justified_count'];$n=(int)$row['unjustified_count'];
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
                'cobertura_desde'=>$coberturaLocal,
                'cobertura_desde_utc'=>$coberturaUtc,
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
            'cobertura_desde'=>$coberturaLocal,
                'cobertura_desde_utc'=>$coberturaUtc,
            'unidad'=>'marcas_en_sesiones_con_cobertura_completa',
        ];
    }catch(Throwable $e){
        return ['disponible'=>false,'porcentaje'=>null,'rows'=>[],'cobertura_desde'=>$coberturaLocal,
                'cobertura_desde_utc'=>$coberturaUtc,'motivo'=>'La cobertura persistida de asistencia no está disponible.'];
    }
}

function dashboard_prospectos_conversion(PDO $pdo,string $inicio,string $fin): array
{
    $coberturaUtc=dashboard_p06_config_datetime_utc($pdo,'dashboard_prospectos_cobertura_desde');
    if($coberturaUtc===null){
        return [
            'disponible'=>false,
            'prospectos'=>null,
            'conversiones'=>null,
            'tasa_conversion'=>null,
            'rows'=>[],
            'cobertura_desde'=>null,
            'motivo'=>'Cobertura forward-only de oportunidades todavía no inicializada.',
        ];
    }

    $coberturaLocal=dashboard_p06_utc_to_cancun($coberturaUtc);
    [$desdePeriodo,$hastaPeriodo]=dashboard_p06_period_bounds_utc($inicio,$fin);
    $desde=max($desdePeriodo,$coberturaUtc);
    if($desde>$hastaPeriodo){
        return [
            'disponible'=>false,
            'prospectos'=>null,
            'conversiones'=>null,
            'tasa_conversion'=>null,
            'rows'=>[],
            'periodo'=>['inicio'=>$inicio,'fin'=>$fin],
            'cobertura_desde'=>$coberturaLocal,
            'cobertura_desde_utc'=>$coberturaUtc,
            'motivo'=>'El periodo seleccionado termina antes del inicio de cobertura fiable de oportunidades.',
        ];
    }

    try{
        $st=$pdo->prepare("SELECT id,entry_source,sede_clave,status,opened_at,closed_at
            FROM sharky_prospect_opportunities
            WHERE opened_at BETWEEN :d AND :h
              AND status IN ('OPEN','CONVERTED')
            ORDER BY opened_at,id");
        $st->execute([':d'=>$desde,':h'=>$hastaPeriodo]);

        $porSede=[
            'MONTEVERDE'=>['prospectos'=>0,'conversiones'=>0,'tasa_conversion'=>null],
            'PALAPAS'=>['prospectos'=>0,'conversiones'=>0,'tasa_conversion'=>null],
            'SIN_SEDE'=>['prospectos'=>0,'conversiones'=>0,'tasa_conversion'=>null],
        ];
        $porFuente=[];
        $porCohorte=[];
        $rows=[];
        $conversiones=0;

        foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){
            $sedeRaw=strtoupper(trim((string)($row['sede_clave']??'')));
            $sede=in_array($sedeRaw,['MONTEVERDE','PALAPAS'],true)?$sedeRaw:'SIN_SEDE';

            $fuenteRaw=strtolower(trim((string)($row['entry_source']??'')));
            $fuente=in_array($fuenteRaw,['meta_ad','web','direct','referral'],true)?$fuenteRaw:'SIN_FUENTE';

            $abiertaUtc=(string)$row['opened_at'];
            $abiertaLocal=dashboard_p06_utc_to_cancun($abiertaUtc);
            $cohorte=substr($abiertaLocal,0,10);
            $convertida=(string)$row['status']==='CONVERTED';

            if(!isset($porFuente[$fuente]))$porFuente[$fuente]=['prospectos'=>0,'conversiones'=>0,'tasa_conversion'=>null];
            if(!isset($porCohorte[$cohorte]))$porCohorte[$cohorte]=['prospectos'=>0,'conversiones'=>0,'tasa_conversion'=>null];

            $porSede[$sede]['prospectos']++;
            $porFuente[$fuente]['prospectos']++;
            $porCohorte[$cohorte]['prospectos']++;

            if($convertida){
                $conversiones++;
                $porSede[$sede]['conversiones']++;
                $porFuente[$fuente]['conversiones']++;
                $porCohorte[$cohorte]['conversiones']++;
            }

            $cerradaUtc=trim((string)($row['closed_at']??''));
            $rows[]=[
                'oportunidad_id'=>(string)$row['id'],
                'cohorte'=>$cohorte,
                'sede'=>$sede,
                'fuente'=>$fuente,
                'estado'=>(string)$row['status'],
                'convertida'=>$convertida,
                'abierta_en'=>$abiertaLocal,
                'abierta_en_utc'=>$abiertaUtc,
                'cerrada_en'=>$cerradaUtc!==''?dashboard_p06_utc_to_cancun($cerradaUtc):null,
                'cerrada_en_utc'=>$cerradaUtc!==''?$cerradaUtc:null,
            ];
        }

        $calcularTasa=static function(array &$buckets): void {
            foreach($buckets as &$bucket){
                $bucket['tasa_conversion']=$bucket['prospectos']>0
                    ?round(($bucket['conversiones']/$bucket['prospectos'])*100,1)
                    :null;
            }
            unset($bucket);
        };
        $calcularTasa($porSede);
        $calcularTasa($porFuente);
        $calcularTasa($porCohorte);
        ksort($porFuente);
        ksort($porCohorte);

        $prospectos=count($rows);
        return [
            'disponible'=>true,
            'prospectos'=>$prospectos,
            'conversiones'=>$conversiones,
            'tasa_conversion'=>$prospectos>0?round(($conversiones/$prospectos)*100,1):null,
            'rows'=>$rows,
            'por_cohorte'=>$porCohorte,
            'por_sede'=>$porSede,
            'por_fuente'=>$porFuente,
            'periodo'=>['inicio'=>$inicio,'fin'=>$fin],
            'cohorte_desde'=>dashboard_p06_utc_to_cancun($desde),
            'cohorte_desde_utc'=>$desde,
            'cobertura_desde'=>$coberturaLocal,
            'cobertura_desde_utc'=>$coberturaUtc,
            'parcial'=>$desde>$desdePeriodo,
            'unidad'=>'oportunidades_por_participante',
            'contrato'=>'La cohorte usa opened_at. EXCLUDED no entra al denominador. CONVERTED exige inscripción Sharky COMPLETED y puede ocurrir después del cierre temporal de la cohorte.',
        ];
    }catch(Throwable $e){
        return [
            'disponible'=>false,
            'prospectos'=>null,
            'conversiones'=>null,
            'tasa_conversion'=>null,
            'rows'=>[],
            'cobertura_desde'=>$coberturaLocal,
            'cobertura_desde_utc'=>$coberturaUtc,
            'motivo'=>'La lectura durable de oportunidades no está disponible.',
        ];
    }
}
