<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-whatsapp-batching.php';

function conversation_cases_ok(bool $condition,string $message): void
{
    if(!$condition){fwrite(STDERR,"SHARKY CONVERSATION CASES FAIL: $message\n");exit(1);}
}

$babyEvent=['text'=>'Quiero saber si es para bebés','interactive_id'=>''];
conversation_cases_ok(hache_sharky_whatsapp_family_age_scope_request($babyEvent),'A direct baby/matronatación question must enter the gentle age-scope reply.');
$babyMessage=hache_sharky_whatsapp_family_age_scope_message(12);
$babyNormalized=hache_sharky_orchestrator_normalize($babyMessage);
conversation_cases_ok(str_contains($babyNormalized,'gracias por preguntar'),'The age-scope reply must open politely.');
conversation_cases_ok(str_contains($babyNormalized,'matronatacion'),'The reply must name matronatación explicitly.');
conversation_cases_ok(str_contains($babyNormalized,'12 anos'),'The reply must preserve the current minimum age.');
conversation_cases_ok(!str_contains($babyNormalized,'tu caso seria'),'The baby reply must not push the prospect into another qualification question.');

conversation_cases_ok(hache_sharky_whatsapp_now_not_request(['text'=>'Ahora no','interactive_id'=>'']),'Natural “Ahora no” must be recognized as a pause.');
conversation_cases_ok(hache_sharky_whatsapp_now_not_request(['text'=>'Ahora no','interactive_id'=>'flow:no']),'The flow:no button labelled “Ahora no” must be recognized as a pause.');
conversation_cases_ok(!hache_sharky_whatsapp_now_not_request(['text'=>'No','interactive_id'=>'']),'A generic “No” must keep its existing semantics instead of becoming a pause globally.');

$now=1788736000;
$state=hache_sharky_orchestrator_state(null,$now);
$state['identity']=array_replace($state['identity'],['kind'=>'prospect','verified'=>true,'source'=>'self_declared']);
$state['commercial_context']['program']='intensive';
$state['commercial_context']['sede_clave']='PALAPAS';
$state['commercial_context']['_idle_followup']=[
    'status'=>'armed','token'=>'old-token','user_turn_at'=>$now-100,'sent_count'=>0,
    'next_stage'=>1,'first_due_at'=>$now+100,'first_sent_at'=>null,'second_due_at'=>null,'completed_at'=>null,
];
$paused=hache_sharky_whatsapp_mark_followup_paused($state,$now,'user_now_not');
conversation_cases_ok(($paused['commercial_context']['program']??null)==='intensive','Pausing must preserve the selected program.');
conversation_cases_ok(($paused['commercial_context']['sede_clave']??null)==='PALAPAS','Pausing must preserve the selected venue.');
$followup=$paused['commercial_context']['_idle_followup']??[];
conversation_cases_ok(($followup['status']??null)==='completed_optout','Pausing must make already-armed idle follow-ups ineligible for delivery.');
conversation_cases_ok(($followup['token']??'not-null')===null&&($followup['next_stage']??'not-null')===null,'Pausing must clear the active follow-up token and stage.');
conversation_cases_ok(($followup['first_due_at']??'not-null')===null&&($followup['second_due_at']??'not-null')===null,'Pausing must clear scheduled follow-up due times.');
conversation_cases_ok(($followup['pause_reason']??null)==='user_now_not','The follow-up state must record why it was paused.');

$contact='529983994917';
$answer="¡Hola!\n\nPara tu intensivo en Palapas Protudec solo necesitas gorro y goggles.";
$result=[
    'state'=>$state,
    'decision'=>['kind'=>'conversation','message'=>$answer,'ui'=>[],'action'=>null],
    'payload'=>hache_sharky_whatsapp_text_payload($contact,$answer),
    'action_result'=>null,
];
$scrubbed=hache_sharky_whatsapp_strip_repeated_greeting_result($result,true);
$scrubbedBody=(string)($scrubbed['payload']['text']['body']??'');
conversation_cases_ok(!str_starts_with(hache_sharky_orchestrator_normalize($scrubbedBody),'hola'),'A later conversational answer must not repeat a generic greeting.');
conversation_cases_ok(str_contains($scrubbedBody,'Para tu intensivo'),'Removing a repeated greeting must preserve the substantive answer.');
$fresh=hache_sharky_whatsapp_strip_repeated_greeting_result($result,false);
conversation_cases_ok(str_contains((string)($fresh['payload']['text']['body']??''),'¡Hola!'),'A genuinely fresh conversation may keep its first greeting.');

$studentResult=$result;
$studentResult['decision']['kind']='student_human_takeover';
$studentResult['payload']=hache_sharky_whatsapp_text_payload($contact,'¡Hola! 😊 Veo que este número ya está registrado como alumno de Hache Natación.');
$studentGreeting=hache_sharky_whatsapp_strip_repeated_greeting_result($studentResult,true);
conversation_cases_ok(str_contains((string)($studentGreeting['payload']['text']['body']??''),'¡Hola!'),'The one-time known-student handoff greeting must not be stripped.');

$source=(string)file_get_contents(__DIR__.'/../config/sharky-whatsapp-batching.php');
$identityCheck=strpos($source,'$knownIdentity=$directChat?hache_sharky_business_identity_by_whatsapp');
$normalProcess=strpos($source,'$result=hache_sharky_whatsapp_process($pdo,$event,$conversationAnswer,$extraContext);');
conversation_cases_ok($identityCheck!==false&&$normalProcess!==false&&$identityCheck<$normalProcess,'Known WhatsApp students must be routed before the generic conversational model path.');
conversation_cases_ok(str_contains($source,"'student_human_takeover'")&&str_contains($source,"'STUDENT_HUMAN_TAKEOVER'"),'Known students must receive the current human-handoff policy.');
conversation_cases_ok(str_contains($source,"'flow_paused'")&&str_contains($source,"'FAMILY_AGE_SCOPE_UNAVAILABLE'"),'The two new deterministic conversation outcomes must stay wired into the batching layer.');

echo "SHARKY_CONVERSATION_CASES_OK\n";
