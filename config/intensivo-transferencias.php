<?php

declare(strict_types=1);

require_once __DIR__.'/intensivos-estado.php';

final class IntensivoTransferenciaException extends RuntimeException {}

/**
 * Valida la fecha solamente cuando existe una pertenencia a intensivo que
 * realmente debe sincronizarse. Los alumnos regulares pueden tener cualquier
 * fecha de inicio válida y no deben heredar las restricciones de los lunes.
 */
function intensivo_validar_fecha_transferencia(array $relaciones, ?string $fechaInicio): ?DateTimeImmutable
{
    if(!$relaciones) return null;

    $fechaInicio=trim((string)$fechaInicio);
    if($fechaInicio===''){
        throw new IntensivoTransferenciaException('La fecha de inicio es obligatoria mientras el alumno pertenezca a un curso intensivo activo.');
    }

    try{$fecha=intensivo_fecha_valida($fechaInicio);}catch(InvalidArgumentException $e){
        throw new IntensivoTransferenciaException('La nueva fecha del intensivo no es válida.',0,$e);
    }
    if((int)$fecha->format('N')!==1){
        throw new IntensivoTransferenciaException('Los cursos intensivos solo pueden iniciar en lunes.');
    }

    return $fecha;
}

/**
 * Sincroniza la pertenencia a un curso intensivo cuando un administrador
 * cambia la fecha de inicio del alumno desde su ficha.
 *
 * La operación solo mueve relaciones activas/no terminadas y se niega a
 * trasladar automáticamente historial académico o contable ya generado.
 */
function intensivo_transferir_por_fecha_edicion(
    PDO $pdo,
    string $alumnoId,
    string $sedeId,
    ?string $fechaInicio,
    string $actorId
): array {
    intensivos_reconciliar_estados_sede($pdo,$sedeId);

    $st=$pdo->prepare("SELECT cia.id AS relacion_id,cia.curso_intensivo_id,cia.horario_id,cia.reposiciones_justificadas,cia.reposiciones_cancelacion,cia.continua_regular,cia.plan_continuidad_id,ci.fecha_inicio,ci.fecha_fin,ci.precio,ci.estado,ci.created_by
        FROM curso_intensivo_alumnos cia
        INNER JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id
        WHERE cia.alumno_id=:a AND ci.sede_id=:s AND ci.fecha_fin>=CURDATE()
        ORDER BY ci.fecha_inicio ASC
        FOR UPDATE");
    $st->execute([':a'=>$alumnoId,':s'=>$sedeId]);$relaciones=$st->fetchAll();

    $fecha=intensivo_validar_fecha_transferencia($relaciones,$fechaInicio);
    if(!$relaciones) return ['aplica'=>false,'transferido'=>false];
    $fechaInicio=trim((string)$fechaInicio);

    foreach($relaciones as $rel){
        if((string)$rel['fecha_inicio']===$fechaInicio){
            // La relación ya apunta al curso correcto. Solo repara/sincroniza la
            // fecha: horario_preferido_id puede ser el horario regular elegido
            // para una continuidad y no debe reemplazarse por el del intensivo.
            $pdo->prepare("UPDATE alumnos SET fecha_inicio=:f,updated_at=NOW() WHERE id=:a AND sede_id=:s")
                ->execute([':f'=>$fechaInicio,':a'=>$alumnoId,':s'=>$sedeId]);
            return ['aplica'=>true,'transferido'=>false,'curso_intensivo_id'=>$rel['curso_intensivo_id']];
        }
    }
    if(count($relaciones)!==1){
        throw new IntensivoTransferenciaException('El alumno aparece en más de un intensivo activo. Corrige esa inconsistencia antes de cambiar la fecha.');
    }
    $actual=$relaciones[0];

    $st=$pdo->prepare("SELECT 1 FROM pagos WHERE alumno_id=:a AND intensivo_id=:c AND tipo='INTENSIVO' AND estado='VALIDO' LIMIT 1 FOR UPDATE");
    $st->execute([':a'=>$alumnoId,':c'=>$actual['curso_intensivo_id']]);
    if($st->fetchColumn()){
        throw new IntensivoTransferenciaException('No se cambió el curso: este alumno ya tiene un pago válido ligado al intensivo actual. Corrige primero el pago para no descuadrar la contabilidad.');
    }
    if((int)$actual['reposiciones_justificadas']>0||(int)$actual['reposiciones_cancelacion']>0||!empty($actual['continua_regular'])||!empty($actual['plan_continuidad_id'])){
        throw new IntensivoTransferenciaException('No se cambió el curso porque el intensivo actual ya tiene reposiciones o continuidad registrada. Haz la corrección desde el historial del alumno.');
    }

    $st=$pdo->prepare("SELECT 1 FROM ausencias WHERE alumno_id=:a AND intensivo_id=:c LIMIT 1 FOR UPDATE");
    $st->execute([':a'=>$alumnoId,':c'=>$actual['curso_intensivo_id']]);
    if($st->fetchColumn()){
        throw new IntensivoTransferenciaException('No se cambió el curso porque ya existen ausencias registradas en el intensivo actual.');
    }
    $st=$pdo->prepare("SELECT 1 FROM asistencias aa INNER JOIN sesiones se ON se.id=aa.sesion_id WHERE aa.alumno_id=:a AND se.horario_id=:h AND se.fecha BETWEEN :fi AND :ff LIMIT 1 FOR UPDATE");
    $st->execute([':a'=>$alumnoId,':h'=>$actual['horario_id'],':fi'=>$actual['fecha_inicio'],':ff'=>$actual['fecha_fin']]);
    if($st->fetchColumn()){
        throw new IntensivoTransferenciaException('No se cambió el curso porque ya existe asistencia registrada durante el intensivo actual.');
    }

    // La sede actúa como candado estable para evitar crear dos cursos para el
    // mismo lunes si dos correcciones administrativas ocurren a la vez.
    $st=$pdo->prepare("SELECT id FROM sedes WHERE id=:s LIMIT 1 FOR UPDATE");$st->execute([':s'=>$sedeId]);
    if(!$st->fetchColumn()) throw new IntensivoTransferenciaException('La sede activa ya no está disponible.');

    $st=$pdo->prepare("SELECT id,fecha_inicio,fecha_fin,precio,estado FROM cursos_intensivos WHERE sede_id=:s AND fecha_inicio=:f LIMIT 1 FOR UPDATE");
    $st->execute([':s'=>$sedeId,':f'=>$fechaInicio]);$destino=$st->fetch();$cursoCreado=false;
    if(!$destino){
        $cursoId=(string)$pdo->query('SELECT UUID()')->fetchColumn();
        $fechaFin=$fecha->modify('+18 days')->format('Y-m-d');
        $estado=intensivo_estado_por_fechas($fechaInicio,$fechaFin);
        if($estado==='TERMINADO') throw new IntensivoTransferenciaException('No se puede mover al alumno a un intensivo que ya terminó.');
        $precio=number_format((float)$actual['precio'],2,'.','');
        $st=$pdo->prepare("INSERT INTO cursos_intensivos(id,sede_id,fecha_inicio,fecha_fin,precio,estado,observaciones,created_by) VALUES(:id,:s,:fi,:ff,:p,:estado,:o,:u)");
        $st->execute([':id'=>$cursoId,':s'=>$sedeId,':fi'=>$fechaInicio,':ff'=>$fechaFin,':p'=>$precio,':estado'=>$estado,':o'=>'Creado automáticamente al transferir un alumno desde la edición administrativa.',':u'=>$actorId]);
        $destino=['id'=>$cursoId,'fecha_inicio'=>$fechaInicio,'fecha_fin'=>$fechaFin,'precio'=>$precio,'estado'=>$estado];
        $cursoCreado=true;
    }else{
        $estado=intensivo_estado_por_fechas((string)$destino['fecha_inicio'],(string)$destino['fecha_fin']);
        if($estado==='TERMINADO') throw new IntensivoTransferenciaException('No se puede mover al alumno a un intensivo que ya terminó.');
    }

    $st=$pdo->prepare("SELECT 1 FROM horarios WHERE id=:h AND sede_id=:s AND activo=1 AND intensivo=1 LIMIT 1 FOR UPDATE");
    $st->execute([':h'=>$actual['horario_id'],':s'=>$sedeId]);
    if(!$st->fetchColumn()) throw new IntensivoTransferenciaException('El horario del alumno ya no está habilitado para intensivos. Selecciona un horario válido antes de moverlo.');

    $st=$pdo->prepare("SELECT id FROM curso_intensivo_alumnos WHERE curso_intensivo_id=:c AND alumno_id=:a AND id<>:id LIMIT 1 FOR UPDATE");
    $st->execute([':c'=>$destino['id'],':a'=>$alumnoId,':id'=>$actual['relacion_id']]);
    if($st->fetchColumn()) throw new IntensivoTransferenciaException('El alumno ya pertenece al curso intensivo de la nueva fecha.');

    $st=$pdo->prepare("UPDATE curso_intensivo_alumnos SET curso_intensivo_id=:nuevo,observaciones=CONCAT(COALESCE(observaciones,''),CASE WHEN COALESCE(observaciones,'')='' THEN '' ELSE '\n' END,:nota) WHERE id=:id AND alumno_id=:a");
    $st->execute([':nuevo'=>$destino['id'],':nota'=>'Transferido administrativamente desde el intensivo del '.date('d/m/Y',strtotime((string)$actual['fecha_inicio'])).' al '.date('d/m/Y',strtotime($fechaInicio)).'.',':id'=>$actual['relacion_id'],':a'=>$alumnoId]);
    if($st->rowCount()!==1) throw new IntensivoTransferenciaException('No se pudo actualizar la pertenencia del alumno al nuevo intensivo.');

    // La transferencia conserva el horario del participante en la relación
    // cia. El horario_preferido_id del alumno pertenece a la capa regular y
    // puede contener una continuidad ya elegida, por lo que no se sobrescribe.
    $pdo->prepare("UPDATE alumnos SET fecha_inicio=:f,updated_at=NOW() WHERE id=:a AND sede_id=:s")
        ->execute([':f'=>$fechaInicio,':a'=>$alumnoId,':s'=>$sedeId]);

    return [
        'aplica'=>true,
        'transferido'=>true,
        'curso_anterior_id'=>$actual['curso_intensivo_id'],
        'curso_intensivo_id'=>$destino['id'],
        'curso_creado'=>$cursoCreado,
        'fecha_inicio'=>$fechaInicio,
    ];
}
