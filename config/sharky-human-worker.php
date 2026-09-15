<?php

declare(strict_types=1);

require_once __DIR__.'/sharky-lab-worker.php';
require_once __DIR__.'/sharky-human-grace-runtime.php';

function hache_sharky_human_cancel_automatic_outbox(PDO $pdo,string $contact): int
{
    if(!function_exists('hache_sharky_outbox_decrypt'))return 0;
    $cancelled=0;$hash=hache_sharky_orchestrator_contact_hash($contact);
    try{
        $st=$pdo->prepare("SELECT id,payload_ciphertext,payload_iv,payload_tag FROM sharky_outbox WHERE contact_hash=:c AND status='PENDING' ORDER BY created_at,id LIMIT 100");
        $st->execute([':c'=>$hash]);$update=$pdo->prepare("UPDATE sharky_outbox SET status='CANCELLED',lease_until=NULL,owner_token=NULL,last_error='HUMAN_INTERVENTION' WHERE id=:id AND status='PENDING'");
        foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){
            $payload=hache_sharky_outbox_decrypt($row);if(!is_array($payload))continue;
            if(($payload['_sharky_allow_takeover']??false)===true)continue;
            $update->execute([':id'=>(string)$row['id']]);$cancelled+=$update->rowCount();
        }
    }catch(Throwable $e){error_log('[sharky-human] unable to cancel automatic outbox');}
    return $cancelled;
}

function hache_sharky_human_restore_last_user(PDO $pdo,string $contact,string $text): void
{
    $text=trim($text);if($text==='')return;
    try{$state=hache_sharky_db_state_load($pdo,$contact);$state['last_user_text']=mb_substr($text,0,1400);$state['updated_at']=time();hache_sharky_db_state_save($pdo,$contact,$state,86400);}catch(Throwable $e){error_log('[sharky-human] unable to restore customer last_user_text');}
}

function hache_sharky_human_process_echo(PDO $pdo,array $event): bool
{
    $contact=preg_replace('/\D+/','',(string)($event['to']??''))?:'';if($contact==='')return false;
    $deliveryLock=hache_sharky_orchestrator_delivery_lock($contact);if(!is_resource($deliveryLock))return false;
    try{
        if(!hache_sharky_lab_claim_early($pdo,$event,$contact,'echo'))return false;
        $eventId=(string)($event['id']??'echo');$command=hache_sharky_human_operator_command($event);

        if($command==='wake'){
            hache_sharky_human_grace_clear($contact);
            if(hache_sharky_takeover_active($contact)&&!hache_sharky_takeover_resume_hash(hache_sharky_contact_hash($contact))){
                error_log('[sharky-human] wake command could not release takeover');return false;
            }
            hache_sharky_metric_increment('takeover_manual_resume');
            return hache_sharky_orchestrator_mark_processed($pdo,$eventId);
        }

        hache_sharky_human_absorb_before_echo($pdo,$contact,$event);
        hache_sharky_human_cancel_automatic_outbox($pdo,$contact);

        if($command==='sleep'){
            hache_sharky_human_grace_clear($contact);
            if(!hache_sharky_takeover_mark($contact,'manual_sleep','Sharky duerme: control humano exclusivo hasta despertar o reinicio diario.')){
                error_log('[sharky-human] sleep command could not persist takeover');return false;
            }
            hache_sharky_metric_increment('takeover_manual_sleep');
            return hache_sharky_orchestrator_mark_processed($pdo,$eventId);
        }

        // A conversation already in an explicit takeover remains exclusive. An
        // ordinary human reply must not accidentally wake Sharky.
        if(hache_sharky_takeover_active($contact)){
            return hache_sharky_orchestrator_mark_processed($pdo,$eventId);
        }

        if(!hache_sharky_human_grace_mark($contact,$eventId)){
            // Fail closed: if grace cannot be persisted, preserve the previous
            // safe behavior instead of risking a double reply.
            if(!hache_sharky_takeover_mark($contact,'manual','Respuesta manual; fallback seguro por fallo de manual_grace.'))return false;
            error_log('[sharky-human] grace persistence failed; kept exclusive manual takeover');
        }else{
            hache_sharky_metric_increment('human_manual_grace_started');
        }
        return hache_sharky_orchestrator_mark_processed($pdo,$eventId);
    }finally{hache_sharky_lab_release_delivery_lock($deliveryLock);}
}

/**
 * Production wrapper around the existing worker. Normal traffic is delegated
 * unchanged; only manual echoes and the narrow grace window take the new path.
 */
function hache_sharky_human_process_event(PDO $pdo,array $event,array $business,?int $minAge=null,?int $escalationThreshold=null): bool
{
    $kind=(string)($event['kind']??'message');$configured=hache_sharky_lab_secret('WHATSAPP_PHONE_NUMBER_ID');
    if($configured!==''&&($event['phone_number_id']??'')!==''&&!hash_equals($configured,(string)$event['phone_number_id'])){
        return hache_sharky_lab_process_event($pdo,$event,$business,$minAge,$escalationThreshold);
    }
    if($kind==='echo')return hache_sharky_human_process_echo($pdo,$event);

    $contact=preg_replace('/\D+/','',(string)($event['from']??''))?:'';$eventId=(string)($event['id']??'');
    if($contact===''||$eventId===''||hache_sharky_takeover_active($contact))return hache_sharky_lab_process_event($pdo,$event,$business,$minAge,$escalationThreshold);
    if(!is_array(hache_sharky_human_grace_read($contact)))return hache_sharky_lab_process_event($pdo,$event,$business,$minAge,$escalationThreshold);

    $wait=hache_sharky_human_grace_wait($pdo,$contact,$eventId,(int)($event['timestamp_ms']??0));
    if(($wait['handled']??false)===true)return true;
    if(($wait['active']??false)!==true)return hache_sharky_lab_process_event($pdo,$event,$business,$minAge,$escalationThreshold);
    if(($wait['ready']??false)!==true)return false;

    $humanEventId=(string)($wait['human_event_id']??'');$humanText=hache_sharky_human_echo_text($pdo,$humanEventId);
    $actualText=trim((string)($event['text']??''));$graceIds=[$eventId];
    if(($event['type']??'text')==='text'&&trim((string)($event['interactive_id']??''))===''){
        $turn=hache_sharky_human_grace_text_turn($pdo,$contact,$eventId,$actualText);$actualText=(string)$turn['text'];$graceIds=$turn['ids'];$event['text']=$actualText;
    }

    $syntheticContext=false;$state=null;
    try{$state=hache_sharky_db_state_load($pdo,$contact);}catch(Throwable $e){}
    if(is_array($state)&&$humanText!==''&&hache_sharky_human_affirmative($actualText)&&hache_sharky_human_safe_context_question($humanText)){
        $event['text']=$humanText;$syntheticContext=true;
        if(function_exists('hache_sharky_meta_active')&&hache_sharky_meta_active($state))$event['interactive_id']='meta:free_text';
    }elseif(is_array($state)&&$humanText!==''&&!is_array($state['flow']??null)&&(!function_exists('hache_sharky_meta_active')||!hache_sharky_meta_active($state))){
        // In open conversation, expose the human question as the immediately
        // preceding turn so a short answer such as “sí” remains interpretable.
        try{$state['last_user_text']=mb_substr($humanText,0,700);$state['updated_at']=time();hache_sharky_db_state_save($pdo,$contact,$state,86400);}catch(Throwable $e){}
    }

    $ok=hache_sharky_lab_process_event($pdo,$event,$business,$minAge,$escalationThreshold);
    if(!$ok)return false;

    foreach($graceIds as $id){$id=trim((string)$id);if($id===''||$id===$eventId)continue;hache_sharky_orchestrator_mark_processed($pdo,$id);}
    if($syntheticContext)hache_sharky_human_restore_last_user($pdo,$contact,$actualText);
    hache_sharky_human_grace_clear_if_current($contact,$humanEventId,$eventId);
    hache_sharky_metric_increment('human_manual_grace_resumed');
    return true;
}
