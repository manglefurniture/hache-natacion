<?php

declare(strict_types=1);

require __DIR__.'/../config/sharky-conversation-brain.php';
require __DIR__.'/../config/sharky-brain-diagnostics.php';
require __DIR__.'/../config/sharky-brain-shadow-runtime.php';

function pr156_ok(bool $condition,string $message): void
{
    if(!$condition){fwrite(STDERR,"SHARKY PR156 REVIEW FAIL: $message\n");exit(1);}
}
function pr156_eq(mixed $actual,mixed $expected,string $message): void
{
    if($actual!==$expected){
        fwrite(STDERR,"SHARKY PR156 REVIEW FAIL: $message\nExpected: ".var_export($expected,true)."\nActual: ".var_export($actual,true)."\n");
        exit(1);
    }
}

$unknown=[
    'identity'=>['kind'=>'unknown','verified'=>false],
    'commercial_context'=>[],
    'flow'=>null,
];

// P1: live member ownership comes from the database router. A first registered
// student turn may still have unknown/prospect durable conversation state, but
// the shadow observer must not classify that turn as a lead.
$firstStudent=hache_sharky_brain_shadow_member_evaluate($unknown,$unknown,['text'=>'Pagos'],'student');
pr156_eq($firstStudent['live_action'],'serve_known_student','Student lane must map to known-student service.');
pr156_eq($firstStudent['brain']['action']??null,'serve_known_student','Student lane authority must outrank stale durable identity.');
pr156_ok(($firstStudent['match']??false)===true,'First deterministic student turn must not contaminate v3 readiness.');

$firstPalapas=hache_sharky_brain_shadow_member_evaluate($unknown,$unknown,['text'=>'Hola'],'palapas');
pr156_eq($firstPalapas['brain']['action']??null,'serve_palapas_restricted','Palapas lane must remain restricted even before durable identity is persisted.');
pr156_ok(($firstPalapas['match']??false)===true,'First Palapas member turn must match.');

// P2: the realtime entrypoint persists unmatched direct contacts as prospects
// before the worker snapshots the turn. That production shape must use the
// reachable unmatched-prospect signal and agree with a normal live conversation.
$unmatched=$unknown;
$unmatched['identity']=[
    'kind'=>'prospect',
    'verified'=>false,
    'source'=>'whatsapp_unmatched',
    'student_id'=>null,
    'name'=>null,
    'sede_clave'=>null,
    'status'=>null,
];
$general=hache_sharky_brain_shadow_evaluate($unmatched,$unmatched,['text'=>'Hola'],[
    'decision'=>['kind'=>'conversation','action'=>null],
],true);
pr156_ok(($general['signals']['default_prospect_if_unmatched']??false)===true,'Production unmatched-prospect signal must be reachable after entrypoint persistence.');
pr156_eq($general['live_action'],'answer_user','Normal live conversation must retain the answer-user diagnostic action.');
pr156_eq($general['brain']['action']??null,'answer_user','Brain must agree with the already-classified unmatched prospect conversation.');
pr156_ok(($general['match']??false)===true,'Unmatched first conversation must not create a v3 mismatch.');

fwrite(STDOUT,"SHARKY_PR156_REVIEW_OK\n");
