<?php

declare(strict_types=1);

if(!function_exists('mb_substr')){function mb_substr(string $s,int $start,?int $length=null,?string $enc=null):string{return $length===null?substr($s,$start):substr($s,$start,$length);}}
if(!function_exists('mb_strlen')){function mb_strlen(string $s,?string $enc=null):int{return strlen($s);}}
if(!function_exists('mb_strtolower')){function mb_strtolower(string $s,?string $enc=null):string{return strtolower($s);}}

require_once __DIR__.'/../config/sharky-whatsapp-batching.php';
require_once __DIR__.'/../config/sharky-outbox.php';

function cross_feature_ok(bool $condition,string $message):void
{
    if(!$condition){fwrite(STDERR,"SHARKY CROSS FEATURE FAIL: $message\n");exit(1);}
}

// 1) Sales presentation owns a dedicated pause semantic. Generic flow:no must
// remain available to every controlled confirmation that means a literal “No”.
$salesPayload=[
    'messaging_product'=>'whatsapp',
    'recipient_type'=>'individual',
    'to'=>'529900000777',
    'type'=>'interactive',
    'interactive'=>[
        'type'=>'button',
        'body'=>['text'=>'¿Quieres que te ayude a registrarte al curso intensivo en Palapas Protudec?'],
        'action'=>['buttons'=>[
            ['type'=>'reply','reply'=>['id'=>'flow:yes','title'=>'Sí']],
            ['type'=>'reply','reply'=>['id'=>'flow:no','title'=>'No']],
            ['type'=>'reply','reply'=>['id'=>'flow:cancel','title'=>'Cancelar']],
        ]],
    ],
];
$salesClose=hache_sharky_outbox_add_sales_close($salesPayload);
$salesReplies=array_column($salesClose['interactive']['action']['buttons']??[],'reply');
cross_feature_ok(array_column($salesReplies,'id')===['flow:yes','action:human','flow:pause'],'Sales close must publish flow:pause, never repurpose generic flow:no.');

// 2) New pause ID, natural text and old already-sent sales button remain valid.
cross_feature_ok(hache_sharky_whatsapp_now_not_request(['text'=>'Ahora no','interactive_id'=>'flow:pause']),'Dedicated flow:pause must pause.');
cross_feature_ok(hache_sharky_whatsapp_now_not_request(['text'=>'Ahora no','interactive_id'=>'']),'Typed “Ahora no” must pause.');
cross_feature_ok(hache_sharky_whatsapp_now_not_request(['text'=>'Ahora no','interactive_id'=>'flow:no']),'Legacy already-sent flow:no + visible “Ahora no” must remain backward compatible.');

// 3) The collision that motivated this audit: a normal “No” button must never
// become a commercial pause merely because its historical ID is flow:no.
cross_feature_ok(!hache_sharky_whatsapp_now_not_request(['text'=>'No','interactive_id'=>'flow:no']),'Generic flow:no + “No” must keep rejection/cancellation semantics.');
cross_feature_ok(!hache_sharky_whatsapp_now_not_request(['text'=>'Cancelar','interactive_id'=>'flow:cancel']),'flow:cancel must remain cancellation, not pause.');

$now=1788740000;
$student=hache_sharky_orchestrator_state(null,$now);
$student['identity']=array_replace($student['identity'],[
    'kind'=>'student','verified'=>true,'source'=>'whatsapp_number','student_id'=>'stu-cross-1',
]);
$student=hache_sharky_orchestrator_flow($student,'absence','offer',[],$now);
$absenceNo=hache_sharky_orchestrate(
    $student,
    ['id'=>'cross.absence.no','from'=>'529900000777','type'=>'interactive','text'=>'No','interactive_id'=>'flow:no'],
    ['now'=>$now+1,'today'=>'2026-09-07']
);
cross_feature_ok(($absenceNo['decision']['kind']??'')==='flow_cancelled','Absence “No” must still cancel its controlled flow.');
cross_feature_ok(($absenceNo['state']['flow']??null)===null,'Absence “No” must clear the controlled flow instead of pausing it.');

// 4) Pausing a commercial conversation preserves useful context but invalidates
// idle sales follow-ups so the user is not chased after asking for space.
$prospect=hache_sharky_orchestrator_state(null,$now);
$prospect['identity']=array_replace($prospect['identity'],['kind'=>'prospect','verified'=>true,'source'=>'self_declared']);
$prospect['commercial_context']['program']='intensive';
$prospect['commercial_context']['sede_clave']='PALAPAS';
$prospect['commercial_context']['_idle_followup']=[
    'status'=>'armed','token'=>'cross-token','user_turn_at'=>$now-30,'sent_count'=>0,'next_stage'=>1,
    'first_due_at'=>$now+870,'first_sent_at'=>null,'second_due_at'=>null,'completed_at'=>null,
];
$paused=hache_sharky_whatsapp_mark_followup_paused($prospect,$now,'user_now_not');
cross_feature_ok(($paused['commercial_context']['program']??'')==='intensive','Pause must preserve program context.');
cross_feature_ok(($paused['commercial_context']['sede_clave']??'')==='PALAPAS','Pause must preserve venue context.');
cross_feature_ok(($paused['commercial_context']['_idle_followup']['status']??'')==='completed_optout','Pause must suppress pending idle follow-ups.');
cross_feature_ok(($paused['commercial_context']['_idle_followup']['token']??null)===null,'Pause must invalidate the follow-up token.');

// 5) Age-specific shortcut and greeting guard must not swallow neighbouring
// policies introduced by other PRs.
cross_feature_ok(hache_sharky_whatsapp_family_age_scope_request(['text'=>'¿Tienen clases para bebés?','interactive_id'=>'']),'Baby/matronatación request must keep its friendly deterministic shortcut.');
cross_feature_ok(!hache_sharky_whatsapp_family_age_scope_request(['text'=>'Es para una niña de 13 años','interactive_id'=>'']),'Eligible explicit child age must stay on the normal age policy.');

$studentTakeover=[
    'decision'=>hache_sharky_orchestrator_decision('student_human_takeover','¡Hola! 😊 Veo que este número ya está registrado como alumno.'),
    'payload'=>hache_sharky_whatsapp_text_payload('529900000777','¡Hola! 😊 Veo que este número ya está registrado como alumno.'),
];
$studentTakeoverClean=hache_sharky_whatsapp_strip_repeated_greeting_result($studentTakeover,true);
cross_feature_ok(($studentTakeoverClean['payload']['text']['body']??'')===($studentTakeover['payload']['text']['body']??''),'Repeated-greeting guard must not strip the deliberate one-time student handoff greeting.');

// 6) Operational answers still outrank the broad side-question heuristic.
cross_feature_ok(hache_sharky_whatsapp_batch_question_text('Pago el 50%')==='Pago el 50%','Payment choice must not be rewritten as an informational question.');
cross_feature_ok(hache_sharky_whatsapp_batch_question_text('Horario matutino')==='Horario matutino','Daypart choice must not be rewritten as an informational question.');

fwrite(STDOUT,"SHARKY_CROSS_FEATURE_COHERENCE_OK\n");
