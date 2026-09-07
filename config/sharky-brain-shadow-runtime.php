<?php

declare(strict_types=1);

require_once __DIR__.'/sharky-conversation-brain.php';
require_once __DIR__.'/sharky-brain-diagnostics.php';

function hache_sharky_brain_shadow_live_action(array $beforeState,array $afterState,array $result): string
{
    $decision=is_array($result['decision']??null)?$result['decision']:[];
    $kind=trim((string)($decision['kind']??''));
    $action=is_array($decision['action']??null)?$decision['action']:[];

    if($kind==='silent_human_takeover')return 'wait_for_human';
    if(in_array($kind,['student_human_takeover','existing_student_intensive_handoff'],true))return 'handoff_known_student';
    if($kind==='family_age_scope_unavailable')return 'close_age_scope';
    if($kind==='flow_paused')return 'pause_commercial_intent';
    if($kind==='side_question')return 'answer_side_question';
    if(($action['type']??'')==='human_takeover')return 'handoff_policy_exception';

    $after=hache_sharky_brain_snapshot($afterState);
    if(($after['flow_name']??null)!==null)return 'continue_controlled_flow';
    if($kind==='commercial_next_action')return 'show_commercial_menu';
    if($kind==='conversation_identity_prompt')return 'ask_identity';
    if($kind==='conversation')return 'answer_user';
    return 'preserve_deterministic_decision';
}

/**
 * Pure comparison used by both production observation and regression tests.
 * No metrics, logs, persistence, OpenAI calls or outbound side effects occur.
 *
 * @return array{live_action:string,brain:array<string,mixed>,match:bool,signals:array<string,mixed>,decision_kind:string}
 */
function hache_sharky_brain_shadow_evaluate(array $beforeState,array $afterState,array $event,array $result,bool $directChat=true): array
{
    $decision=is_array($result['decision']??null)?$result['decision']:[];
    $decisionKind=trim((string)($decision['kind']??''));
    $decisionAction=is_array($decision['action']??null)?$decision['action']:[];
    $knownStudent=in_array($decisionKind,['student_human_takeover','existing_student_intensive_handoff'],true);
    $rawPause=function_exists('hache_sharky_whatsapp_now_not_request')
        &&hache_sharky_whatsapp_now_not_request($event);
    $eligiblePause=$rawPause
        &&function_exists('hache_sharky_whatsapp_pause_eligible')
        &&hache_sharky_whatsapp_pause_eligible($beforeState,$event);

    // These adapter-owned presentation decisions are conversational policy for
    // Brain comparison. A real side_question is explicitly included so the new
    // protected-decision guard does not suppress legitimate flow interruptions.
    $brainDecisionKind=in_array($decisionKind,['commercial_next_action','conversation_identity_prompt','side_question'],true)
        ?'conversation':($decisionKind!==''?$decisionKind:'conversation');

    $heuristicSideQuestion=function_exists('hache_sharky_whatsapp_is_side_question')
        &&hache_sharky_whatsapp_is_side_question($beforeState,$event);
    $sideQuestion=$decisionKind==='side_question'
        ||($brainDecisionKind==='conversation'&&$heuristicSideQuestion);

    $signals=[
        'decision_kind'=>$brainDecisionKind,
        'direct_chat'=>$directChat,
        'human_takeover_active'=>$decisionKind==='silent_human_takeover',
        'known_student'=>$knownStudent,
        'family_age_unavailable'=>$decisionKind==='family_age_scope_unavailable',
        'pause_requested'=>$rawPause,
        'pause_eligible'=>$eligiblePause,
        'policy_handoff_required'=>(($decisionAction['type']??'')==='human_takeover'&&!$knownStudent),
        'side_question'=>$sideQuestion,
        'low_information'=>function_exists('hache_sharky_whatsapp_low_information_reengagement')
            &&hache_sharky_whatsapp_low_information_reengagement((string)($event['text']??'')),
        'turn_discovery_only'=>function_exists('hache_sharky_whatsapp_turn_is_discovery_only')
            &&hache_sharky_whatsapp_turn_is_discovery_only((string)($event['text']??'')),
    ];

    $brain=hache_sharky_brain_next_best_action($beforeState,$afterState,$event,$signals);
    $live=hache_sharky_brain_shadow_live_action($beforeState,$afterState,$result);
    return [
        'live_action'=>$live,
        'brain'=>$brain,
        'match'=>hache_sharky_brain_shadow_matches($live,$brain),
        'signals'=>$signals,
        'decision_kind'=>$decisionKind,
    ];
}

/** Read-only production shadow observer. */
function hache_sharky_brain_shadow_observe(array $beforeState,array $afterState,array $event,array $result,bool $directChat=true): void
{
    try{
        $evaluation=hache_sharky_brain_shadow_evaluate($beforeState,$afterState,$event,$result,$directChat);
        $brain=is_array($evaluation['brain']??null)?$evaluation['brain']:[];
        $live=(string)($evaluation['live_action']??'unknown');
        $brainAction=(string)($brain['action']??'unknown');
        $match=($evaluation['match']??false)===true;
        $decisionKind=(string)($evaluation['decision_kind']??'');

        if(function_exists('hache_sharky_metric_increment')){
            // Lifetime counters remain continuous across Brain policy versions.
            hache_sharky_metric_increment('brain_shadow_observed');
            hache_sharky_metric_increment($match?'brain_shadow_match':'brain_shadow_mismatch');

            // Readiness uses a fresh v2 cohort so the reviewed v1 protected
            // mismatch cannot linger in the activation gate after this fix.
            hache_sharky_metric_increment(hache_sharky_brain_diag_observed_metric_key());
            if(!$match)hache_sharky_metric_increment(hache_sharky_brain_diag_metric_key($live,$brainAction));
        }
        if(!$match){
            error_log('[sharky-brain-shadow] mismatch live='.$live.' brain='.$brainAction
                .' reason='.(string)($brain['reason']??'unknown').' decision='.$decisionKind);
        }
    }catch(Throwable $e){
        if(function_exists('hache_sharky_metric_increment')){
            hache_sharky_metric_increment('brain_shadow_error');
            hache_sharky_metric_increment('brain_diag_error');
        }
        error_log('[sharky-brain-shadow] observer failed');
    }
}
