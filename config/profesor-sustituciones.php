<?php

declare(strict_types=1);

final class HacheProfesorSustitucionException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus=422)
    {
        parent::__construct($message);
    }
}

function hache_profesor_sesion_inicio_utc(string $fecha,string $hora): string
{
    $local=DateTimeImmutable::createFromFormat(
        '!Y-m-d H:i:s',
        trim($fecha).' '.trim($hora),
        new DateTimeZone('America/Cancun')
    );
    if(!$local)throw new HacheProfesorSustitucionException('La sesión tiene una fecha u hora inválida.',500);
    return $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function hache_profesor_sustituciones_schema_ready(PDO $pdo): bool
{
    try{
        $st=$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='profesor_sustituciones'");
        if((int)$st->fetchColumn()!==1)return false;
        $st=$pdo->query("SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') cols,MIN(non_unique) non_unique FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='profesor_sustituciones' AND index_name='uq_profesor_sustitucion_activa' GROUP BY index_name");
        $idx=$st->fetch(PDO::FETCH_ASSOC);
        if(!is_array($idx)||(string)($idx['cols']??'')!=='sesion_id,profesor_original_id,activa'||(int)($idx['non_unique']??1)!==0)return false;
        $st=$pdo->query("SELECT COUNT(*) FROM configuracion WHERE clave='profesores_sustituciones_cobertura_desde'");
        return (int)$st->fetchColumn()===1;
    }catch(Throwable $e){return false;}
}

function hache_profesor_sustitucion_registrar(PDO $pdo,string $sesionId,string $originalId,string $sustitutoId,string $motivo,string $actorId): string
{
    if(!hache_profesor_sustituciones_schema_ready($pdo)){
        throw new HacheProfesorSustitucionException('Falta aplicar la migración F7.2 de profesores.',503);
    }
    $sesionId=trim($sesionId);$originalId=trim($originalId);$sustitutoId=trim($sustitutoId);
    $motivo=preg_replace('/\s+/u',' ',trim($motivo))??'';
    if($sesionId===''||$originalId===''||$sustitutoId==='')throw new HacheProfesorSustitucionException('Sesión, profesor original y sustituto son obligatorios.');
    if($originalId===$sustitutoId)throw new HacheProfesorSustitucionException('El profesor sustituto debe ser distinto del profesor original.');
    if($motivo===''||mb_strlen($motivo)>500)throw new HacheProfesorSustitucionException('El motivo es obligatorio y debe tener máximo 500 caracteres.');

    $owns=!$pdo->inTransaction();
    if($owns)$pdo->beginTransaction();
    try{
        $st=$pdo->prepare("SELECT se.id,se.fecha,se.estado,se.cerrada,se.horario_id,h.hora_inicio,h.hora_fin,h.sede_id
            FROM sesiones se
            JOIN horarios h ON h.id=se.horario_id
            WHERE se.id=:s LIMIT 1 FOR UPDATE");
        $st->execute([':s'=>$sesionId]);$sesion=$st->fetch(PDO::FETCH_ASSOC);
        if(!is_array($sesion))throw new HacheProfesorSustitucionException('Sesión no encontrada.',404);
        if((string)$sesion['estado']==='CANCELADA')throw new HacheProfesorSustitucionException('No se puede registrar una sustitución sobre una sesión cancelada.',409);

        $sesionInicioUtc=hache_profesor_sesion_inicio_utc((string)$sesion['fecha'],(string)$sesion['hora_inicio']);
        $coverage=(string)$pdo->query("SELECT valor FROM configuracion WHERE clave='profesores_sustituciones_cobertura_desde' LIMIT 1")->fetchColumn();
        if($coverage===''||$sesionInicioUtc<$coverage){
            throw new HacheProfesorSustitucionException('La sesión está fuera de la cobertura forward-only de sustituciones F7.',409);
        }

        $st=$pdo->prepare("SELECT ph.id
            FROM profesor_horarios ph
            JOIN profesor_horario_vigencias v ON v.profesor_horario_id=ph.id
            WHERE ph.profesor_id=:p AND ph.horario_id=:h
              AND v.vigente_desde<=:inicio1
              AND (v.vigente_hasta IS NULL OR v.vigente_hasta>=:inicio2)
            LIMIT 1 FOR UPDATE");
        $st->execute([':p'=>$originalId,':h'=>(string)$sesion['horario_id'],':inicio1'=>$sesionInicioUtc,':inicio2'=>$sesionInicioUtc]);
        if(!$st->fetchColumn())throw new HacheProfesorSustitucionException('El profesor original no tiene una asignación durable que cubra esa sesión.',409);

        $st=$pdo->prepare('SELECT activo FROM profesores WHERE id=:p LIMIT 1 FOR UPDATE');
        $st->execute([':p'=>$sustitutoId]);$subActive=$st->fetchColumn();
        if($subActive===false)throw new HacheProfesorSustitucionException('Profesor sustituto no encontrado.',404);
        if((int)$subActive!==1)throw new HacheProfesorSustitucionException('El profesor sustituto debe estar activo.',409);

        $st=$pdo->prepare("SELECT 1
            FROM profesor_horarios ph
            JOIN profesor_horario_vigencias v ON v.profesor_horario_id=ph.id
            WHERE ph.profesor_id=:p AND ph.horario_id=:h
              AND v.vigente_desde<=:inicio1
              AND (v.vigente_hasta IS NULL OR v.vigente_hasta>=:inicio2)
            LIMIT 1");
        $st->execute([':p'=>$sustitutoId,':h'=>(string)$sesion['horario_id'],':inicio1'=>$sesionInicioUtc,':inicio2'=>$sesionInicioUtc]);
        if($st->fetchColumn())throw new HacheProfesorSustitucionException('Ese profesor ya estaba asignado regularmente a la sesión; no corresponde registrarlo como sustituto.',409);

        $st=$pdo->prepare("SELECT id FROM profesor_sustituciones WHERE sesion_id=:s AND profesor_original_id=:p AND estado='ACTIVA' LIMIT 1 FOR UPDATE");
        $st->execute([':s'=>$sesionId,':p'=>$originalId]);
        if($st->fetchColumn())throw new HacheProfesorSustitucionException('Ya existe una sustitución activa para ese profesor en la sesión.',409);

        $id=(string)$pdo->query('SELECT UUID()')->fetchColumn();
        $st=$pdo->prepare("INSERT INTO profesor_sustituciones(
            id,sesion_id,profesor_original_id,profesor_sustituto_id,motivo,origen,estado,created_by
        ) VALUES(:id,:s,:o,:r,:m,'ADMIN','ACTIVA',:u)");
        $st->execute([':id'=>$id,':s'=>$sesionId,':o'=>$originalId,':r'=>$sustitutoId,':m'=>$motivo,':u'=>$actorId]);

        if($owns)$pdo->commit();
        return $id;
    }catch(Throwable $e){
        if($owns&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

function hache_profesor_sustitucion_anular(PDO $pdo,string $id,string $motivo,string $actorId): void
{
    if(!hache_profesor_sustituciones_schema_ready($pdo)){
        throw new HacheProfesorSustitucionException('Falta aplicar la migración F7.2 de profesores.',503);
    }
    $id=trim($id);$motivo=preg_replace('/\s+/u',' ',trim($motivo))??'';
    if($id==='')throw new HacheProfesorSustitucionException('La sustitución es obligatoria.');
    if($motivo===''||mb_strlen($motivo)>500)throw new HacheProfesorSustitucionException('El motivo de anulación es obligatorio y debe tener máximo 500 caracteres.');

    $owns=!$pdo->inTransaction();
    if($owns)$pdo->beginTransaction();
    try{
        $st=$pdo->prepare("SELECT estado FROM profesor_sustituciones WHERE id=:id LIMIT 1 FOR UPDATE");
        $st->execute([':id'=>$id]);$estado=$st->fetchColumn();
        if($estado===false)throw new HacheProfesorSustitucionException('Sustitución no encontrada.',404);
        if((string)$estado!=='ACTIVA')throw new HacheProfesorSustitucionException('La sustitución ya no está activa.',409);

        $st=$pdo->prepare("UPDATE profesor_sustituciones
            SET estado='ANULADA',anulada_by=:u,anulada_at=UTC_TIMESTAMP(),motivo_anulacion=:m
            WHERE id=:id AND estado='ACTIVA'");
        $st->execute([':u'=>$actorId,':m'=>$motivo,':id'=>$id]);
        if($st->rowCount()!==1)throw new HacheProfesorSustitucionException('La sustitución cambió antes de anularse.',409);

        if($owns)$pdo->commit();
    }catch(Throwable $e){
        if($owns&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

/** @return array{cobertura_desde:string,sesiones:list<array<string,mixed>>,sustituciones:list<array<string,mixed>>} */
function hache_profesor_sustituciones_contexto(PDO $pdo,string $desde,string $hasta): array
{
    $coverage=(string)$pdo->query("SELECT valor FROM configuracion WHERE clave='profesores_sustituciones_cobertura_desde' LIMIT 1")->fetchColumn();

    $st=$pdo->prepare("SELECT se.id,se.fecha,se.estado,se.cerrada,h.id horario_id,h.hora_inicio,h.hora_fin,
        s.id sede_id,s.clave sede_clave,s.nombre sede_nombre
        FROM sesiones se
        JOIN horarios h ON h.id=se.horario_id
        JOIN sedes s ON s.id=h.sede_id
        WHERE se.fecha BETWEEN :d AND :h
        ORDER BY se.fecha,h.hora_inicio,s.nombre");
    $st->execute([':d'=>$desde,':h'=>$hasta]);$sesiones=$st->fetchAll(PDO::FETCH_ASSOC);

    $st=$pdo->prepare("SELECT se.id sesion_id,se.fecha,h.hora_inicio,p.id profesor_id,p.nombre profesor_nombre,
        v.vigente_desde,v.vigente_hasta
        FROM sesiones se
        JOIN horarios h ON h.id=se.horario_id
        JOIN profesor_horarios ph ON ph.horario_id=h.id
        JOIN profesor_horario_vigencias v ON v.profesor_horario_id=ph.id
        JOIN profesores p ON p.id=ph.profesor_id
        WHERE se.fecha BETWEEN :d AND :h
        ORDER BY se.fecha,h.hora_inicio,p.nombre");
    $st->execute([':d'=>$desde,':h'=>$hasta]);$assigned=[];
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){
        $startUtc=hache_profesor_sesion_inicio_utc((string)$row['fecha'],(string)$row['hora_inicio']);
        if((string)$row['vigente_desde']>$startUtc)continue;
        if($row['vigente_hasta']!==null&&(string)$row['vigente_hasta']<$startUtc)continue;
        $assigned[(string)$row['sesion_id']][]=['id'=>(string)$row['profesor_id'],'nombre'=>(string)$row['profesor_nombre']];
    }
    foreach($sesiones as &$sesion)$sesion['profesores_asignados']=$assigned[(string)$sesion['id']]??[];
    unset($sesion);

    $st=$pdo->prepare("SELECT ps.id,ps.sesion_id,ps.profesor_original_id,po.nombre profesor_original_nombre,
        ps.profesor_sustituto_id,pr.nombre profesor_sustituto_nombre,ps.motivo,ps.origen,ps.estado,
        ps.created_at,uc.usuario created_by_usuario,ps.anulada_at,ua.usuario anulada_by_usuario,ps.motivo_anulacion,
        se.fecha,h.hora_inicio,h.hora_fin,s.clave sede_clave,s.nombre sede_nombre
        FROM profesor_sustituciones ps
        JOIN sesiones se ON se.id=ps.sesion_id
        JOIN horarios h ON h.id=se.horario_id
        JOIN sedes s ON s.id=h.sede_id
        JOIN profesores po ON po.id=ps.profesor_original_id
        JOIN profesores pr ON pr.id=ps.profesor_sustituto_id
        LEFT JOIN usuarios uc ON uc.id=ps.created_by
        LEFT JOIN usuarios ua ON ua.id=ps.anulada_by
        WHERE se.fecha BETWEEN :d AND :h
        ORDER BY se.fecha DESC,h.hora_inicio DESC,ps.created_at DESC");
    $st->execute([':d'=>$desde,':h'=>$hasta]);

    return ['cobertura_desde'=>$coverage,'sesiones'=>$sesiones,'sustituciones'=>$st->fetchAll(PDO::FETCH_ASSOC)];
}
