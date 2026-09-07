<?php

declare(strict_types=1);

require __DIR__.'/../config/sharky-conversation-brain.php';
require __DIR__.'/../config/sharky-brain-diagnostics.php';

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

function brain_state(string $identity='unknown',?string $program=null,?string $venue=null,?array $flow=null,bool $paused=false): array
{
    $state=[
        'identity'=>['kind'=>$identity,'verified'=>$identity!=='unknown'],
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

brain_eq(HACHE_SHARKY_BRAIN_VERSION,'3.0-shadow-v1','Brain version must be explicit and stable for shadow comparisons.');
brain_eq(hache_sharky_brain_precedence()[0],'wait_for_human','Human takeover must be the highest-priority Brain policy.');
brain_eq(hache_sharky_brain_precedence()[1],'handoff_known_student','Known-student policy must win before commercial automation.');

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
    'decision_kind'=>'conversation','human_takeover_active'=>true,'known_student'=>true,'pause_eligible'=>true,
]);
brain_eq($decision['action'],'wait_for_human','Human takeover must silence every lower-priority policy.');

$student=brain_state('student');
$decision=hache_sharky_brain_next_best_action($unknown,$student,['text'=>'Hola'],[
    'known_student'=>true,'direct_chat'=>true,'pause_eligible'=>true,
]);
brain_eq($decision['action'],'handoff_known_student','Known student must not enter prospect pause/commercial logic.');

$decision=hache_sharky_brain_next_best_action($unknown,$prospect,['text'=>'Bebé'],[
    'family_age_unavailable'=>true,'pause_eligible'=>true,
]);
brain_eq($decision['action'],'close_age_scope','Age-scope closure must outrank a lower commercial pause signal.');

$decision=hache_sharky_brain_next_best_action($ready,$ready,['text'=>'Ahora no'],[
    'pause_requested'=>true,'pause_eligible'=>true,'policy_handoff_required'=>true,
]);
brain_eq($decision['action'],'pause_commercial_intent','An eligible supported pause must be deterministic and must not open a new commercial action.');

// Raw recognition alone is not enough: first-contact/stale pause requests do not
// satisfy the live pause guard and therefore must keep normal identity routing.
$decision=hache_sharky_brain_next_best_action($unknown,$unknown,['text'=>'Ahora no'],[
    'decision_kind'=>'conversation','pause_requested'=>true,'pause_eligible'=>false,
]);
brain_eq($decision['action'],'ask_identity','Ineligible pause recognition must not terminate an unknown conversation.');

$decision=hache_sharky_brain_next_best_action($flow,$flow,['text'=>'¿Cuánto cuesta?'],[
    'side_question'=>true,'decision_kind'=>'conversation',
]);
brain_eq($decision['action'],'answer_side_question','A side question must temporarily outrank the active controlled flow.');
brain_eq($decision['route'],'conversation','Side questions are answered conversationally while preserving state.');

$decision=hache_sharky_brain_next_best_action($flow,$flow,['text'=>'Sí'],['decision_kind'=>'conversation']);
brain_eq($decision['action'],'continue_controlled_flow','An active controlled flow must remain authoritative.');

$decision=hache_sharky_brain_next_best_action($prospect,$prospect,['text'=>'x'],['decision_kind'=>'weather_cancellation_policy']);
brain_eq($decision['action'],'preserve_deterministic_decision','A specific deterministic decision must not be replaced by generic Brain guidance.');

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

$decision=hache_sharky_brain_next_best_action($unknown,$unknown,['text'=>'Hola'],['decision_kind'=>'conversation']);
brain_eq($decision['action'],'ask_identity','Unknown identity must remain the first guided slot.');

$decision=hache_sharky_brain_next_best_action($prospect,$partial,['text'=>'Intensivo'],['decision_kind'=>'conversation']);
brain_eq($decision['action'],'continue_discovery','Incomplete prospect context must continue discovery instead of inventing a transaction.');

$decision=hache_sharky_brain_next_best_action($ready,$ready,['text'=>'¿Qué equipo necesito?'],['decision_kind'=>'conversation']);
brain_eq($decision['action'],'answer_user','A normal informational question with ready context must be answered without forcing a menu.');

brain_ok(hache_sharky_brain_shadow_matches('answer_user',$decision),'Shadow comparator must report exact policy matches.');
brain_ok(!hache_sharky_brain_shadow_matches('show_commercial_menu',$decision),'Shadow comparator must expose policy divergences.');

// Diagnostic keys are bounded enumerations, never user text or state values.
brain_eq(hache_sharky_brain_diag_metric_key('answer_user','continue_discovery'),'brain_mm_13_12','Mismatch metric key must use stable numeric action codes.');
brain_eq(hache_sharky_brain_diag_metric_key('anything-unexpected','answer_user'),'brain_mm_00_13','Unknown actions must collapse into the protected unknown bucket.');

$candidate=hache_sharky_brain_diag_report([['date'=>'2026-09-07','counters'=>[
    'brain_diag_observed'=>60,
    'brain_mm_13_12'=>4,
]]]);
brain_eq($candidate['observed'],60,'Diagnostic report must count the post-diagnostic cohort only.');
brain_eq($candidate['mismatches'],4,'Diagnostic report must aggregate categorized mismatches.');
brain_eq($candidate['matches'],56,'Diagnostic report must derive matches from observed minus mismatches.');
brain_eq($candidate['agreement_pct'],93.3,'Diagnostic report must calculate agreement percentage.');
brain_eq($candidate['blocking_mismatches'],0,'Low-risk discovery mismatch must not be marked protected.');
brain_eq($candidate['status'],'candidate','A mature clean cohort may become a Phase 2B candidate without auto-activating routing.');
brain_ok($candidate['routing_live']===false,'Diagnostic readiness must never activate live Brain routing.');

$blocking=hache_sharky_brain_diag_report([['counters'=>[
    'brain_diag_observed'=>60,
    'brain_mm_07_13'=>1,
]]]);
brain_eq($blocking['blocking_mismatches'],1,'A controlled-flow divergence must block live candidacy.');
brain_eq($blocking['status'],'review_blocking','Protected divergences must require review.');

$collecting=hache_sharky_brain_diag_report([['counters'=>['brain_diag_observed'=>12]]]);
brain_eq($collecting['status'],'collecting','Small cohorts must remain in evidence collection.');
brain_eq($collecting['remaining_observations'],38,'Readiness must show how many observations remain.');

$errored=hache_sharky_brain_diag_report([['counters'=>['brain_diag_observed'=>60,'brain_diag_error'=>1]]]);
brain_eq($errored['status'],'review_errors','Any observer error must block readiness.');

$shadowSource=(string)file_get_contents(__DIR__.'/../config/sharky-brain-shadow-runtime.php');
brain_ok(str_contains($shadowSource,"hache_sharky_metric_increment('brain_diag_observed')"),'Live shadow observer must start the diagnostic observation cohort.');
brain_ok(str_contains($shadowSource,'hache_sharky_brain_diag_metric_key($live,$brainAction)'),'Live mismatches must be classified by bounded action pair.');
brain_ok(!str_contains($shadowSource,"hache_sharky_metric_increment('brain_diag_match')"),'Diagnostic matches are derived, avoiding redundant counters.');

fwrite(STDOUT,"SHARKY_CONVERSATION_BRAIN_OK\n");
