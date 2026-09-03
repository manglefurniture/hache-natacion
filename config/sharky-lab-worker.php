<?php

declare(strict_types=1);

require_once __DIR__.'/sharky-runtime.php';
require_once __DIR__.'/sharky-whatsapp-batching.php';
require_once __DIR__.'/sharky-whatsapp-echoes.php';
require_once __DIR__.'/sharky-draft-parity.php';
require_once __DIR__.'/sharky-post-pr72.php';
require_once __DIR__.'/sharky-outbox.php';
require_once __DIR__.'/sharky-inbox.php';

function hache_sharky_lab_secret(string $name): string
{
    $value=trim((string)getenv($name));if($value!=='')return $value;
    return hache_sharky_orchestrator_secret($name);
}

function hache_sharky_lab_graph_version(): string
{
    $version=hache_sharky_lab_secret('WHATSAPP_GRAPH_VERSION');return preg_match('/^v\d+\.\d+$/',$version)===1?$version:'v26.0';
}

function hache_sharky_lab_send(array $payload): bool
{
    $token=hache_sharky_lab_secret('WHATSAPP_ACCESS_TOKEN');$phoneId=hache_sharky_lab_secret('WHATSAPP_PHONE_NUMBER_ID');if($token===''||$phoneId==='')return false;
    $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($json===false)return false;
    $ch=curl_init('https://graph.facebook.com/'.rawurlencode(hache_sharky_lab_graph_version()).'/'.rawurlencode($phoneId).'/messages');
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$token],CURLOPT_POSTFIELDS=>$json]);
    $response=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);
    return $response!==false&&$error===''&&$status>=200&&$status<300;
}

function hache_sharky_lab_answer(string $text,string $instruction,array $state,array $context): string
{
    $history=[];$ref=$state['referral']['latest']??null;
    if(is_array($ref)&&!empty($ref['headline']))$history[]=['role'=>'system','content'=>'Origen de campaña: '.mb_substr((string)$ref['headline'],0,180)];
    $instruction=rtrim($instruction)."\n\n".hache_sharky_post72_whatsapp_style_policy();
    $history[]=['role'=>'system','content'=>$instruction];$payload=json_encode(['message'=>$text,'history'=>$history,'channel'=>'whatsapp'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($payload===false)return '';
    $ch=curl_init('https://hnatacion.com/api/sharky.php');curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>30,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_POSTFIELDS=>$payload,CURLOPT_RESOLVE=>['hnatacion.com:443:127.0.0.1']]);
    $response=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);if($response===false||$status<200||$status>=300)return '';
    $data=json_decode((string)$response,true);return is_array($data)&&($data['ok']??false)===true?trim((string)($data['answer']??'')):'';
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

/**
 * La respuesta pendiente y la finalización del inbox comparten una misma
 * transacción. Si el outbox no puede persistirse, ningún receipt se completa.
 */
function hache_sharky_lab_queue_and_complete(PDO $pdo,string $contact,array $payload,string $dedupeSeed,string $sourceMessageId,array $batchedIds=[]): bool
{
    try{
        if($pdo->inTransaction())throw new RuntimeException('Unexpected open transaction before Sharky delivery boundary');
        $pdo->beginTransaction();
        if(!hache_sharky_outbox_enqueue($pdo,$contact,$payload,$dedupeSeed))throw new RuntimeException('Unable to persist Sharky outbound payload');
        hache_sharky_action_delivery_queued_by_message($pdo,$sourceMessageId);
        foreach(hache_sharky_lab_receipt_ids($sourceMessageId,$batchedIds) as $messageId)hache_sharky_orchestrator_mark_processed($pdo,$messageId);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('[sharky-lab] durable delivery boundary failed');return false;}
    // El envío ocurre después del commit. Si Meta falla, el outbox conserva la respuesta.
    hache_sharky_outbox_dispatch($pdo,'hache_sharky_lab_send',10);
    return true;
}

function hache_sharky_lab_process_event(PDO $pdo,array $event,array $business,?int $minAge=null,?int $escalationThreshold=null): bool
{
    $kind=(string)($event['kind']??'message');$configured=hache_sharky_lab_secret('WHATSAPP_PHONE_NUMBER_ID');
    if($configured!==''&&($event['phone_number_id']??'')!==''&&!hash_equals($configured,(string)$event['phone_number_id'])){
        $contact=hache_sharky_inbox_contact($event);if($contact!==''&&hache_sharky_lab_claim_early($pdo,$event,$contact,$kind))hache_sharky_orchestrator_mark_processed($pdo,(string)$event['id']);
        return true;
    }
    if($kind==='echo'){
        $contact=preg_replace('/\D+/','',(string)($event['to']??''))?:'';if($contact==='')return false;
        if(!hache_sharky_lab_claim_early($pdo,$event,$contact,'echo'))return false;
        hache_sharky_takeover_mark($contact,'manual','Respuesta manual detectada por WhatsApp coexistence.');hache_sharky_orchestrator_mark_processed($pdo,(string)$event['id']);return true;
    }

    $contact=preg_replace('/\D+/','',(string)($event['from']??''))?:'';if($contact==='')return false;$eventId=(string)($event['id']??'event');
    if(hache_sharky_takeover_active($contact)){
        if(!hache_sharky_lab_claim_early($pdo,$event,$contact,(string)($event['type']??'message')))return false;
        hache_sharky_orchestrator_mark_processed($pdo,$eventId);return true;
    }
    $minAge??=hache_sharky_config_int($business,'sharky_edad_minima',12,1,99);$escalationThreshold??=hache_sharky_config_int($business,'sharky_escalado_intentos',2,1,5);
    $secretResolver=static fn(string $name):string=>hache_sharky_lab_secret($name);
    if(($event['type']??'')==='audio'){
        $text=hache_sharky_draft_transcribe_audio($event,$business,$secretResolver);
        if($text===''){
            if(!hache_sharky_lab_claim_early($pdo,$event,$contact,'audio'))return false;
            $payload=hache_sharky_whatsapp_text_payload($contact,'No pude procesar esa nota de voz. Escríbeme el mensaje y seguimos por aquí.');
            return hache_sharky_lab_queue_and_complete($pdo,$contact,$payload,$eventId.'|audio-fallback',$eventId);
        }
        $event['type']='text';$event['text']=$text;
    }
    $text=trim((string)($event['text']??''));$paymentException=$text!==''&&hache_sharky_post72_payment_exception_request($text);
    if($text!==''&&($paymentException||hache_sharky_draft_requires_handoff($text))){
        if(!hache_sharky_lab_claim_early($pdo,$event,$contact,(string)($event['type']??'text')))return false;
        $message=$paymentException
            ? 'Para asegurar tu lugar, la reserva se realiza por anticipado pagando el total o al menos el 50%. Si deseas pagar todo hasta el día de inicio sin reserva previa, necesito dejarte con una persona del equipo para que confirme esa excepción.'
            : 'Te dejo con el equipo de Hache Natación. Una persona continuará contigo por este mismo chat.';
        $payload=hache_sharky_whatsapp_text_payload($contact,$message);
        if(!hache_sharky_lab_queue_and_complete($pdo,$contact,$payload,$eventId.'|handoff',$eventId))return false;
        hache_sharky_takeover_mark($contact,$paymentException?'payment_exception':'shared_v2_policy',$paymentException?'Excepción comercial: 0% anticipado y pago total al inicio.':'Handoff decidido por la misma regla vigente del webhook v2.');
        return true;
    }

    $result=hache_sharky_whatsapp_enqueue($pdo,$event,'hache_sharky_lab_answer',[
        'verification_base_url'=>'https://hnatacion.com/sharky-verificar.php',
        'min_age'=>$minAge,
        'defer_receipt_completion'=>true,
    ]);
    if($result['skip']??false)return false;
    $decision=is_array($result['decision']??null)?$result['decision']:[];$action=is_array($decision['action']??null)?$decision['action']:null;
    $shouldTakeover=is_array($action)&&($action['type']??'')==='human_takeover';
    $out=is_array($result['payload']??null)?$result['payload']:null;$actionResult=is_array($result['action_result']??null)?$result['action_result']:null;
    if(is_array($actionResult)){
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
    $deliveryPending=hache_sharky_action_delivery_pending_for_message($pdo,$deliverySource);
    if($deliveryPending&&!is_array($out))return false;
    if(is_array($out)){
        $payloadHash=hash('sha256',json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:'');
        if(!hache_sharky_lab_queue_and_complete($pdo,$contact,$out,$deliverySource.'|'.$decisionKind.'|'.$payloadHash,$deliverySource,$batchedIds))return false;
    }else{
        foreach(hache_sharky_lab_receipt_ids($deliverySource,$batchedIds) as $messageId)hache_sharky_orchestrator_mark_processed($pdo,$messageId);
    }
    if($shouldTakeover)hache_sharky_takeover_mark($contact,$decisionKind==='conversation'?'unresolved':'requested_human','Sharky 2.0 controlled handoff');
    return true;
}
