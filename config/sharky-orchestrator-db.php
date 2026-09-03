<?php

declare(strict_types=1);

require_once __DIR__.'/sharky-orchestrator-store.php';
require_once __DIR__.'/sharky-business-actions.php';
require_once __DIR__.'/sharky-identity-verification.php';
require_once __DIR__.'/sharky-action-recovery.php';
require_once __DIR__.'/sharky-start-authority.php';
require_once __DIR__.'/sharky-registration-recovery.php';

function hache_sharky_db_state_key(): string
{
    $secret=hache_sharky_orchestrator_secret('SHARKY_STATE_ENCRYPTION_KEY');
    if(strlen($secret)<32){
        if(PHP_SAPI==='cli')$secret='hache-sharky-state-cli-regression-key-2026';
        else throw new RuntimeException('SHARKY_STATE_ENCRYPTION_KEY is required before enabling Sharky 2.0');
    }
    return hash_hmac('sha256','hache-sharky-state-v1',$secret,true);
}

function hache_sharky_db_state_ready(PDO $pdo): bool
{
    try{
        $st=$pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='sharky_conversation_state'");
        $st->execute();
        $columns=array_fill_keys(array_map('strval',$st->fetchAll(PDO::FETCH_COLUMN)),true);
        foreach(['contact_hash','state_ciphertext','state_iv','state_tag','expires_at'] as $column)if(!isset($columns[$column]))return false;
        return true;
    }catch(Throwable $e){return false;}
}

function hache_sharky_db_state_encrypt(array $state): array
{
    $json=json_encode($state,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if($json===false)throw new RuntimeException('Unable to encode durable Sharky state');
    $iv=random_bytes(12);$tag='';
    $cipher=openssl_encrypt($json,'aes-256-gcm',hache_sharky_db_state_key(),OPENSSL_RAW_DATA,$iv,$tag,'sharky-state-v1');
    if(!is_string($cipher)||strlen($tag)!==16)throw new RuntimeException('Unable to encrypt durable Sharky state');
    return ['ciphertext'=>base64_encode($cipher),'iv'=>base64_encode($iv),'tag'=>base64_encode($tag)];
}

function hache_sharky_db_state_decrypt(array $row): ?array
{
    $cipher=base64_decode((string)($row['state_ciphertext']??''),true);
    $iv=base64_decode((string)($row['state_iv']??''),true);
    $tag=base64_decode((string)($row['state_tag']??''),true);
    if(!is_string($cipher)||!is_string($iv)||!is_string($tag)||strlen($iv)!==12||strlen($tag)!==16)return null;
    $json=openssl_decrypt($cipher,'aes-256-gcm',hache_sharky_db_state_key(),OPENSSL_RAW_DATA,$iv,$tag,'sharky-state-v1');
    if(!is_string($json))return null;
    $decoded=json_decode($json,true);
    return is_array($decoded)?$decoded:null;
}

function hache_sharky_db_state_purge_expired(PDO $pdo,int $limit=100): int
{
    $limit=max(1,min(1000,$limit));
    try{return (int)$pdo->exec('DELETE FROM sharky_conversation_state WHERE expires_at<NOW() LIMIT '.$limit);}
    catch(Throwable $e){error_log('[sharky-orchestrator] expired state purge failed');return 0;}
}

function hache_sharky_db_state_load(PDO $pdo,string $contact): array
{
    if(!hache_sharky_db_state_ready($pdo))throw new RuntimeException('Sharky conversation state storage is unavailable');
    $hash=hache_sharky_orchestrator_contact_hash($contact);
    try{
        hache_sharky_db_state_purge_expired($pdo);
        $st=$pdo->prepare('SELECT state_json,state_ciphertext,state_iv,state_tag,expires_at FROM sharky_conversation_state WHERE contact_hash=:c LIMIT 1');
        $st->execute([':c'=>$hash]);$row=$st->fetch(PDO::FETCH_ASSOC);
        if(!$row)return hache_sharky_orchestrator_state();
        if(strtotime((string)$row['expires_at'])<time()){
            $pdo->prepare('DELETE FROM sharky_conversation_state WHERE contact_hash=:c')->execute([':c'=>$hash]);
            return hache_sharky_orchestrator_state();
        }

        $decoded=null;
        if(trim((string)($row['state_ciphertext']??''))!==''){
            $decoded=hache_sharky_db_state_decrypt($row);
            if(!is_array($decoded))throw new RuntimeException('Unable to decrypt durable Sharky state');
        }elseif(trim((string)($row['state_json']??''))!==''){
            // One-time compatibility path for staging databases created before state
            // encryption existed. Read the legacy JSON, immediately reseal it and
            // clear the plaintext column.
            $legacy=json_decode((string)$row['state_json'],true);
            if(!is_array($legacy))throw new RuntimeException('Invalid legacy durable Sharky state');
            $decoded=$legacy;
            hache_sharky_db_state_save_now($pdo,$contact,$legacy,max(HACHE_SHARKY_FLOW_TTL,(int)max(1,strtotime((string)$row['expires_at'])-time())));
        }
        return hache_sharky_orchestrator_state(is_array($decoded)?$decoded:null);
    }catch(Throwable $e){error_log('[sharky-orchestrator] db state load failed');throw new RuntimeException('Unable to load durable Sharky state',0,$e);}
}

function hache_sharky_db_state_defer_begin(): void
{
    $GLOBALS['hache_sharky_db_state_deferred']=true;
    $GLOBALS['hache_sharky_db_state_pending']=null;
}

function hache_sharky_db_state_defer_take(): ?array
{
    $pending=$GLOBALS['hache_sharky_db_state_pending']??null;
    $GLOBALS['hache_sharky_db_state_deferred']=false;
    $GLOBALS['hache_sharky_db_state_pending']=null;
    return is_array($pending)?$pending:null;
}

function hache_sharky_db_state_defer_cancel(): void
{
    $GLOBALS['hache_sharky_db_state_deferred']=false;
    $GLOBALS['hache_sharky_db_state_pending']=null;
}

function hache_sharky_db_state_save_now(PDO $pdo,string $contact,array $state,int $ttl=86400): bool
{
    $ttl=max(HACHE_SHARKY_FLOW_TTL,min(172800,$ttl));
    if(!hache_sharky_db_state_ready($pdo))throw new RuntimeException('Sharky conversation state storage is unavailable');
    $hash=hache_sharky_orchestrator_contact_hash($contact);$sealed=hache_sharky_db_state_encrypt($state);
    $expires=(new DateTimeImmutable())->modify('+'.$ttl.' seconds')->format('Y-m-d H:i:s');
    try{
        hache_sharky_db_state_purge_expired($pdo);
        $st=$pdo->prepare('INSERT INTO sharky_conversation_state(contact_hash,state_json,state_ciphertext,state_iv,state_tag,expires_at) VALUES(:c,NULL,:s,:iv,:tag,:e) ON DUPLICATE KEY UPDATE state_json=NULL,state_ciphertext=VALUES(state_ciphertext),state_iv=VALUES(state_iv),state_tag=VALUES(state_tag),expires_at=VALUES(expires_at),updated_at=NOW()');
        $st->execute([':c'=>$hash,':s'=>$sealed['ciphertext'],':iv'=>$sealed['iv'],':tag'=>$sealed['tag'],':e'=>$expires]);
        return true;
    }catch(Throwable $e){error_log('[sharky-orchestrator] db state save failed');throw new RuntimeException('Unable to save durable Sharky state',0,$e);}
}

function hache_sharky_db_state_save(PDO $pdo,string $contact,array $state,int $ttl=86400): bool
{
    if(($GLOBALS['hache_sharky_db_state_deferred']??false)===true){
        $GLOBALS['hache_sharky_db_state_pending']=['contact'=>$contact,'state'=>$state,'ttl'=>$ttl];
        return true;
    }
    return hache_sharky_db_state_save_now($pdo,$contact,$state,$ttl);
}

function hache_sharky_action_status(PDO $pdo,string $idempotencyKey): ?array
{
    return hache_sharky_action_recovery_status($pdo,$idempotencyKey);
}

function hache_sharky_action_audit_pending_result(): array
{
    return ['ok'=>false,'retryable'=>true,'code'=>'ACTION_AUDIT_PENDING','message'=>'La operación quedó pendiente de reconciliación segura. No la repitas manualmente; el sistema la recuperará.'];
}

function hache_sharky_execute_action(PDO $pdo,string $contact,array $action,string $idempotencyKey,array $context=[]): array
{
    $type=trim((string)($action['type']??''));$contactHash=hache_sharky_orchestrator_contact_hash($contact);$studentId=isset($action['student_id'])?trim((string)$action['student_id']):null;
    if($type==='')return ['ok'=>false,'code'=>'NO_ACTION','message'=>'No hay una acción válida para ejecutar.'];

    $existing=hache_sharky_action_status($pdo,$idempotencyKey);
    if($existing&&(string)$existing['status']==='COMPLETED')return ['ok'=>true,'duplicate'=>true,'code'=>(string)($existing['result_code']??'ALREADY_COMPLETED'),'message'=>trim((string)($existing['result_message']??''))?:'Esta operación ya había sido procesada.','result'=>is_array($existing['result']??null)?$existing['result']:null];
    if(hache_sharky_action_lease_active($existing))return ['ok'=>false,'retryable'=>true,'code'=>'ACTION_IN_PROGRESS','message'=>'La operación todavía se está procesando.'];
    if($existing&&(string)$existing['status']==='FAILED')return ['ok'=>false,'retryable'=>false,'code'=>'ACTION_ALREADY_FAILED','message'=>'La operación anterior falló y requiere una nueva confirmación.'];

    $ownerToken=null;
    if(!hache_sharky_action_recovery_claim($pdo,$idempotencyKey,$type,$contactHash,$studentId,$action,$ownerToken)||!is_string($ownerToken)||$ownerToken==='')return ['ok'=>false,'retryable'=>true,'code'=>'ACTION_CLAIM_FAILED','message'=>'No pude asegurar la operación. No se realizó ningún cambio.'];

    try{
        if(($action['requires_revalidation']??false)!==true)throw new HacheSharkyBusinessException('La operación no pasó la revalidación obligatoria.','REVALIDATION_REQUIRED',409);
        if($type==='create_absence'){
            $identity=hache_sharky_business_identity_by_whatsapp($pdo,$contact);$verified=$context['verification']??null;$allowedStudent=null;
            if(($identity['found']??false)===true)$allowedStudent=(string)$identity['student_id'];elseif(is_array($verified)&&($verified['verified']??false)===true)$allowedStudent=(string)($verified['student_id']??'');
            if($allowedStudent===''||$allowedStudent!==$studentId)throw new HacheSharkyBusinessException('No pude revalidar la identidad del alumno.','IDENTITY_MISMATCH',403);
            $result=hache_sharky_business_create_absence($pdo,$action,null,$context['today']??null);$code=(string)($result['code']??'CREATED');$message=($result['duplicate']??false)?'Esa ausencia ya estaba registrada; no la dupliqué.':'Listo. Tu ausencia quedó registrada.';
            if(!hache_sharky_action_recovery_finish($pdo,$idempotencyKey,true,$code,$result,$message,$ownerToken))return hache_sharky_action_audit_pending_result();
            return ['ok'=>true,'code'=>$code,'message'=>$message,'result'=>$result];
        }
        if($type==='register_intensive'){
            $startDate=trim((string)($action['fecha_inicio']??''));
            if(!hache_sharky_start_authority_intensive_date_allowed($startDate,isset($context['today'])?(string)$context['today']:null)){
                throw new HacheSharkyBusinessException('Los cursos intensivos comienzan los lunes. Una incorporación en otra fecha necesita autorización humana.','START_DATE_REQUIRES_HUMAN',409);
            }

            // No identity precheck is authoritative here. The business service owns
            // the identity lock. If another worker committed after this action lease
            // was stolen, PHONE_ALREADY_REGISTERED is reconciled under that same
            // identity serialization instead of being persisted as a false failure.
            try{
                $result=hache_sharky_business_register_intensive($pdo,$action,null,(int)($context['min_age']??12),$context['today']??null);
            }catch(HacheSharkyBusinessException $registrationError){
                if($registrationError->codeName!=='PHONE_ALREADY_REGISTERED')throw $registrationError;
                $result=hache_sharky_registration_recover_locked($pdo,$contact,$action);
                if($result===null)throw $registrationError;
            }

            $code=(string)($result['code']??'CREATED');
            $recovered=($result['recovered']??false)===true||$code==='RECOVERED';
            $message='Listo. Tu registro fue recibido y quedó pendiente de confirmación/pago.';
            if(!hache_sharky_action_recovery_finish($pdo,$idempotencyKey,true,$recovered?'RECOVERED':$code,$result,$message,$ownerToken))return hache_sharky_action_audit_pending_result();
            return ['ok'=>true,'duplicate'=>$recovered,'code'=>$recovered?'RECOVERED':$code,'message'=>$message,'result'=>$result];
        }
        if($type==='human_takeover'){
            $message='La conversación quedó en manos del equipo.';
            if(!hache_sharky_action_recovery_finish($pdo,$idempotencyKey,true,'HANDOFF',null,$message,$ownerToken))return hache_sharky_action_audit_pending_result();
            return ['ok'=>true,'code'=>'HANDOFF','message'=>$message];
        }
        throw new HacheSharkyBusinessException('La acción solicitada no está habilitada.','ACTION_NOT_ALLOWED',422);
    }catch(HacheSharkyBusinessException $e){
        if(!hache_sharky_action_recovery_finish($pdo,$idempotencyKey,false,$e->codeName,null,$e->getMessage(),$ownerToken))return hache_sharky_action_audit_pending_result();
        return ['ok'=>false,'retryable'=>false,'code'=>$e->codeName,'message'=>$e->getMessage(),'http_status'=>$e->httpStatus];
    }
    catch(Throwable $e){
        $finished=hache_sharky_action_recovery_finish($pdo,$idempotencyKey,false,'INTERNAL_ERROR',null,'No pude completar la operación. No se confirmó ningún cambio.',$ownerToken);
        error_log('[sharky-orchestrator] action execution failed: '.$e->getMessage());
        if(!$finished)return hache_sharky_action_audit_pending_result();
        return ['ok'=>false,'retryable'=>true,'code'=>'INTERNAL_ERROR','message'=>'No pude completar la operación. No se confirmó ningún cambio.'];
    }
}
