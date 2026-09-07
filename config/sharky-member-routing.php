<?php

declare(strict_types=1);

require_once __DIR__.'/sharky-member-ops.php';
require_once __DIR__.'/sharky-member-payments.php';

function hache_sharky_member_routing_ready(PDO $pdo): bool
{
    return hache_sharky_member_schema_ready($pdo)
        && hache_sharky_member_payments_schema_ready($pdo);
}

function hache_sharky_member_routing_handoff_requested(string $text): bool
{
    $text=trim($text);
    if($text==='')return false;

    if(function_exists('hache_sharky_human_request')&&hache_sharky_human_request($text))return true;
    if(function_exists('hache_sharky_draft_requires_handoff')&&hache_sharky_draft_requires_handoff($text))return true;
    if(function_exists('hache_sharky_post72_payment_exception_request')&&hache_sharky_post72_payment_exception_request($text))return true;
    if(function_exists('hache_sharky_start_authority_handoff')&&is_array(hache_sharky_start_authority_handoff($text)))return true;

    return false;
}

function hache_sharky_member_deterministic_event(PDO $pdo,array $event,array $state): bool
{
    $kind=(string)($event['kind']??'');
    if(in_array($kind,[HACHE_SHARKY_MEMBER_EVIDENCE_KIND,HACHE_SHARKY_MEMBER_PAYMENT_PROOF_KIND],true))return true;

    $flow=hache_sharky_member_flow($state);$flowName=(string)($flow['name']??'');
    if(in_array($flowName,['absence','teacher_cancel'],true))return true;

    $intent=hache_sharky_member_intent((string)($event['text']??''),(string)($event['interactive_id']??''));
    $teacher=hache_sharky_member_teacher_by_whatsapp($pdo,(string)($event['from']??''));
    if(($teacher['found']??false)===true&&in_array($intent,['greeting','teacher_agenda','teacher_cancel','teacher_cancel_select','member:tc_confirm','member:tc_abort'],true))return true;

    return in_array($intent,['greeting','class_today','payments','absence','repos','member:absence_no_evidence','member:absence_add_evidence','member:absence_confirm','member:absence_abort','absence_date'],true);
}

function hache_sharky_member_supported_event(PDO $pdo,array $event): bool
{
    if(!hache_sharky_member_routing_ready($pdo))return false;
    if(trim((string)($event['group_id']??''))!=='')return false;
    if((string)($event['kind']??'')==='echo')return false;

    $contact=preg_replace('/\D+/','',(string)($event['from']??''))?:'';
    if($contact==='')return false;

    if(function_exists('hache_sharky_takeover_active')&&hache_sharky_takeover_active($contact))return false;
    $messageId=trim((string)($event['id']??''));
    if($messageId!==''&&function_exists('hache_sharky_inbox_handoff_pending')&&hache_sharky_inbox_handoff_pending($pdo,$messageId))return false;

    try{$state=hache_sharky_db_state_load($pdo,$contact);}catch(Throwable $e){return false;}
    if(hache_sharky_member_deterministic_event($pdo,$event,$state))return true;

    $student=hache_sharky_business_identity_by_whatsapp($pdo,$contact);
    if(($student['found']??false)!==true)return false;

    // An explicit request for a person (or another existing safety/commercial
    // handoff rule) keeps using the controlled takeover path. Everything else
    // from a known student stays with Sharky instead of falling into the legacy
    // "known student = human" shortcut.
    if(hache_sharky_member_routing_handoff_requested((string)($event['text']??'')))return false;

    return trim((string)($event['type']??''))!=='';
}

function hache_sharky_member_student_fallback(PDO $pdo,array $event): bool
{
    $contact=preg_replace('/\D+/','',(string)($event['from']??''))?:'';
    if($contact==='')return false;
    $student=hache_sharky_member_student_context($pdo,$contact);
    if(($student['found']??false)!==true)return false;

    $deliveryLock=hache_sharky_orchestrator_delivery_lock($contact);
    if(!is_resource($deliveryLock))return false;
    hache_sharky_db_state_defer_begin();
    try{
        if(!hache_sharky_lab_claim_early($pdo,$event,$contact,(string)($event['type']??'message'))){
            hache_sharky_db_state_defer_cancel();
            return false;
        }
        $state=hache_sharky_db_state_load($pdo,$contact);
        $identity=$student['identity']??null;
        if(is_array($identity)&&function_exists('hache_sharky_orchestrator_apply_identity'))$state=hache_sharky_orchestrator_apply_identity($state,$identity);
        $first=function_exists('hache_sharky_member_first_name')?hache_sharky_member_first_name($student):'';
        $body='Claro'.($first!==''?', '.$first:'').' 😊 Dime qué necesitas. Puedo ayudarte con tus clases, pagos, ausencias y reposiciones; si es otra cosa, escríbemela con confianza.';
        $payload=hache_sharky_member_buttons($contact,$body,[
            ['id'=>'member:class_today','title'=>'Mi clase hoy'],
            ['id'=>'member:payments','title'=>'Pagos'],
            ['id'=>'member:absence','title'=>'Reportar ausencia'],
        ]);
        return hache_sharky_member_queue($pdo,$contact,$event,$state,$payload,'student-fallback');
    }catch(Throwable $e){
        hache_sharky_db_state_defer_cancel();
        error_log('[sharky-member-routing] fallback failed');
        return false;
    }finally{
        hache_sharky_lab_release_delivery_lock($deliveryLock);
    }
}

/**
 * Shared semantic lane for realtime webhook and inbox recovery.
 * Returns null when the event belongs to the legacy/general Sharky pipeline.
 */
function hache_sharky_member_route_event(PDO $pdo,array $event,array $business=[]): ?bool
{
    if(!hache_sharky_member_routing_ready($pdo))return null;
    if(trim((string)($event['group_id']??''))!=='')return null;
    if((string)($event['kind']??'')==='echo')return null;

    $contact=preg_replace('/\D+/','',(string)($event['from']??''))?:'';
    if($contact==='')return null;
    if(function_exists('hache_sharky_takeover_active')&&hache_sharky_takeover_active($contact))return null;

    $paymentResult=hache_sharky_member_payment_process_event($pdo,$event,$business);
    if($paymentResult!==null)return $paymentResult;
    if(!hache_sharky_member_supported_event($pdo,$event))return null;

    try{$state=hache_sharky_db_state_load($pdo,$contact);}catch(Throwable $e){return null;}
    if(hache_sharky_member_deterministic_event($pdo,$event,$state)){
        return hache_sharky_member_process_event($pdo,$event,$business);
    }

    // Member-ops intentionally handles only deterministic operations. A known
    // student with another benign question must stay with Sharky rather than be
    // auto-handed to a person by the old batching policy. Route it directly to
    // the fallback so the same inbox receipt is claimed exactly once.
    return hache_sharky_member_student_fallback($pdo,$event);
}
