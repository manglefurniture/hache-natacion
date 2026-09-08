<?php

declare(strict_types=1);

require __DIR__.'/../config/sharky-conversation-brain.php';
require __DIR__.'/../config/sharky-brain-diagnostics.php';
require __DIR__.'/../config/sharky-brain-shadow-runtime.php';

function brain_ok(bool $condition,string $message): void
{
    if(!$condition){fwrite(STDERR,"SHARKY BRAIN FAIL: $message\n");exit(1);}
}
function brain_eq(mixed $actual,mixed $expected,string $message): void
{
    if($actual!==$expected){
        fwrite(STDERR,"SHARKY BRAIN FAIL: $message\nExpected: ".var_export($expected,true)."\nActual: ".var_export($actual,true)."\n");
        exit(1);
    }
}

function brain_state(string $identity='unknown',?string $program=null,?string $venue=null,?array $flow=null,bool $paused=false,?string $identityVenue=null,?string $identityStatus=null): array
{
    $state=[
        'identity'=>[
            'kind'=>$identity,
            'verified'=>$identity!=='unknown',
            'sede_clave'=>$identityVenue,
            'status'=>$identityStatus,
        ],
        'commercial_context'=>[
            'program'=>$program,
            'sede_clave'=>$venue,
            'age'=>null,
            'swim_level'=>null,
        ],
        'flow'=>$flow,
    ];
    if($paused)$state['commercial_context']['_idle_followup']=[
        'status'=>'completed_optout','token'=>null,'next_stage'=>null,'pause_reason'=>'user_now_not',
    ];
    return $state;
}

brain_eq(HACHE_SHARKY_BRAIN_VERSION,'3.0-shadow-v3','Brain version must identify the member-routing policy.');
brain_eq(HACHE_SHARKY_BRAIN_DIAG_COHORT,'v3','Readiness must use a fresh v3 cohort after changing member ownership.');
$precedence=hache_sharky_brain_precedence();
brain_eq($precedence[0],'wait_for_human','Human takeover must remain the highest-priority Brain policy.');
brain_ok(array_search('serve_known_student',$precedence,true)<array_search('close_age_scope',$precedence,true),'Member service must win before prospect policy.');
brain_ok(array_search('pause_commercial_intent',$precedence,true)<array_search('handoff_policy_exception',$precedence,true),'Existing prospect pause precedence must remain unchanged.');

$ready=brain_state('prospect','intensive','PALAPAS');
$snapshot=hache_sharky_brain_snapshot($ready);
brain_eq($snapshot['phase'],'commercial_ready','Program + venue must classify a prospect as commercially ready.');
brain_ok($snapshot['commercial_ready']===true,'Commercial readiness must not require age.');
brain_eq($snapshot['age'],null,'Age remains optional when the prospect did not volunteer it.');

$readyAge=$ready;$readyAge['commercial_context']['age']=59;$readyAge['commercial_context']['swim_level']='beginner';
$snapshot=hache_sharky_brain_snapshot($readyAge);
brain_eq($snapshot['age'],59,'Volunteered age must be visible to the Brain snapshot.');
brain_eq($snapshot['swim_level'],'beginner','Swimming level must be visible to the Brain snapshot.');

$paused=brain_state('prospect','regular','MONTEVERDE',null,true);
brain_ok(hache_sharky_brain_followup_paused($paused),'A completed opt-out follow-up state must be visible as paused.');
brain_ok(hache_sharky_brain_snapshot($paused)['commercial_ready']===true,'Pausing follow-ups must not erase commercial context.');

$unknown=brain_state();
$prospect=brain_state('prospect');
$flow=brain_state('prospect','intensive','PALAPAS',['name'=>'register_intensive','step'=>'offer','data'=>[]]);

$decision=hache_sharky_brain_next_best_action($unknown,$prospect,['text'=>'Soy nuevo'],[
    'decision_kind'=>'conversation','human_takeover_active'=>true,'known_student'=>true,'member_service_available'=>true,'pause_eligible'=>true,
]);
brain_eq($decision['action'],'wait_for_human','Human takeover must silence every lower-priority policy.');

$studentMv=brain_state('student',null,null,null,false,'MONTEVERDE','ACTIVO');
$studentPal=brain_state('student',null,null,null,false,'PALAPAS','ACTIVO');
$studentPending=brain_state('student',null,null,null,false,'PALAPAS','PENDIENTE');

$decision=hache_sharky_brain_next_best_action($studentMv,$studentMv,['text'=>'Hola'],[
    'known_student'=>true,'direct_chat'=>true,'member_service_available'=>true,
]);
brain_eq($decision['action'],'serve_known_student','Known Monteverde student must stay in Sharky self-service.');

$decision=hache_sharky_brain_next_best_action($studentPal,$studentPal,['text'=>'Pagos'],[
    'known_student'=>true,'direct_chat'=>true,'member_service_available'=>true,'palapas_restricted'=>true,
]);
brain_eq($decision['action'],'serve_palapas_restricted','Palapas student must enter the red-light member lane instead of accounting.');

$decision=hache_sharky_brain_next_best_action($studentPending,$studentPending,['text'=>'¿Tengo clase?'],[
    'known_student'=>true,'direct_chat'=>true,'member_service_available'=>true,'member_pending'=>true,'palapas_restricted'=>true,
]);
brain_eq($decision['action'],'serve_pending_student','Pending registration must outrank active Palapas class controls.');

$teacherState=brain_state('unknown');
$decision=hache_sharky_brain_next_best_action($teacherState,$teacherState,['text'=>'Cancelar clase'],[
    'member_service_available'=>true,'teacher_member_event'=>true,'direct_chat'=>true,
]);
brain_eq($decision['action'],'serve_teacher','Teacher-owned member event must remain deterministic.');

$decision=hache_sharky_brain_next_best_action($studentMv,$studentMv,['text'=>'Hola'],[
    'known_student'=>true,'direct_chat'=>true,'member_service_available'=>false,
]);
brain_eq($decision['action'],'handoff_known_student','Known student needs a safe human fallback if member service is unavailable.');

$decision=hache_sharky_brain_next_best_action($unknown,$prospect,['text'=>'Bebé'],[
    'family_age_unavailable'=>true,'pause_eligible'=>true,
]);
brain_eq($decision['action'],'close_age_scope','Age-scope closure must outrank a lower commercial pause signal.');

$decision=hache_sharky_brain_next_best_action($ready,$ready,['text'=>'Ahora no'],[
    'pause_requested'=>true,'pause_eligible'=>true,'policy_handoff_required'=>true,
]);
brain_eq($decision['action'],'pause_commercial_intent','v3 must preserve the tested pause-before-policy-handoff prospect rule.');

$decision=hache_sharky_brain_next_best_action($unknown,$unknown,['text'=>'Hola'],[
    'decision_kind'=>'conversation','direct_chat'=>true,'default_prospect_if_unmatched'=>true,
]);
brain_eq($decision['action'],'start_guided_qualification','An unmatched direct WhatsApp contact must default to prospect instead of asking student status.');

$decision=hache_sharky_brain_next_best_action($unknown,$unknown,['text'=>'Hola'],[
    'decision_kind'=>'conversation','direct_chat'=>true,'default_prospect_if_unmatched'=>false,
]);
brain_eq($decision['action'],'ask_identity','Identity prompt remains a conservative fallback when unmatched-prospect authority is absent.');

$decision=hache_sharky_brain_next_best_action($flow,$flow,['text'=>'¿Cuánto cuesta?'],[
    'side_question'=>true,'decision_kind'=>'conversation',
]);
brain_eq($decision['action'],'answer_side_question','A conversational side question must temporarily outrank the active controlled flow.');
brain_eq($decision['route'],'conversation','Side questions are answered conversationally while preserving state.');

$decision=hache_sharky_brain_next_best_action($flow,$flow,['text'=>'Sí'],['decision_kind'=>'prospect_swim_prompt']);
brain_eq($decision['action'],'continue_controlled_flow','A deterministic prompt inside an active controlled flow must keep the controlled-flow diagnostic action.');

$decision=hache_sharky_brain_next_best_action($ready,$ready,['text'=>'¿Cuánto cuesta?'],[
    'side_question'=>true,'decision_kind'=>'weather_cancellation_policy',
]);
brain_eq($decision['action'],'preserve_deterministic_decision','Outside a controlled flow, a side-question heuristic must never override an already-selected protected decision.');

$decision=hache_sharky_brain_next_best_action($unknown,$prospect,['text'=>'Soy nuevo'],['decision_kind'=>'conversation']);
brain_eq($decision['action'],'start_guided_qualification','Unknown-to-prospect transition must start guided qualification exactly once.');

$partial=brain_state('prospect','intensive',null);
$decision=hache_sharky_brain_next_best_action($partial,$ready,['text'=>'Palapas'],[
    'decision_kind'=>'conversation','turn_discovery_only'=>true,
]);
brain_eq($decision['action'],'show_commercial_menu','Completing program + venue through discovery must expose the deterministic commercial menu.');

$decision=hache_sharky_brain_next_best_action($ready,$ready,['text'=>'Hola'],[
    'decision_kind'=>'conversation','low_information'=>true,
]);
brain_eq($decision['action'],'show_commercial_menu','Low-information reengagement with known commercial context must resume from the menu.');

$decision=hache_sharky_brain_next_best_action($prospect,$partial,['text'=>'Intensivo'],['decision_kind'=>'conversation']);
brain_eq($decision['action'],'continue_discovery','Incomplete prospect context must continue discovery instead of inventing a transaction.');

$decision=hache_sharky_brain_next_best_action($ready,$ready,['text'=>'¿Qué equipo necesito?'],['decision_kind'=>'conversation']);
brain_eq($decision['action'],'answer_user','A normal informational question with ready context must be answered without forcing a menu.');
brain_ok(hache_sharky_brain_shadow_matches('answer_user',$decision),'Shadow comparator must report exact policy matches.');

$earlyHandoff=hache_sharky_brain_shadow_evaluate($ready,$ready,['text'=>'Excepción'],[
    'decision'=>['kind'=>'early_policy_handoff','action'=>['type'=>'human_takeover']],
]);
brain_eq($earlyHandoff['live_action'],'handoff_policy_exception','Early policy handoff must map into the protected live vocabulary.');
brain_eq($earlyHandoff['brain']['action']??null,'handoff_policy_exception','Brain must evaluate early policy handoffs instead of leaving a coverage hole.');
brain_ok(($earlyHandoff['match']??false)===true,'Current Brain and live early handoff policy must agree.');

$silentTakeover=hache_sharky_brain_shadow_evaluate($ready,$ready,['text'=>'Hola'],[
    'decision'=>['kind'=>'silent_human_takeover'],
]);
brain_eq($silentTakeover['live_action'],'wait_for_human','Active manual takeover must be represented in shadow diagnostics.');
brain_eq($silentTakeover['brain']['action']??null,'wait_for_human','Brain must remain silent while a human owns the chat.');

$studentFallback=hache_sharky_brain_shadow_evaluate($studentMv,$studentMv,['text'=>'Hola'],[
    'decision'=>['kind'=>'student_human_takeover','action'=>['type'=>'human_takeover']],
]);
brain_eq($studentFallback['live_action'],'handoff_known_student','Legacy fallback must keep its explicit diagnostic action.');
brain_eq($studentFallback['brain']['action']??null,'handoff_known_student','Brain must agree with the safe fallback when member service did not own the turn.');

$memberMv=hache_sharky_brain_shadow_member_evaluate($studentMv,$studentMv,['text'=>'Hola'],'student');
brain_eq($memberMv['live_action'],'serve_known_student','Member observer must classify Monteverde self-service.');
brain_eq($memberMv['brain']['action']??null,'serve_known_student','Brain must agree with Monteverde member service.');
brain_ok(($memberMv['match']??false)===true,'Monteverde member lane must match.');

$memberPal=hache_sharky_brain_shadow_member_evaluate($studentPal,$studentPal,['text'=>'Pagos'],'palapas');
brain_eq($memberPal['brain']['action']??null,'serve_palapas_restricted','Brain must protect Palapas red-light routing.');
brain_ok(($memberPal['match']??false)===true,'Palapas member lane must match.');

$memberPending=hache_sharky_brain_shadow_member_evaluate($studentPending,$studentPending,['text'=>'¿Tengo clase?'],'palapas');
brain_eq($memberPending['brain']['action']??null,'serve_pending_student','Brain must protect pending registration before Palapas class controls.');
brain_ok(($memberPending['match']??false)===true,'Pending member lane must match.');

$memberTeacher=hache_sharky_brain_shadow_member_evaluate($teacherState,$teacherState,['text'=>'Motivo de cancelación'],'teacher');
brain_eq($memberTeacher['brain']['action']??null,'serve_teacher','Brain must protect teacher-owned member turns.');
brain_ok(($memberTeacher['match']??false)===true,'Teacher member lane must match.');

$realSideQuestion=hache_sharky_brain_shadow_evaluate($flow,$flow,['text'=>'¿Cuánto cuesta?'],[
    'decision'=>['kind'=>'side_question'],
]);
brain_eq($realSideQuestion['brain']['action']??null,'answer_side_question','Confirmed live side questions must remain conversational.');
brain_ok(($realSideQuestion['match']??false)===true,'Protected precedence must not regress legitimate side-question handling.');

$protectedHeuristic=hache_sharky_brain_shadow_evaluate($ready,$ready,['text'=>'¿Cuánto cuesta?'],[
    'decision'=>['kind'=>'weather_cancellation_policy'],
]);
brain_eq($protectedHeuristic['brain']['action']??null,'preserve_deterministic_decision','Heuristic side-question detection must not override a protected live decision.');
brain_ok(($protectedHeuristic['match']??false)===true,'Protected deterministic policy must still match in v3.');

brain_eq(hache_sharky_brain_diag_observed_metric_key(),'brain_diag_v3_observed','v3 must use a fresh observation counter.');
brain_eq(hache_sharky_brain_diag_metric_key('answer_user','continue_discovery'),'brain_v3_mm_17_16','Mismatch key must use stable v3 action codes.');
brain_eq(hache_sharky_brain_diag_metric_key('anything-unexpected','answer_user'),'brain_v3_mm_00_17','Unknown actions must collapse into the protected unknown bucket.');

$candidate=hache_sharky_brain_diag_report([['date'=>'2026-09-07','counters'=>[
    'brain_diag_v3_observed'=>60,
    'brain_v3_mm_17_16'=>4,
]]]);
brain_eq($candidate['cohort'],'v3','Diagnostic report must expose v3.');
brain_eq($candidate['observed'],60,'Diagnostic report must count only v3 observations.');
brain_eq($candidate['mismatches'],4,'Diagnostic report must aggregate v3 mismatches.');
brain_eq($candidate['matches'],56,'Diagnostic report must derive matches from observed minus mismatches.');
brain_eq($candidate['agreement_pct'],93.3,'Diagnostic report must calculate agreement percentage.');
brain_eq($candidate['blocking_mismatches'],0,'Low-risk discovery mismatch must not be marked protected.');
brain_eq($candidate['status'],'candidate','A mature clean v3 cohort may become a Phase 2B candidate without auto-activating routing.');
brain_ok($candidate['routing_live']===false,'Diagnostic readiness must never activate live Brain routing.');

$legacyIgnored=hache_sharky_brain_diag_report([['counters'=>[
    'brain_diag_observed'=>236,
    'brain_mm_08_06'=>1,
    'brain_diag_v2_observed'=>60,
    'brain_v2_mm_13_12'=>4,
    'brain_diag_v3_observed'=>12,
]]]);
brain_eq($legacyIgnored['observed'],12,'v1/v2 evidence must not satisfy the new v3 readiness gate.');
brain_eq($legacyIgnored['blocking_mismatches'],0,'Legacy protected mismatches must not contaminate v3.');
brain_eq($legacyIgnored['status'],'collecting','A fresh member-aware cohort must collect its own evidence.');

$blocking=hache_sharky_brain_diag_report([['counters'=>[
    'brain_diag_v3_observed'=>60,
    'brain_v3_mm_06_07'=>1,
]]]);
brain_eq($blocking['blocking_mismatches'],1,'A member-service vs handoff divergence must block live candidacy.');
brain_eq($blocking['status'],'review_blocking','Protected member divergences in v3 must require review.');

$collecting=hache_sharky_brain_diag_report([['counters'=>['brain_diag_v3_observed'=>12]]]);
brain_eq($collecting['status'],'collecting','Small v3 cohorts must remain in evidence collection.');
brain_eq($collecting['remaining_observations'],38,'Readiness must show how many v3 observations remain.');

$errored=hache_sharky_brain_diag_report([['counters'=>['brain_diag_v3_observed'=>60,'brain_diag_error'=>1]]]);
brain_eq($errored['status'],'review_errors','Any observer/pre-state error must still block v3 readiness.');

$shadowSource=(string)file_get_contents(__DIR__.'/../config/sharky-brain-shadow-runtime.php');
brain_ok(str_contains($shadowSource,'hache_sharky_brain_diag_observed_metric_key()'),'Live shadow observer must write to the v3 cohort.');
brain_ok(str_contains($shadowSource,'hache_sharky_brain_diag_metric_key($live,$brainAction)'),'Live mismatches must be classified by bounded v3 action pair.');
brain_ok(str_contains($shadowSource,'function hache_sharky_brain_shadow_member_evaluate'),'Brain shadow must understand deterministic member lanes.');
brain_ok(str_contains($shadowSource,'function hache_sharky_brain_shadow_observe_member'),'Production observer must expose a member-lane hook.');
brain_ok(str_contains($shadowSource,"['commercial_next_action','conversation_identity_prompt','side_question']"),'Confirmed side-question decisions must stay normalized as conversational policy.');

$routerSource=(string)file_get_contents(__DIR__.'/../config/sharky-member-routing.php');
brain_ok(str_contains($routerSource,'hache_sharky_brain_shadow_observe_member'),'Shared realtime/recovery member router must feed the read-only Brain observer.');
brain_ok(str_contains($routerSource,"'palapas'"),'Palapas route must have a distinct Brain observation lane.');
brain_ok(str_contains($routerSource,"\$teacherIntent?'teacher':'student'"),'Teacher and student deterministic routes must be distinguished in Brain evidence.');

$workerSource=(string)file_get_contents(__DIR__.'/../config/sharky-lab-worker.php');
brain_ok(preg_match("/brain_shadow_error'\);\s*hache_sharky_metric_increment\('brain_diag_error'\);\s*error_log\('\[sharky-brain-shadow\] unable to load pre-turn state'/s",$workerSource)===1,'Pre-turn state load failures must keep blocking diagnostic readiness across cohort versions.');
brain_ok(str_contains($workerSource,"'kind'=>'early_policy_handoff'")&&str_contains($workerSource,"'kind'=>'silent_human_takeover'"),'Protected early-return branches must feed the read-only shadow observer.');

fwrite(STDOUT,"SHARKY_CONVERSATION_BRAIN_OK\n");
