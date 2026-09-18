<?php

declare(strict_types=1);

final class HacheProfesoresAssignmentException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus=422)
    {
        parent::__construct($message);
    }
}

function hache_profesores_vigencias_schema_ready(PDO $pdo): bool
{
    try{
        $st=$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='profesor_horario_vigencias'");
        if((int)$st->fetchColumn()!==1)return false;
        $st=$pdo->query("SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') cols,MIN(non_unique) non_unique FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='profesor_horario_vigencias' AND index_name='uq_profesor_horario_vigencia_abierta' GROUP BY index_name");
        $idx=$st->fetch(PDO::FETCH_ASSOC);
        if(!is_array($idx)||(string)($idx['cols']??'')!=='profesor_horario_id,abierta'||(int)($idx['non_unique']??1)!==0)return false;
        $st=$pdo->query("SELECT COUNT(*) FROM configuracion WHERE clave IN ('profesores_asignaciones_cobertura_desde','profesores_asignaciones_baseline_aplicado')");
        if((int)$st->fetchColumn()!==2)return false;
        $st=$pdo->query("SELECT COUNT(*) FROM profesor_horarios ph JOIN profesores p ON p.id=ph.profesor_id WHERE ph.activo=1 AND p.activo=0");
        if((int)$st->fetchColumn()!==0)return false;
        $st=$pdo->query("SELECT COUNT(*) FROM profesor_horario_vigencias v JOIN profesor_horarios ph ON ph.id=v.profesor_horario_id WHERE ph.activo=0 AND v.vigente_hasta IS NULL");
        return (int)$st->fetchColumn()===0;
    }catch(Throwable $e){return false;}
}

function hache_profesores_reconciliar_inactivos(PDO $pdo): int
{
    $changed=0;

    $st=$pdo->query("SELECT v.id,v.vigente_desde
        FROM profesor_horario_vigencias v
        JOIN profesor_horarios ph ON ph.id=v.profesor_horario_id
        JOIN profesores p ON p.id=ph.profesor_id
        WHERE p.activo=0
          AND v.vigente_hasta IS NULL
          AND v.origen='F7_BASELINE'
        ORDER BY v.id");
    $baselines=$st->fetchAll(PDO::FETCH_ASSOC);
    if($baselines){
        $update=$pdo->prepare("UPDATE profesor_horario_vigencias
            SET vigente_hasta=:hasta,closed_by=NULL
            WHERE id=:id AND vigente_hasta IS NULL AND origen='F7_BASELINE'");
        foreach($baselines as $row){
            $update->execute([':hasta'=>(string)$row['vigente_desde'],':id'=>(string)$row['id']]);
            $changed+=$update->rowCount();
        }
    }

    $st=$pdo->query("SELECT ph.id
        FROM profesor_horarios ph
        JOIN profesores p ON p.id=ph.profesor_id
        WHERE ph.activo=1 AND p.activo=0
        ORDER BY ph.id");
    $assignmentIds=array_values(array_map('strval',$st->fetchAll(PDO::FETCH_COLUMN)));
    if($assignmentIds){
        $update=$pdo->prepare("UPDATE profesor_horarios
            SET activo=0,updated_at=UTC_TIMESTAMP()
            WHERE id=:id AND activo=1");
        foreach($assignmentIds as $assignmentId){
            $update->execute([':id'=>$assignmentId]);
            $changed+=$update->rowCount();
        }
    }

    return $changed;
}

function hache_profesores_vigencia_abrir(PDO $pdo,string $assignmentId,?string $actorId): void
{
    $st=$pdo->prepare("INSERT INTO profesor_horario_vigencias(id,profesor_horario_id,vigente_desde,origen,created_by)
        SELECT UUID(),ph.id,UTC_TIMESTAMP(),'ADMIN',:u
        FROM profesor_horarios ph
        WHERE ph.id=:id AND ph.activo=1
          AND NOT EXISTS(
            SELECT 1 FROM profesor_horario_vigencias v
            WHERE v.profesor_horario_id=ph.id AND v.vigente_hasta IS NULL
          )");
    $st->execute([':u'=>$actorId,':id'=>$assignmentId]);
}

function hache_profesores_vigencia_cerrar(PDO $pdo,string $assignmentId,?string $actorId): void
{
    $st=$pdo->prepare("UPDATE profesor_horario_vigencias
        SET vigente_hasta=UTC_TIMESTAMP(),closed_by=:u
        WHERE profesor_horario_id=:id AND vigente_hasta IS NULL");
    $st->execute([':u'=>$actorId,':id'=>$assignmentId]);
}

function hache_profesores_asignacion_set(PDO $pdo,string $profesorId,string $horarioId,bool $activo,?string $actorId): string
{
    if(!hache_profesores_vigencias_schema_ready($pdo)){
        throw new HacheProfesoresAssignmentException('Falta aplicar la migración F7.1 de profesores.',503);
    }
    $owns=!$pdo->inTransaction();
    if($owns)$pdo->beginTransaction();
    try{
        $st=$pdo->prepare('SELECT activo FROM profesores WHERE id=:p LIMIT 1 FOR UPDATE');
        $st->execute([':p'=>$profesorId]);$teacherActive=$st->fetchColumn();
        if($teacherActive===false)throw new HacheProfesoresAssignmentException('Profesor no encontrado.');
        if($activo&&(int)$teacherActive!==1)throw new HacheProfesoresAssignmentException('No puedes asignar horarios a un profesor inactivo.');

        $st=$pdo->prepare('SELECT activo FROM horarios WHERE id=:h LIMIT 1 FOR UPDATE');
        $st->execute([':h'=>$horarioId]);$scheduleActive=$st->fetchColumn();
        if($scheduleActive===false)throw new HacheProfesoresAssignmentException('Horario no encontrado.');
        if($activo&&(int)$scheduleActive!==1)throw new HacheProfesoresAssignmentException('No puedes asignar un horario inactivo.');

        $st=$pdo->prepare('SELECT id FROM profesor_horarios WHERE profesor_id=:p AND horario_id=:h LIMIT 1 FOR UPDATE');
        $st->execute([':p'=>$profesorId,':h'=>$horarioId]);$assignmentId=$st->fetchColumn();
        if($assignmentId===false){
            $assignmentId=(string)$pdo->query('SELECT UUID()')->fetchColumn();
            $st=$pdo->prepare('INSERT INTO profesor_horarios(id,profesor_id,horario_id,activo,created_by) VALUES(:id,:p,:h,:a,:u)');
            $st->execute([':id'=>$assignmentId,':p'=>$profesorId,':h'=>$horarioId,':a'=>$activo?1:0,':u'=>$actorId]);
        }else{
            $assignmentId=(string)$assignmentId;
            $st=$pdo->prepare('UPDATE profesor_horarios SET activo=:a,updated_at=UTC_TIMESTAMP() WHERE id=:id');
            $st->execute([':a'=>$activo?1:0,':id'=>$assignmentId]);
        }

        if($activo)hache_profesores_vigencia_abrir($pdo,$assignmentId,$actorId);
        else hache_profesores_vigencia_cerrar($pdo,$assignmentId,$actorId);

        if($owns)$pdo->commit();
        return $assignmentId;
    }catch(Throwable $e){
        if($owns&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

function hache_profesores_cerrar_asignaciones_profesor(PDO $pdo,string $profesorId,?string $actorId): int
{
    if(!hache_profesores_vigencias_schema_ready($pdo)){
        throw new HacheProfesoresAssignmentException('Falta aplicar la migración F7.1 de profesores.',503);
    }
    $owns=!$pdo->inTransaction();
    if($owns)$pdo->beginTransaction();
    try{
        $st=$pdo->prepare('SELECT id FROM profesor_horarios WHERE profesor_id=:p AND activo=1 FOR UPDATE');
        $st->execute([':p'=>$profesorId]);$ids=array_values(array_map('strval',$st->fetchAll(PDO::FETCH_COLUMN)));
        if(!$ids){
            if($owns)$pdo->commit();
            return 0;
        }

        $st=$pdo->prepare('UPDATE profesor_horarios SET activo=0,updated_at=UTC_TIMESTAMP() WHERE profesor_id=:p AND activo=1');
        $st->execute([':p'=>$profesorId]);$changed=$st->rowCount();

        $st=$pdo->prepare("UPDATE profesor_horario_vigencias v
            JOIN profesor_horarios ph ON ph.id=v.profesor_horario_id
            SET v.vigente_hasta=UTC_TIMESTAMP(),v.closed_by=:u
            WHERE ph.profesor_id=:p AND v.vigente_hasta IS NULL");
        $st->execute([':u'=>$actorId,':p'=>$profesorId]);
        if($owns)$pdo->commit();
        return $changed;
    }catch(Throwable $e){
        if($owns&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}
