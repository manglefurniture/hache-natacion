<?php

declare(strict_types=1);

require_once __DIR__.'/sharky-business-actions.php';

/**
 * Reconciles an intensive registration that may already have committed in a
 * previous worker after its action lease expired. Identity serialization and
 * reconciliation intentionally share the same transaction/identity lock.
 */
function hache_sharky_registration_recover_locked(PDO $pdo,string $contact,array $action): ?array
{
    $digits=preg_replace('/\D+/','',$contact)?:'';
    if(strlen($digits)===13&&str_starts_with($digits,'521'))$digits='52'.substr($digits,3);
    $phone='+'.$digits;
    if(!telefono_es_e164($phone))return null;

    $name=preg_replace('/\s+/u',' ',trim((string)($action['name']??'')))??'';
    $sede=strtoupper(trim((string)($action['sede_clave']??'')));
    $date=trim((string)($action['fecha_inicio']??''));
    $scheduleId=trim((string)($action['schedule_id']??''));
    if($name===''||$sede===''||$date===''||$scheduleId==='')return null;

    $pdo->beginTransaction();
    try{
        // Same global identity lock used by all student creation paths. A worker
        // that stole the action lease cannot make a stale precheck authoritative.
        regla_bloquear_identidades_alumnos($pdo);
        $st=$pdo->prepare("SELECT a.id student_id,ci.id course_id,ci.precio,s.clave sede_clave,s.nombre sede_nombre,ci.fecha_inicio,h.hora_inicio,h.hora_fin,u.id portal_user_id,u.usuario username FROM alumnos a JOIN sedes s ON s.id=a.sede_id JOIN curso_intensivo_alumnos cia ON cia.alumno_id=a.id JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id JOIN horarios h ON h.id=cia.horario_id JOIN usuarios u ON u.alumno_id=a.id AND u.rol='ALUMNO' AND u.activo=1 WHERE a.whatsapp=:w AND a.nombre=:n AND s.clave=:s AND ci.fecha_inicio=:f AND cia.horario_id=:h LIMIT 1 FOR UPDATE");
        $st->execute([':w'=>$phone,':n'=>$name,':s'=>$sede,':f'=>$date,':h'=>$scheduleId]);
        $row=$st->fetch(PDO::FETCH_ASSOC);
        if(!$row){$pdo->commit();return null;}

        // The original temporary credential may have been created by a worker
        // that lost ownership before durable delivery. Rotate it while the same
        // identity lock is held so only the reconciled owner can deliver a valid one.
        $temporaryPassword=password_temporal_segura();
        $passwordHash=password_hash($temporaryPassword,PASSWORD_DEFAULT);
        $st=$pdo->prepare('UPDATE usuarios SET password_hash=:p,debe_cambiar_password=1 WHERE id=:u AND alumno_id=:a');
        $st->execute([':p'=>$passwordHash,':u'=>(string)$row['portal_user_id'],':a'=>(string)$row['student_id']]);
        if($st->rowCount()!==1)throw new RuntimeException('Unable to rotate recovered portal credential');
        $pdo->commit();

        return [
            'ok'=>true,'code'=>'RECOVERED','recovered'=>true,
            'student_id'=>(string)$row['student_id'],'course_id'=>(string)$row['course_id'],
            'username'=>(string)$row['username'],'temporary_password'=>$temporaryPassword,
            'sede_clave'=>(string)$row['sede_clave'],'sede_nombre'=>(string)$row['sede_nombre'],
            'fecha_inicio'=>(string)$row['fecha_inicio'],
            'schedule_label'=>substr((string)$row['hora_inicio'],0,5).'–'.substr((string)$row['hora_fin'],0,5),
            'price'=>(float)$row['precio'],
            'status'=>'PENDIENTE',
        ];
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}
