<?php

declare(strict_types=1);

require_once __DIR__.'/sharky-orchestrator-store.php';
require_once __DIR__.'/sharky-business-actions.php';
require_once __DIR__.'/sharky-identity-verification.php';
require_once __DIR__.'/sharky-action-recovery.php';

function hache_sharky_db_state_ready(PDO $pdo): bool
{
    try{$st=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:t');$st->execute([':t'=>'sharky_conversation_state']);return(int)$st->fetchColumn()===1;}catch(Throwable $e){return false;}
}

function hache_sharky_db_state_load(PDO $pdo,string $contact): array
{
    if(!hache_sharky_db_state_ready($pdo))return hache_sharky_orchestrator_state_load($contact);$hash=hache_sharky_orchestrator_contact_hash($contact);
    try{$st=$pdo->prepare('SELECT state_json,expires_at FROM sharky_conversation_state WHERE contact_hash=:c LIMIT 1');$st->execute([':c'=>$hash]);$row=$st->fetch(PDO::FETCH_ASSOC);if(!$row)return hache_sharky_orchestrator_state();if(strtotime((string)$row['expires_at'])<time()){$pdo->prepare('DELETE FROM sharky_conversation_state WHERE contact_hash=:c')->execute([':c'=>$hash]);return hache_sharky_orchestrator_state();}$decoded=json_decode((string)$row['state_json'],true);return hache_sharky_orchestrator_state(is_array($decoded)?$decoded:null);}catch(Throwable $e){error_log('[sharky-orchestrator] db state load failed');return hache_sharky_orchestrator_state_load($contact);}
}

function hache_sharky_db_state_save(PDO $pdo,string $contact,array $state,int $ttl=86400): bool
{
    $ttl=max(HACHE_SHARKY_FLOW_TTL,min(172800,$ttl));if(!hache_sharky_db_state_ready($pdo))return hache_sharky_orchestrator_state_save($contact,$state);$hash=hache_sharky_orchestrator_contact_hash($contact);$json=json_encode($state,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($json===false)return false;$expires=(new DateTimeImmutable())->modify('+'.$ttl.' seconds')->format('Y-m-d H:i:s');
    try{$st=$pdo->prepare('INSERT INTO sharky_conversation_state(contact_hash,state_json,expires_at) VALUES(:c,:s,:e) ON DUPLICATE KEY UPDATE state_json=VALUES(state_json),expires_at=VALUES(expires_at),updated_at=NOW()');$st->execute([':c'=>$hash,':s'=>$json,':e'=>$expires]);return true;}catch(Throwable $e){error_log('[sharky-orchestrator] db state save failed');return hache_sharky_orchestrator_state_save($contact,$state);}
}

function hache_sharky_action_status(PDO $pdo,string $idempotencyKey): ?array
{
    return hache_sharky_action_recovery_status($pdo,$idempotencyKey);
}

function hache_sharky_recover_intensive(PDO $pdo,array $fresh,array $action): ?array
{
    if(($fresh['found']??false)!==true)return null;
    $st=$pdo->prepare("SELECT a.id student_id,ci.id course_id,ci.precio,s.clave sede_clave,s.nombre sede_nombre,ci.fecha_inicio,h.hora_inicio,h.hora_fin FROM alumnos a JOIN sedes s ON s.id=a.sede_id JOIN curso_intensivo_alumnos cia ON cia.alumno_id=a.id JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id JOIN horarios h ON h.id=cia.horario_id WHERE a.id=:a AND a.nombre=:n AND s.clave=:s AND ci.fecha_inicio=:f AND cia.horario_id=:h LIMIT 1");
    $st->execute([':a'=>(string)$fresh['student_id'],':n'=>(string)($action['name']??''),':s'=>strtoupper((string)($action['sede_clave']??'')),':f'=>(string)($action['fecha_inicio']??''),':h'=>(string)($action['schedule_id']??'')]);$row=$st->fetch(PDO::FETCH_ASSOC);if(!$row)return null;
    return ['ok'=>true,'code'=>'CREATED','recovered'=>true,'student_id'=>(string)$row['student_id'],'course_id'=>(string)$row['course_id'],'sede_clave'=>(string)$row['sede_clave'],'sede_nombre'=>(string)$row['sede_nombre'],'fecha_inicio'=>(string)$row['fecha_inicio'],'schedule_label'=>substr((string)$row['hora_inicio'],0,5).'–'.substr((string)$row['hora_fin'],0,5),'price'=>(float)$row['precio']];
}

function hache_sharky_execute_action(PDO $pdo,string $contact,array $action,string $idempotencyKey,array $context=[]): array
{
    $type=trim((string)($action['type']??''));$contactHash=hache_sharky_orchestrator_contact_hash($contact);$studentId=isset($action['student_id'])?trim((string)$action['student_id']):null;
    if($type==='')return ['ok'=>false,'code'=>'NO_ACTION','message'=>'No hay una acción válida para ejecutar.'];

    $existing=hache_sharky_action_status($pdo,$idempotencyKey);
    if($existing&&(string)$existing['status']==='COMPLETED')return ['ok'=>true,'duplicate'=>true,'code'=>(string)($existing['result_code']??'ALREADY_COMPLETED'),'message'=>trim((string)($existing['result_message']??''))?:'Esta operación ya había sido procesada.','result'=>is_array($existing['result']??null)?$existing['result']:null];
    if(hache_sharky_action_lease_active($existing))return ['ok'=>false,'retryable'=>true,'code'=>'ACTION_IN_PROGRESS','message'=>'La operación todavía se está procesando.'];
    if($existing&&(string)$existing['status']==='FAILED')return ['ok'=>false,'retryable'=>false,'code'=>'ACTION_ALREADY_FAILED','message'=>'La operación anterior falló y requiere una nueva confirmación.'];

    if(!hache_sharky_action_recovery_claim($pdo,$idempotencyKey,$type,$contactHash,$studentId,$action))return ['ok'=>false,'retryable'=>true,'code'=>'ACTION_CLAIM_FAILED','message'=>'No pude asegurar la operación. No se realizó ningún cambio.'];

    try{
        if(($action['requires_revalidation']??false)!==true)throw new HacheSharkyBusinessException('La operación no pasó la revalidación obligatoria.','REVALIDATION_REQUIRED',409);
        if($type==='create_absence'){
            $identity=hache_sharky_business_identity_by_whatsapp($pdo,$contact);$verified=$context['verification']??null;$allowedStudent=null;
            if(($identity['found']??false)===true)$allowedStudent=(string)$identity['student_id'];elseif(is_array($verified)&&($verified['verified']??false)===true)$allowedStudent=(string)($verified['student_id']??'');
            if($allowedStudent===''||$allowedStudent!==$studentId)throw new HacheSharkyBusinessException('No pude revalidar la identidad del alumno.','IDENTITY_MISMATCH',403);
            $result=hache_sharky_business_create_absence($pdo,$action,null,$context['today']??null);$code=(string)($result['code']??'CREATED');$message=($result['duplicate']??false)?'Esa ausencia ya estaba registrada; no la dupliqué.':'Listo. Tu ausencia quedó registrada.';
            hache_sharky_action_recovery_finish($pdo,$idempotencyKey,true,$code,$result,$message);return ['ok'=>true,'code'=>$code,'message'=>$message,'result'=>$result];
        }
        if($type==='register_intensive'){
            $fresh=hache_sharky_business_identity_by_whatsapp($pdo,$contact);
            if(($fresh['found']??false)===true){$recovered=hache_sharky_recover_intensive($pdo,$fresh,$action);if($recovered!==null){$message='Listo. Tu registro fue recibido y quedó pendiente de confirmación/pago.';hache_sharky_action_recovery_finish($pdo,$idempotencyKey,true,'RECOVERED',$recovered,$message);return ['ok'=>true,'duplicate'=>true,'code'=>'RECOVERED','message'=>$message,'result'=>$recovered];}throw new HacheSharkyBusinessException('Este WhatsApp ya pertenece a un alumno registrado.','PHONE_ALREADY_REGISTERED',409);}
            $result=hache_sharky_business_register_intensive($pdo,$action,null,(int)($context['min_age']??12),$context['today']??null);$message='Listo. Tu registro fue recibido y quedó pendiente de confirmación/pago.';
            hache_sharky_action_recovery_finish($pdo,$idempotencyKey,true,(string)($result['code']??'CREATED'),$result,$message);return ['ok'=>true,'code'=>(string)($result['code']??'CREATED'),'message'=>$message,'result'=>$result];
        }
        if($type==='human_takeover'){$message='La conversación quedó en manos del equipo.';hache_sharky_action_recovery_finish($pdo,$idempotencyKey,true,'HANDOFF',null,$message);return ['ok'=>true,'code'=>'HANDOFF','message'=>$message];}
        throw new HacheSharkyBusinessException('La acción solicitada no está habilitada.','ACTION_NOT_ALLOWED',422);
    }catch(HacheSharkyBusinessException $e){hache_sharky_action_recovery_finish($pdo,$idempotencyKey,false,$e->codeName,null,$e->getMessage());return ['ok'=>false,'retryable'=>false,'code'=>$e->codeName,'message'=>$e->getMessage(),'http_status'=>$e->httpStatus];}
    catch(Throwable $e){hache_sharky_action_recovery_finish($pdo,$idempotencyKey,false,'INTERNAL_ERROR',null,'No pude completar la operación. No se confirmó ningún cambio.');error_log('[sharky-orchestrator] action execution failed: '.$e->getMessage());return ['ok'=>false,'retryable'=>true,'code'=>'INTERNAL_ERROR','message'=>'No pude completar la operación. No se confirmó ningún cambio.'];}
}
