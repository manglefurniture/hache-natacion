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

function hache_sharky_member_closure_text(string $text): bool
{
    if(str_contains($text,'?')||str_contains($text,'¿'))return false;
    $t=hache_sharky_member_normalize($text);
    $t=preg_replace('/[^\p{L}\p{N}\s]+/u',' ',$t)??$t;
    $t=preg_replace('/\s+/u',' ',trim($t))??trim($t);
    if($t==='')return false;
    return preg_match('/^(?:(?:ok|okay|okey|vale|perfecto|listo|sale)\s+)?(?:muchas\s+)?gracias(?:\s+(?:sharky|por\s+todo|por\s+la\s+ayuda))?$/u',$t)===1;
}

function hache_sharky_member_pending_registration(array $student): bool
{
    return strtoupper(trim((string)($student['student']['estado_administrativo']??'')))==='PENDIENTE';
}

function hache_sharky_member_pending_schedule_problem(string $text): bool
{
    $t=hache_sharky_member_normalize($text);
    if($t==='')return false;
    return preg_match('/\b(?:horario|horarios|temprano|madrugar|levantarme|levantarse|asistir|asistencia|faltar|faltado|no\s+he\s+podido\s+ir)\b/u',$t)===1;
}

function hache_sharky_member_pending_message(array $student,string $text,string $intent=''): string
{
    $first=function_exists('hache_sharky_member_first_name')?hache_sharky_member_first_name($student):'';
    $name=$first!==''?', '.$first:'';

    if($intent==='greeting'){
        return '¡Hola'.$name.'! 😊 Veo que tu inscripción todavía está pendiente. Si quieres retomarla, puedo ayudarte a revisar el pago o las opciones que tengas disponibles.';
    }
    if(hache_sharky_member_pending_schedule_problem($text)){
        return 'Entiendo'.$name.' 😊. Si ese horario se te está complicando, podemos revisar qué opciones tienes para retomar tu inscripción. Cuéntame qué horario te funcionaría mejor y te oriento con lo que haya disponible.';
    }
    return 'Claro'.$name.' 😊. Tu inscripción todavía está pendiente. Cuéntame qué necesitas y te ayudo a revisar cómo retomarla.';
}

function hache_sharky_member_teacher_owned_event(array $teacher,?array $flow,array $event,?string $intent=null): bool
{
    if(($teacher['found']??false)!==true)return false;
    $intent=$intent??hache_sharky_member_intent((string)($event['text']??''),(string)($event['interactive_id']??''));
    if(in_array($intent,['greeting','teacher_agenda','teacher_cancel','teacher_cancel_select','member:tc_confirm','member:tc_abort'],true))return true;

    // The only teacher-owned free text is the cancellation reason. Do not let
    // a stale member payment button or payment-like text inherit teacher flow
    // ownership merely because teacher_cancel is still active.
    if(!is_array($flow)||($flow['name']??'')!=='teacher_cancel'||($flow['step']??'')!=='reason')return false;
    if(trim((string)($event['interactive_id']??''))!=='')return false;
    if(trim((string)($event['text']??''))==='')return false;
    return $intent!=='payments';
}

/**
 * Temporal Palapas red-light policy.
 *
 * A Palapas record can still be recognized by its WhatsApp number, but Sharky
 * must not expose or operate payments, balances, absences or repositions while
 * that site is under administrative reconciliation. The only self-service
 * member capability left enabled is confirming whether today's class is on.
 */
function hache_sharky_member_palapas_restricted_route(PDO $pdo,array $event): ?bool
{
    if(trim((string)($event['group_id']??''))!=='')return null;
    if((string)($event['kind']??'')==='echo')return null;

    $contact=preg_replace('/\D+/','',(string)($event['from']??''))?:'';
    if($contact==='')return null;
    if(function_exists('hache_sharky_takeover_active')&&hache_sharky_takeover_active($contact))return null;

    $identity=hache_sharky_business_identity_by_whatsapp($pdo,$contact);
    if(($identity['found']??false)!==true||strtoupper((string)($identity['sede_clave']??''))!=='PALAPAS')return null;
    if(hache_sharky_member_routing_handoff_requested((string)($event['text']??'')))return null;

    // A professor may also exist in the member registry. Only explicit teacher
    // controls and the free-text cancellation-reason step bypass the Palapas
    // gate; member payment events stay restricted even while a teacher flow is active.
    $teacher=hache_sharky_member_teacher_by_whatsapp($pdo,$contact);
    $intent=hache_sharky_member_intent((string)($event['text']??''),(string)($event['interactive_id']??''));
    try{$routingState=hache_sharky_db_state_load($pdo,$contact);$routingFlow=hache_sharky_member_flow($routingState);}catch(Throwable $e){$routingFlow=null;}
    if(hache_sharky_member_teacher_owned_event($teacher,$routingFlow,$event,$intent))return null;

    $student=hache_sharky_member_student_context($pdo,$contact);
    if(($student['found']??false)!==true)return null;

    $deliveryLock=hache_sharky_orchestrator_delivery_lock($contact);
    if(!is_resource($deliveryLock))return false;
    hache_sharky_db_state_defer_begin();
    try{
        if(!hache_sharky_lab_claim_early($pdo,$event,$contact,(string)($event['type']??'message'))){
            hache_sharky_db_state_defer_cancel();
            return false;
        }
        $state=hache_sharky_db_state_load($pdo,$contact);
        $state=hache_sharky_member_set_flow($state,null,time());
        $memberIdentity=$student['identity']??null;
        if(is_array($memberIdentity)&&function_exists('hache_sharky_orchestrator_apply_identity'))$state=hache_sharky_orchestrator_apply_identity($state,$memberIdentity);

        $text=trim((string)($event['text']??''));
        $first=hache_sharky_member_first_name($student);
        if(hache_sharky_member_closure_text($text)){
            $payload=hache_sharky_whatsapp_text_payload($contact,'¡Con gusto'.($first!==''?', '.$first:'').'! 😊');
            return hache_sharky_member_queue($pdo,$contact,$event,$state,$payload,'palapas-close');
        }

        // A record created by an unfinished registration is identifiable, but it
        // is not yet an active student. Never affirm a scheduled class for it.
        if(hache_sharky_member_pending_registration($student)){
            $body=($first!==''?'Hola, '.$first.' 😊. ':'').'Veo que tu inscripción en Palapas todavía está pendiente. Por ahora no puedo confirmar una clase activa para ti; el equipo de Hache puede revisar tu situación.';
            $payload=hache_sharky_whatsapp_text_payload($contact,$body);
            return hache_sharky_member_queue($pdo,$contact,$event,$state,$payload,'palapas-pending');
        }

        if($intent==='class_today'){
            $payload=hache_sharky_whatsapp_text_payload($contact,hache_sharky_member_class_message($student));
            return hache_sharky_member_queue($pdo,$contact,$event,$state,$payload,'palapas-class-today');
        }

        if($intent==='payments'){
            $body='Por ahora los temas de pagos de Palapas los está revisando directamente el equipo de Hache. Sí puedo ayudarte a confirmar si tienes clase hoy.';
        }else{
            $body=($first!==''?'¡Hola, '.$first.'! 👋 ':'').'Por ahora desde Sharky en Palapas puedo ayudarte a confirmar si tienes clase hoy. Para cualquier otro tema, el equipo de Hache lo revisa contigo.';
        }
        $payload=hache_sharky_member_buttons($contact,$body,[['id'=>'member:class_today','title'=>'Mi clase hoy']]);
        return hache_sharky_member_queue($pdo,$contact,$event,$state,$payload,'palapas-restricted-home');
    }catch(Throwable $e){
        hache_sharky_db_state_defer_cancel();
        error_log('[sharky-member-routing] Palapas restriction failed');
        return false;
    }finally{
        hache_sharky_lab_release_delivery_lock($deliveryLock);
    }
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
        $text=trim((string)($event['text']??''));
        $intent=(string)(hache_sharky_member_intent($text,(string)($event['interactive_id']??''))??'');
        $first=function_exists('hache_sharky_member_first_name')?hache_sharky_member_first_name($student):'';

        if(hache_sharky_member_closure_text($text)){
            $body='¡Con gusto'.($first!==''?', '.$first:'').'! 😊';
            $payload=hache_sharky_whatsapp_text_payload($contact,$body);
            return hache_sharky_member_queue($pdo,$contact,$event,$state,$payload,'student-close');
        }

        if(hache_sharky_member_pending_registration($student)){
            $body=hache_sharky_member_pending_message($student,$text,$intent);
            $payload=hache_sharky_whatsapp_text_payload($contact,$body);
            return hache_sharky_member_queue($pdo,$contact,$event,$state,$payload,'student-pending');
        }

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

    // Explicit human requests from a Palapas student must leave member-ops
    // before the payment processor sees the same text (e.g. "persona + pago").
    $routeIdentity=hache_sharky_business_identity_by_whatsapp($pdo,$contact);
    if(($routeIdentity['found']??false)===true
        && strtoupper((string)($routeIdentity['sede_clave']??''))==='PALAPAS'
        && hache_sharky_member_routing_handoff_requested((string)($event['text']??'')))return null;

    // Palapas is intentionally intercepted before member payments so no
    // accounting flow can open or resume while the temporary red light is on.
    $palapas=hache_sharky_member_palapas_restricted_route($pdo,$event);
    if($palapas!==null)return $palapas;

    $paymentResult=hache_sharky_member_payment_process_event($pdo,$event,$business);
    if($paymentResult!==null)return $paymentResult;
    if(!hache_sharky_member_supported_event($pdo,$event))return null;

    try{$state=hache_sharky_db_state_load($pdo,$contact);}catch(Throwable $e){return null;}
    $text=(string)($event['text']??'');
    $interactiveId=(string)($event['interactive_id']??'');
    $intent=(string)(hache_sharky_member_intent($text,$interactiveId)??'');

    // A record in alumnos is identity, not proof of an active enrolment. People
    // created by the intensive checkout remain PENDIENTE until staff validates
    // the payment. They may still review/complete payment, but must not receive
    // active-student class/absence/reposition controls meanwhile.
    $teacher=hache_sharky_member_teacher_by_whatsapp($pdo,$contact);
    $activeFlow=hache_sharky_member_flow($state);
    $teacherFlow=is_array($activeFlow)&&str_starts_with((string)($activeFlow['name']??''),'teacher_');
    $teacherIntent=($teacher['found']??false)===true&&($teacherFlow||in_array($intent,['greeting','teacher_agenda','teacher_cancel','teacher_cancel_select','member:tc_confirm','member:tc_abort'],true));
    if(!$teacherIntent){
        $student=hache_sharky_member_student_context($pdo,$contact);
        if(($student['found']??false)===true&&hache_sharky_member_pending_registration($student)&&$intent!=='payments'){
            return hache_sharky_member_student_fallback($pdo,$event);
        }
    }

    if(hache_sharky_member_deterministic_event($pdo,$event,$state)){
        return hache_sharky_member_process_event($pdo,$event,$business);
    }

    return hache_sharky_member_student_fallback($pdo,$event);
}
