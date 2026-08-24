<?php
declare(strict_types=1);

function regla_bloquear_identidades_alumnos(PDO $pdo): void
{
    if(!$pdo->inTransaction()) throw new LogicException('El bloqueo de identidades requiere una transacción activa');
    // alumnos.whatsapp conserva datos históricos que no permiten imponer aún
    // un UNIQUE global. Esta fila estable serializa únicamente las altas y los
    // cambios de identidad para que la validación de duplicados sea atómica.
    $st=$pdo->query("SELECT id FROM sedes ORDER BY id LIMIT 1 FOR UPDATE");
    if(!$st->fetchColumn()) throw new RuntimeException('No existe una sede para proteger las identidades de alumnos');
}

function regla_periodo_regular_actual(string $sedeClave, ?string $cicloPago, ?DateTimeImmutable $referencia=null): array
{
    $d=$referencia ?: new DateTimeImmutable('today');
    $sede=strtoupper($sedeClave);
    $ciclo=strtoupper((string)$cicloPago);
    if($sede==='PALAPAS' && $ciclo==='P15'){
        $inicio=((int)$d->format('j')>=15)
            ? new DateTimeImmutable($d->format('Y-m-15'))
            : new DateTimeImmutable($d->modify('-1 month')->format('Y-m-15'));
        $fin=$inicio->modify('+1 month')->modify('-1 day');
        return ['mes'=>(int)$inicio->format('n'),'anio'=>(int)$inicio->format('Y'),'inicio'=>$inicio->format('Y-m-d'),'fin'=>$fin->format('Y-m-d')];
    }
    $inicio=new DateTimeImmutable($d->format('Y-m-01'));
    $fin=$inicio->modify('last day of this month');
    return ['mes'=>(int)$inicio->format('n'),'anio'=>(int)$inicio->format('Y'),'inicio'=>$inicio->format('Y-m-d'),'fin'=>$fin->format('Y-m-d')];
}

function regla_inscripcion_historica_cubierta(PDO $pdo,string $alumnoId): bool
{
    $st=$pdo->prepare("SELECT inscripcion_historica_cubierta FROM alumno_reglas_negocio WHERE alumno_id=:a LIMIT 1");
    $st->execute([':a'=>$alumnoId]);
    return (bool)$st->fetchColumn();
}

function regla_marcar_inscripcion_historica(PDO $pdo,string $alumnoId,bool $cubierta,?string $nota=null): void
{
    $st=$pdo->prepare("INSERT INTO alumno_reglas_negocio(alumno_id,inscripcion_historica_cubierta,nota) VALUES(:a,:c,:n)
        ON DUPLICATE KEY UPDATE inscripcion_historica_cubierta=VALUES(inscripcion_historica_cubierta),nota=VALUES(nota),updated_at=NOW()");
    $st->execute([':a'=>$alumnoId,':c'=>$cubierta?1:0,':n'=>$nota]);
}

function regla_es_continuidad_intensivo_monteverde(PDO $pdo,string $alumnoId,string $sedeId): bool
{
    $st=$pdo->prepare("SELECT 1 FROM curso_intensivo_alumnos cia INNER JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id WHERE cia.alumno_id=:a AND ci.sede_id=:s AND cia.continua_regular=1 LIMIT 1");
    $st->execute([':a'=>$alumnoId,':s'=>$sedeId]);
    return (bool)$st->fetchColumn();
}

function regla_inscripcion_regular_cubierta(PDO $pdo,string $alumnoId,string $sedeId,string $sedeClave): bool
{
    if(regla_inscripcion_historica_cubierta($pdo,$alumnoId)) return true;
    if(strtoupper($sedeClave)==='MONTEVERDE' && regla_es_continuidad_intensivo_monteverde($pdo,$alumnoId,$sedeId)) return true;
    $st=$pdo->prepare("SELECT 1 FROM pagos p INNER JOIN inscripciones i ON i.id=p.inscripcion_id WHERE p.alumno_id=:a AND i.sede_id=:s AND p.tipo='INSCRIPCION' AND p.estado='VALIDO' LIMIT 1");
    $st->execute([':a'=>$alumnoId,':s'=>$sedeId]);
    return (bool)$st->fetchColumn();
}

function regla_mensualidad_regular_cubierta(PDO $pdo,string $alumnoId,string $sedeId,string $sedeClave,?string $cicloPago,?DateTimeImmutable $referencia=null): bool
{
    $p=regla_periodo_regular_actual($sedeClave,$cicloPago,$referencia);
    $st=$pdo->prepare("SELECT 1 FROM mensualidades m WHERE m.alumno_id=:a AND m.sede_id=:s AND m.periodo_inicio=:pi AND m.periodo_fin=:pf AND m.estado='PAGADA' LIMIT 1");
    $st->execute([':a'=>$alumnoId,':s'=>$sedeId,':pi'=>$p['inicio'],':pf'=>$p['fin']]);
    return (bool)$st->fetchColumn();
}

function regla_intensivo_pagado(PDO $pdo,string $alumnoId,string $sedeId,?string $cursoId=null,?DateTimeImmutable $referencia=null,?string $horarioId=null): bool
{
    $where=[
        'cia.alumno_id=:a',
        'ci.sede_id=:s',
        "ci.estado IN ('PROGRAMADO','EN_CURSO')",
        "p.tipo='INTENSIVO'",
        "p.estado='VALIDO'",
    ];
    $params=[':a'=>$alumnoId,':s'=>$sedeId];
    if($cursoId!==null && $cursoId!==''){$where[]='ci.id=:c';$params[':c']=$cursoId;}
    if($horarioId!==null && $horarioId!==''){$where[]='cia.horario_id=:h';$params[':h']=$horarioId;}
    if($referencia!==null){$where[]=':f BETWEEN ci.fecha_inicio AND ci.fecha_fin';$params[':f']=$referencia->format('Y-m-d');}
    $sql="SELECT 1 FROM curso_intensivo_alumnos cia
        INNER JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id
        INNER JOIN pagos p ON p.intensivo_id=ci.id AND p.alumno_id=cia.alumno_id
        WHERE ".implode(' AND ',$where).' LIMIT 1';
    $st=$pdo->prepare($sql);$st->execute($params);
    return (bool)$st->fetchColumn();
}

function regla_tiene_intensivo_activo(PDO $pdo,string $alumnoId,string $sedeId): bool
{
    $st=$pdo->prepare("SELECT 1 FROM curso_intensivo_alumnos cia INNER JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id WHERE cia.alumno_id=:a AND ci.sede_id=:s AND ci.estado IN ('PROGRAMADO','EN_CURSO') LIMIT 1");
    $st->execute([':a'=>$alumnoId,':s'=>$sedeId]);
    return (bool)$st->fetchColumn();
}

function regla_derecho_clase(PDO $pdo,string $alumnoId,string $sedeId,string $horarioId,DateTimeImmutable $fecha): array
{
    $st=$pdo->prepare("SELECT a.sede_id,a.ciclo_pago,a.plan_actual_id,a.horario_preferido_id,a.estado_administrativo,s.clave sede_clave,
        (SELECT ci.id FROM curso_intensivo_alumnos cia INNER JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id
         WHERE cia.alumno_id=a.id AND cia.horario_id=:h AND ci.sede_id=:si AND ci.estado IN ('PROGRAMADO','EN_CURSO')
           AND :f BETWEEN ci.fecha_inicio AND ci.fecha_fin LIMIT 1) curso_intensivo_id
        FROM alumnos a INNER JOIN sedes s ON s.id=a.sede_id
        WHERE a.id=:a AND a.sede_id=:sa LIMIT 1");
    $st->execute([':h'=>$horarioId,':si'=>$sedeId,':f'=>$fecha->format('Y-m-d'),':a'=>$alumnoId,':sa'=>$sedeId]);
    $a=$st->fetch();
    if(!$a || $a['estado_administrativo']==='BAJA') return ['puede'=>false,'tipo'=>null,'motivo'=>'Alumno inactivo o fuera de sede'];
    if(!empty($a['curso_intensivo_id'])){
        $pagado=regla_intensivo_pagado($pdo,$alumnoId,$sedeId,(string)$a['curso_intensivo_id'],$fecha,$horarioId);
        return ['puede'=>$pagado,'tipo'=>'INTENSIVO','curso_intensivo_id'=>$a['curso_intensivo_id'],'motivo'=>$pagado?null:'Curso intensivo pendiente de pago'];
    }
    if(empty($a['plan_actual_id']) || (string)$a['horario_preferido_id']!==$horarioId) return ['puede'=>false,'tipo'=>'REGULAR','motivo'=>'El alumno no pertenece a este horario'];
    $ins=regla_inscripcion_regular_cubierta($pdo,$alumnoId,$sedeId,(string)$a['sede_clave']);
    $men=regla_mensualidad_regular_cubierta($pdo,$alumnoId,$sedeId,(string)$a['sede_clave'],$a['ciclo_pago'],$fecha);
    return ['puede'=>$ins&&$men,'tipo'=>'REGULAR','inscripcion_cubierta'=>$ins,'mensualidad_cubierta'=>$men,'motivo'=>($ins&&$men)?null:'Inscripción o mensualidad pendiente'];
}

function regla_crear_mensualidad_pendiente(PDO $pdo,string $alumnoId,string $sedeId,string $sedeClave,?string $cicloPago,string $planId,float $precio,string $createdBy,?DateTimeImmutable $referencia=null): void
{
    $p=regla_periodo_regular_actual($sedeClave,$cicloPago,$referencia);
    $st=$pdo->prepare("SELECT id FROM mensualidades WHERE alumno_id=:a AND sede_id=:s AND mes=:m AND anio=:y LIMIT 1");
    $st->execute([':a'=>$alumnoId,':s'=>$sedeId,':m'=>$p['mes'],':y'=>$p['anio']]);
    if($st->fetchColumn()) return;
    $id=(string)$pdo->query('SELECT UUID()')->fetchColumn();
    $importe=number_format($precio,2,'.','');
    $st=$pdo->prepare("INSERT INTO mensualidades(id,sede_id,alumno_id,mes,anio,periodo_inicio,periodo_fin,plan_id,importe_estandar,importe_a_cobrar,importe_cobrado,estado,observacion,fecha_pago,created_by) VALUES(:id,:s,:a,:m,:y,:pi,:pf,:plan,:ie,:ia,NULL,'PENDIENTE',NULL,NULL,:u)");
    $st->execute([':id'=>$id,':s'=>$sedeId,':a'=>$alumnoId,':m'=>$p['mes'],':y'=>$p['anio'],':pi'=>$p['inicio'],':pf'=>$p['fin'],':plan'=>$planId,':ie'=>$importe,':ia'=>$importe,':u'=>$createdBy]);
}

function regla_recalcular_alumno(PDO $pdo,string $alumnoId): array
{
    $st=$pdo->prepare("SELECT a.id,a.sede_id,a.ciclo_pago,a.plan_actual_id,a.estado_administrativo,s.clave sede_clave FROM alumnos a INNER JOIN sedes s ON s.id=a.sede_id WHERE a.id=:a LIMIT 1");
    $st->execute([':a'=>$alumnoId]);$a=$st->fetch();
    if(!$a || $a['estado_administrativo']==='BAJA') return ['aplica'=>false];
    $intensivoActivo=regla_tiene_intensivo_activo($pdo,$alumnoId,(string)$a['sede_id']);
    $intensivoPagado=$intensivoActivo&&regla_intensivo_pagado($pdo,$alumnoId,(string)$a['sede_id']);
    $regularAplica=!$intensivoActivo&&!empty($a['plan_actual_id']);
    $historica=$regularAplica?regla_inscripcion_historica_cubierta($pdo,$alumnoId):false;
    $ins=$regularAplica?regla_inscripcion_regular_cubierta($pdo,$alumnoId,(string)$a['sede_id'],(string)$a['sede_clave']):false;
    $men=$regularAplica?regla_mensualidad_regular_cubierta($pdo,$alumnoId,(string)$a['sede_id'],(string)$a['sede_clave'],$a['ciclo_pago']):false;
    $estado=($intensivoPagado || ($regularAplica && $ins && $men))?'ACTIVO':'PENDIENTE';
    if($a['estado_administrativo']!==$estado){$up=$pdo->prepare("UPDATE alumnos SET estado_administrativo=:e,updated_at=NOW() WHERE id=:a AND estado_administrativo<>'BAJA'");$up->execute([':e'=>$estado,':a'=>$alumnoId]);}
    return ['aplica'=>true,'regular_aplica'=>$regularAplica,'intensivo_activo'=>$intensivoActivo,'intensivo_pagado'=>$intensivoPagado,'inscripcion_cubierta'=>$ins,'mensualidad_cubierta'=>$men,'estado'=>$estado,'inscripcion_historica'=>$historica,'inscripcion_exenta'=>$regularAplica&&strtoupper((string)$a['sede_clave'])==='MONTEVERDE'&&regla_es_continuidad_intensivo_monteverde($pdo,$alumnoId,(string)$a['sede_id'])];
}

function regla_recalcular_alumno_regular(PDO $pdo,string $alumnoId): array
{
    return regla_recalcular_alumno($pdo,$alumnoId);
}

function regla_promover_planes_programados_sede(PDO $pdo,string $sedeId): int
{
    $st=$pdo->prepare("UPDATE alumnos a
        INNER JOIN planes p ON p.id=a.plan_programado_id AND p.sede_id=a.sede_id AND p.activo=1
        SET a.plan_actual_id=a.plan_programado_id,a.plan_programado_id=NULL,a.plan_programado_desde=NULL,a.updated_at=NOW()
        WHERE a.sede_id=:s AND a.plan_programado_id IS NOT NULL AND a.plan_programado_desde IS NOT NULL AND a.plan_programado_desde<=CURDATE()");
    $st->execute([':s'=>$sedeId]);
    return $st->rowCount();
}

function regla_reconciliar_sede(PDO $pdo,string $sedeId): void
{
    $st=$pdo->prepare("SELECT a.id FROM alumnos a WHERE a.sede_id=:s AND a.estado_administrativo<>'BAJA'");
    $st->execute([':s'=>$sedeId]);
    foreach($st->fetchAll(PDO::FETCH_COLUMN) as $id) regla_recalcular_alumno($pdo,(string)$id);
}

function regla_reconciliar_sede_una_vez(PDO $pdo,string $sedeId,string $sedeClave): bool
{
    $clave=strtoupper(trim($sedeClave));
    $hoy=date('Y-m-d');
    if(($_SESSION['hache_reconciliada'][$clave]??null)===$hoy) return false;
    regla_promover_planes_programados_sede($pdo,$sedeId);
    regla_reconciliar_sede($pdo,$sedeId);
    $_SESSION['hache_reconciliada'][$clave]=$hoy;
    return true;
}
