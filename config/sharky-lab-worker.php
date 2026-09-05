<?php

declare(strict_types=1);

require_once __DIR__.'/sharky-runtime.php';
require_once __DIR__.'/sharky-whatsapp-batching.php';
require_once __DIR__.'/sharky-whatsapp-echoes.php';
require_once __DIR__.'/sharky-draft-parity.php';
require_once __DIR__.'/sharky-post-pr72.php';
require_once __DIR__.'/sharky-outbox.php';
require_once __DIR__.'/sharky-inbox.php';
require_once __DIR__.'/sharky-groups.php';

function hache_sharky_lab_secret(string $name): string
{
    $value=trim((string)getenv($name));if($value!=='')return $value;
    return hache_sharky_orchestrator_secret($name);
}

function hache_sharky_lab_graph_version(): string
{
    $version=hache_sharky_lab_secret('WHATSAPP_GRAPH_VERSION');return preg_match('/^v\d+\.\d+$/',$version)===1?$version:'v26.0';
}

function hache_sharky_lab_today(): string
{
    return (new DateTimeImmutable('today',new DateTimeZone('America/Cancun')))->format('Y-m-d');
}

function hache_sharky_lab_send(array $payload): bool
{
    $payload=hache_sharky_groups_finalize_outbound($payload);
    $token=hache_sharky_lab_secret('WHATSAPP_ACCESS_TOKEN');$phoneId=hache_sharky_lab_secret('WHATSAPP_PHONE_NUMBER_ID');if($token===''||$phoneId==='')return false;
    $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($json===false)return false;
    $ch=curl_init('https://graph.facebook.com/'.rawurlencode(hache_sharky_lab_graph_version()).'/'.rawurlencode($phoneId).'/messages');
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$token],CURLOPT_POSTFIELDS=>$json]);
    $response=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);
    return $response!==false&&$error===''&&$status>=200&&$status<300;
}

function hache_sharky_lab_presentation_queued(array $state): bool
{
    return ($state['assistant_presentation_queued']??false)===true;
}

function hache_sharky_lab_answer_contains_presentation(string $answer): bool
{
    $head=hache_sharky_orchestrator_normalize(mb_substr(trim($answer),0,300));
    return str_contains($head,'soy sharky')&&str_contains($head,'hache natacion');
}

function hache_sharky_lab_mark_presentation_queued(?array $deferredState,array $payload): ?array
{
    if(!is_array($deferredState)||!is_array($deferredState['state']??null))return $deferredState;
    if(hache_sharky_lab_presentation_queued($deferredState['state']))return $deferredState;
    $answer=hache_sharky_draft_payload_text($payload);
    if($answer!==''&&hache_sharky_lab_answer_contains_presentation($answer))$deferredState['state']['assistant_presentation_queued']=true;
    return $deferredState;
}

function hache_sharky_lab_answer(string $text,string $instruction,array $state,array $context): string
{
    $history=[];$ref=$state['referral']['latest']??null;
    $previous=trim((string)($context['previous_user_text']??''));
    if($previous!=='')$history[]=['role'=>'user','content'=>mb_substr($previous,0,700)];
    if(is_array($ref)&&!empty($ref['headline']))$history[]=['role'=>'system','content'=>'Origen de campaña: '.mb_substr((string)$ref['headline'],0,180)];
    $instruction=rtrim($instruction)."\n\n".hache_sharky_post72_whatsapp_style_policy();
    $history[]=['role'=>'system','content'=>$instruction];
    if(hache_sharky_lab_presentation_queued($state))$history[]=['role'=>'assistant','content'=>'Ya me presenté como Sharky; la conversación ya está en curso.'];
    $payload=json_encode(['message'=>$text,'history'=>$history,'channel'=>'whatsapp'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($payload===false)return '';
    $ch=curl_init('https://hnatacion.com/api/sharky.php');curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>30,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_POSTFIELDS=>$payload,CURLOPT_RESOLVE=>['hnatacion.com:443:127.0.0.1']]);
    $response=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);if($response===false||$status<200||$status>=300)return '';
    $data=json_decode((string)$response,true);
    if(!is_array($data)||($data['ok']??false)!==true)return '';
    if(is_array($data['usage']??null)&&trim((string)($context['contact']??''))!=='')hache_sharky_usage_record((string)$context['contact'],$data['usage']);
    return trim((string)($data['answer']??''));
}

function hache_sharky_lab_claim_early(PDO $pdo,array $event,string $contact,string $type): bool
{
    return hache_sharky_orchestrator_claim_message($pdo,(string)($event['id']??''),hache_sharky_orchestrator_contact_hash($contact),$type);
}

function hache_sharky_lab_receipt_ids(string $sourceMessageId,array $batchedIds=[]): array
{
    $ids=[];$sourceMessageId=trim($sourceMessageId);if($sourceMessageId!=='')$ids[$sourceMessageId]=true;
    foreach($batchedIds as $id){$id=trim((string)$id);if($id!=='')$ids[$id]=true;}
    return array_keys($ids);
}

function hache_sharky_lab_mark_handoff_pending(PDO $pdo,string $sourceMessageId,array $batchedIds=[]): bool
{
    foreach(hache_sharky_lab_receipt_ids($sourceMessageId,$batchedIds) as $messageId){
        if(!hache_sharky_inbox_mark_handoff_pending($pdo,$messageId))return false;
    }
    return true;
}

function hache_sharky_lab_persist_deferred_state(PDO $pdo,?array $deferredState): void
{
    if(!is_array($deferredState))return;
    $contact=trim((string)($deferredState['contact']??''));$state=$deferredState['state']??null;$ttl=(int)($deferredState['ttl']??86400);
    if($contact===''||!is_array($state))throw new RuntimeException('Invalid deferred Sharky state');
    hache_sharky_db_state_save_now($pdo,$contact,$state,$ttl);
}

function hache_sharky_lab_release_delivery_lock($lock): void
{
    if(is_resource($lock))hache_sharky_orchestrator_unlock($lock);
}

/**
 * Estado conversacional, respuesta pendiente y finalización del inbox comparten
 * una misma transacción. Si cualquiera de las escrituras críticas falla, se
 * revierte todo y el inbox conserva el turno para recovery.
 */
function hache_sharky_lab_queue_and_complete(PDO $pdo,string $contact,array $payload,string $dedupeSeed,string $sourceMessageId,array $batchedIds=[],?array $deferredState=null): bool
{
    try{
        if($pdo->inTransaction())throw new RuntimeException('Unexpected open transaction before Sharky delivery boundary');
        $pdo->beginTransaction();
        hache_sharky_lab_persist_deferred_state($pdo,$deferredState);
        $queued=($payload['_sharky_group']??false)===true
            ?hache_sharky_outbox_enqueue_raw($pdo,$contact,$payload,$dedupeSeed,time())
            :hache_sharky_outbox_enqueue($pdo,$contact,$payload,$dedupeSeed);
        if(!$queued)throw new RuntimeException('Unable to persist Sharky outbound payload');
        if(!hache_sharky_action_delivery_queued_by_message($pdo,$sourceMessageId))throw new RuntimeException('Unable to mark Sharky action delivery as queued');
        foreach(hache_sharky_lab_receipt_ids($sourceMessageId,$batchedIds) as $messageId){
            if(!hache_sharky_orchestrator_mark_processed($pdo,$messageId))throw new RuntimeException('Unable to complete Sharky inbox receipt');
        }
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('[sharky-lab] durable delivery boundary failed');return false;}
    // El caller mantiene el delivery lock de este contacto durante el dispatch.
    hache_sharky_outbox_dispatch($pdo,'hache_sharky_lab_send',10,$contact);
    return true;
}

function hache_sharky_lab_complete_without_outbox(PDO $pdo,string $sourceMessageId,array $batchedIds=[],?array $deferredState=null): bool
{
    try{
        if($pdo->inTransaction())throw new RuntimeException('Unexpected open transaction before Sharky completion boundary');
        $pdo->beginTransaction();
        hache_sharky_lab_persist_deferred_state($pdo,$deferredState);
        foreach(hache_sharky_lab_receipt_ids($sourceMessageId,$batchedIds) as $messageId){
            if(!hache_sharky_orchestrator_mark_processed($pdo,$messageId))throw new RuntimeException('Unable to complete Sharky inbox receipt');
        }
        $pdo->commit();return true;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('[sharky-lab] durable completion boundary failed');return false;}
}

function hache_sharky_lab_process_event(PDO $pdo,array $event,array $business,?int $minAge=null,?int $escalationThreshold=null): bool
{
    $kind=(string)($event['kind']??'message');$configured=hache_sharky_lab_secret('WHATSAPP_PHONE_NUMBER_ID');
    $groupId=trim((string)($event['group_id']??''));
    if($configured!==''&&($event['phone_number_id']??'')!==''&&!hash_equals($configured,(string)$event['phone_number_id'])){
        $contact=hache_sharky_inbox_contact($event);if($contact!==''&&hache_sharky_lab_claim_early($pdo,$event,$contact,$kind))hache_sharky_orchestrator_mark_processed($pdo,(string)$event['id']);
        return true;
    }
    if($kind==='echo'){
        $contact=preg_replace('/\D+/','',(string)($event['to']??''))?:'';if($contact==='')return false;
        $deliveryLock=hache_sharky_orchestrator_delivery_lock($contact);if(!is_resource($deliveryLock))return false;
        try{
            if(!hache_sharky_lab_claim_early($pdo,$event,$contact,'echo'))return false;
            if(!hache_sharky_takeover_mark($contact,'manual','Respuesta manual detectada por WhatsApp coexistence.')){
                error_log('[sharky-lab] manual takeover persistence failed; automatic outbox remains blocked from dispatch in this worker');
                return false;
            }
            // Solo después de persistir takeover se permite cancelar pendientes.
            hache_sharky_outbox_dispatch($pdo,'hache_sharky_lab_send',20,$contact);
            return hache_sharky_orchestrator_mark_processed($pdo,(string)$event['id']);
        }finally{hache_sharky_lab_release_delivery_lock($deliveryLock);}
    }

    $contact=preg_replace('/\D+/','',(string)($event['from']??''))?:'';if($contact==='')return false;$eventId=(string)($event['id']??'event');
    $handoffPending=hache_sharky_inbox_handoff_pending($pdo,$eventId);
    if(hache_sharky_takeover_active($contact)&&!$handoffPending){
        if(!hache_sharky_lab_claim_early($pdo,$event,$contact,(string)($event['type']??'message')))return false;
        return hache_sharky_orchestrator_mark_processed($pdo,$eventId);
    }
    $minAge??=hache_sharky_config_int($business,'sharky_edad_minima',12,1,99);$escalationThreshold??=hache_sharky_config_int($business,'sharky_escalado_intentos',2,1,5);
    $secretResolver=static fn(string $name):string=>hache_sharky_lab_secret($name);
    if(($event['type']??'')==='audio'){
        $text=hache_sharky_draft_transcribe_audio($event,$business,$secretResolver);
        if($text===''){
            $deliveryLock=hache_sharky_orchestrator_delivery_lock($contact);if(!is_resource($deliveryLock))return false;
            try{
                if(!hache_sharky_lab_claim_early($pdo,$event,$contact,'audio'))return false;
                $payload=hache_sharky_groups_prepare_outbound(hache_sharky_whatsapp_text_payload($contact,'No pude procesar esa nota de voz. Escríbeme el mensaje y seguimos por aquí.'),$groupId);
                return hache_sharky_lab_queue_and_complete($pdo,$contact,$payload,$eventId.'|audio-fallback',$eventId);
            }finally{hache_sharky_lab_release_delivery_lock($deliveryLock);}
        }
        $event['type']='text';$event['text']=$text;
    }
    $text=trim((string)($event['text']??''));
    $paymentException=$text!==''&&hache_sharky_post72_payment_exception_request($text);
    $startAuthority=$text!==''?hache_sharky_start_authority_handoff($text):null;
    if($text!==''&&(is_array($startAuthority)||$paymentException||hache_sharky_draft_requires_handoff($text))){
        $deliveryLock=hache_sharky_orchestrator_delivery_lock($contact);if(!is_resource($deliveryLock))return false;
        try{
            if(!hache_sharky_lab_claim_early($pdo,$event,$contact,(string)($event['type']??'text')))return false;
            if(!hache_sharky_lab_mark_handoff_pending($pdo,$eventId))return false;
            if(is_array($startAuthority))$message=(string)$startAuthority['message'];
            elseif($paymentException)$message='Para asegurar tu lugar, la reserva se realiza por anticipado pagando el total o al menos el 50%. Si deseas pagar todo hasta el día de inicio sin reserva previa, necesito dejarte con una persona del equipo para que confirme esa excepción.';
            else $message='Te dejo con el equipo de Hache Natación. Una persona continuará contigo por este mismo chat.';
            $reason=is_array($startAuthority)?'start_date_exception':($paymentException?'payment_exception':'shared_v2_policy');
            $summary=is_array($startAuthority)?'Excepción de fecha de inicio fuera de la autoridad de Sharky.':($paymentException?'Excepción comercial: 0% anticipado y pago total al inicio.':'Handoff decidido por la misma regla vigente del webhook v2.');
            if(!hache_sharky_takeover_mark($contact,$reason,$summary)){
                error_log('[sharky-lab] takeover persistence failed before handoff delivery reason='.$reason);
                return false;
            }
            $payload=hache_sharky_groups_prepare_outbound(hache_sharky_outbox_allow_during_takeover(hache_sharky_whatsapp_text_payload($contact,$message)),$groupId);
            return hache_sharky_lab_queue_and_complete($pdo,$contact,$payload,$eventId.'|handoff',$eventId);
        }finally{hache_sharky_lab_release_delivery_lock($deliveryLock);}
    }

    $deliveryLock=null;
    hache_sharky_db_state_defer_begin();
    try{
        $result=hache_sharky_whatsapp_enqueue($pdo,$event,'hache_sharky_lab_answer',[
            'verification_base_url'=>'https://hnatacion.com/sharky-verificar.php',
            'min_age'=>$minAge,
            'today'=>hache_sharky_lab_today(),
            'defer_receipt_completion'=>true,
            'defer_delivery_unlock'=>true,
        ]);
        $deliveryLock=$result['_delivery_lock']??null;unset($result['_delivery_lock']);
        $deferredState=hache_sharky_db_state_defer_take();
    }catch(Throwable $e){hache_sharky_db_state_defer_cancel();hache_sharky_lab_release_delivery_lock($deliveryLock);throw $e;}
    if($result['skip']??false){hache_sharky_lab_release_delivery_lock($deliveryLock);return false;}

    try{
        $decision=is_array($result['decision']??null)?$result['decision']:[];$action=is_array($decision['action']??null)?$decision['action']:null;
        $shouldTakeover=is_array($action)&&($action['type']??'')==='human_takeover';
        $out=is_array($result['payload']??null)?$result['payload']:null;$actionResult=is_array($result['action_result']??null)?$result['action_result']:null;
        if(is_array($actionResult)){
            if((string)($actionResult['code']??'')==='START_DATE_REQUIRES_HUMAN'){
                $shouldTakeover=true;
                $out=hache_sharky_whatsapp_text_payload($contact,'Los cursos intensivos comienzan los lunes. Para incorporarte en otra fecha necesito dejarte con una persona del equipo que autorice la excepción.');
            }
            $studentId=trim((string)($actionResult['result']['student_id']??''));if($studentId!=='')hache_sharky_draft_link_attribution($pdo,$contact,$studentId,is_array($result['state']??null)?$result['state']:[]);
            $registrationMessage=hache_sharky_post72_registration_message($actionResult,$business)??hache_sharky_draft_registration_message($actionResult,$business);if($registrationMessage!==null)$out=hache_sharky_whatsapp_text_payload($contact,$registrationMessage);
        }
        $decisionKind=(string)($decision['kind']??'');
        if(in_array($decisionKind,['conversation','conversation_identity_prompt','side_question'],true)){
            $answer=hache_sharky_draft_payload_text($out);
            if($answer!==''&&hache_sharky_draft_escalation_update($contact,$answer,$escalationThreshold)){
                $shouldTakeover=true;
                $out=hache_sharky_whatsapp_text_payload($contact,'Para no hacerte dar vueltas, te dejo con el equipo de Hache Natación. Una persona continuará contigo por este mismo chat.');
            }
        }

        $deliverySource=(string)($result['synthetic_id']??$eventId);$batchedIds=is_array($result['batched_ids']??null)?$result['batched_ids']:[];
        if($shouldTakeover){
            if(!hache_sharky_lab_mark_handoff_pending($pdo,$deliverySource,$batchedIds))return false;
            $reason=$decisionKind==='conversation'?'unresolved':((string)($actionResult['code']??'')==='START_DATE_REQUIRES_HUMAN'?'start_date_exception':'requested_human');
            if(!hache_sharky_takeover_mark($contact,$reason,'Sharky 2.0 controlled handoff')){
                error_log('[sharky-lab] controlled takeover persistence failed reason='.$reason);
                return false;
            }
            if(is_array($out))$out=hache_sharky_outbox_allow_during_takeover($out);
        }

        if(is_array($out))$out=hache_sharky_groups_prepare_outbound($out,$groupId);
        $deliveryPending=hache_sharky_action_delivery_pending_for_message($pdo,$deliverySource);
        if($deliveryPending&&!is_array($out))return false;
        if(is_array($out)){
            $deferredState=hache_sharky_lab_mark_presentation_queued($deferredState,$out);
            $payloadHash=hash('sha256',json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:'');
            return hache_sharky_lab_queue_and_complete($pdo,$contact,$out,$deliverySource.'|'.$decisionKind.'|'.$payloadHash,$deliverySource,$batchedIds,$deferredState);
        }
        return hache_sharky_lab_complete_without_outbox($pdo,$deliverySource,$batchedIds,$deferredState);
    }finally{hache_sharky_lab_release_delivery_lock($deliveryLock);}
}
