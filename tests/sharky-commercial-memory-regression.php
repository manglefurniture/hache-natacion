<?php

declare(strict_types=1);
require_once __DIR__.'/../config/sharky-lab-worker.php';
require_once __DIR__.'/../config/sharky-deterministic-replies.php';
function memory_ok(bool $ok,string $why): void { if(!$ok)throw new RuntimeException($why); }
putenv('SHARKY_STATE_ENCRYPTION_KEY='.str_repeat('test-memory-key-',3));
$today='2026-09-07';$now=1788796800;
$catalog=[
    'plans'=>[['id'=>'p3','nombre'=>'Regular 3','sesiones_semana'=>3,'precio'=>1000],['id'=>'p5','nombre'=>'Regular 5','sesiones_semana'=>5,'precio'=>1200]],
    'schedules'=>[['id'=>'h7','label'=>'07:00–08:00'],['id'=>'h20','label'=>'20:00–21:00']],
    'courses'=>[['id'=>'c14','sede_clave'=>'PALAPAS','fecha_inicio'=>'2026-09-14','precio'=>1350,'schedules'=>[['id'=>'h20','label'=>'20:00–21:00']]]],
];
function memory_new(): array {
    $s=hache_sharky_orchestrator_state();
    $s['identity']=array_replace($s['identity'],['kind'=>'prospect','verified'=>false,'source'=>'whatsapp_unmatched']);
    return $s;
}
function memory_turn(array $state,string $text,array $catalog,string $answer='Continuamos.'): array {
    $before=$state['commercial_context'];
    $state=hache_sharky_orchestrator_capture_commercial_context($state,$text);
    $state=hache_sharky_whatsapp_apply_natural_venue_preference($state,$text);
    $state=hache_sharky_whatsapp_apply_natural_swim_level($state,$text);
    $state=hache_sharky_commercial_invalidate($state,$before);
    $state=hache_sharky_commercial_capture($state,$text,$catalog,'2026-09-07');
    $event=['id'=>'turn:'.hash('sha256',$text),'text'=>$text];
    $result=hache_sharky_orchestrate($state,$event,['now'=>1788796800]);$state=$result['state'];
    memory_ok($result['decision']['kind']!=='conversation_identity_prompt','Unmatched prospect must never be asked if already a student');
    $answer=hache_sharky_whatsapp_enforce_confirmed_context($answer,$state);
    $decision=hache_sharky_commercial_reply($state,$answer,$catalog);
    $payload=hache_sharky_whatsapp_render('529981112233',$decision);
    $payload=hache_sharky_lab_present_once($payload,$state,$text);
    $deferred=hache_sharky_lab_mark_presentation_queued(['state'=>$state],$payload);
    // Real encrypted durable-state representation between every turn; no history inference.
    $sealed=hache_sharky_db_state_encrypt($deferred['state']);
    $loaded=hache_sharky_db_state_decrypt(['state_ciphertext'=>$sealed['ciphertext'],'state_iv'=>$sealed['iv'],'state_tag'=>$sealed['tag']]);
    memory_ok(is_array($loaded),'Encrypted roundtrip');
    return [hache_sharky_orchestrator_state($loaded),$payload,$decision];
}
$ivan=memory_new();$introductions=0;
foreach(['Buenas noches','Clases regulares','Monteverde','5 clases','7-8'] as $text){
    [$ivan,$payload,$decision]=memory_turn($ivan,$text,$catalog);
    $introductions+=substr_count(hache_sharky_draft_payload_text($payload),'Soy Sharky');
}
memory_ok($introductions===1,'Iván: exactly one AI disclosure');
$c=$ivan['commercial_context'];
memory_ok($c['program']==='regular'&&$c['sede_clave']==='MONTEVERDE','Iván: program and venue');
memory_ok($c['plan_id']==='p5'&&$c['plan_name']==='Regular 5'&&$c['sessions_per_week']===5,'Iván: backend plan authority');
memory_ok($c['schedule_id']==='h7'&&$c['schedule_label']==='07:00–08:00','Iván: persisted schedule');
memory_ok(($decision['ui']['buttons'][0]['id']??'')==='action:human','Regular immediate controlled handoff CTA');
foreach(['¿Y el costo?','¿Puedo ir con traje de baño completo?'] as $text){
    [$ivan,$payload,$decision]=memory_turn($ivan,$text,$catalog,'Sí, puedes. ¿Prefieres 3 o 5 clases por semana? ¿Qué horario prefieres?');
    memory_ok(hache_sharky_commercial_snapshot($ivan)===hache_sharky_commercial_snapshot(['commercial_context'=>$c]),'Side question preserves every confirmed selection');
    $body=hache_sharky_draft_payload_text($payload);
    memory_ok(!str_contains($body,'3 o 5')&&!str_contains($body,'Qué horario'),'No repeated plan or schedule questions');
}
$price=hache_sharky_deterministic_price_message($ivan);
memory_ok(str_contains((string)$price,'Regular 5')&&str_contains((string)$price,'1,200')&&!str_contains((string)$price,'1,000'),'Selected plan price only');
[$changed]=memory_turn($ivan,'Prefiero 3 clases',$catalog);
memory_ok($changed['commercial_context']['plan_id']==='p3'&&$changed['commercial_context']['schedule_id']==='h7','Explicit plan change keeps independent valid schedule');
[$changed]=memory_turn($ivan,'Mejor Palapas',$catalog);
memory_ok(empty($changed['commercial_context']['plan_id'])&&empty($changed['commercial_context']['schedule_id']),'Venue change invalidates venue-bound ids');
$ambiguous=$catalog;$ambiguous['plans'][]=['id'=>'p5other','nombre'=>'Regular Plus','sesiones_semana'=>5,'precio'=>1600];
$base=memory_new();$base['commercial_context']=['program'=>'regular','sede_clave'=>'MONTEVERDE'];
[$amb]=memory_turn($base,'5 clases',$ambiguous);
memory_ok(empty($amb['commercial_context']['plan_id'])&&$amb['commercial_context']['sessions_per_week']===5,'Same-frequency plans must not be guessed');
[$amb]=memory_turn($amb,'Regular Plus',$ambiguous);
memory_ok($amb['commercial_context']['plan_id']==='p5other','Disambiguation by actual backend name');
$ignacia=memory_new();$introductions=0;
foreach(['Hola','Quiero aprender a nadar','Tuve una mala experiencia, casi me ahogo de niña','Cuando no siento el piso me agito','Puedo estar en el agua','En Palapas','¿Puedo ir con traje de baño completo?','Ya tengo gorro y goggles','Quiero 8 de la noche','¿Y el costo?','Quiero iniciar lunes 14 de septiembre de 2026'] as $text){
    [$ignacia,$payload,$decision]=memory_turn($ignacia,$text,$catalog,'Te acompaño paso a paso. ¿Ya sabes nadar o estás empezando desde cero?');
    $body=hache_sharky_draft_payload_text($payload);$introductions+=substr_count($body,'Soy Sharky');
    if($text!=='Hola')memory_ok(!str_contains($body,'desde cero?'),'Ignacia: no repeated swim-level question');
}
$c=$ignacia['commercial_context'];
memory_ok($introductions===1,'Ignacia: disclosure once');
memory_ok($c['swim_level']==='beginner'&&$c['program']==='intensive'&&$c['sede_clave']==='PALAPAS','Ignacia: semantic beginner consolidated');
memory_ok($c['schedule_id']==='h20'&&$c['schedule_label']==='20:00–21:00','Ignacia: evening schedule');
memory_ok($c['course_id']==='c14'&&$c['fecha_inicio']==='2026-09-14','Ignacia: backend course and date');
memory_ok($c['kit']===['gorro'=>true,'goggles'=>true],'Ignacia: own kit remembered');
memory_ok(($decision['ui']['buttons'][0]['id']??'')==='action:register_intensive','Ignacia: immediate Inscribirme, no follow-up wait');
[$mixedDate]=memory_turn(array_replace($ignacia,['commercial_context'=>array_diff_key($c,array_flip(['course_id','fecha_inicio','course_price']))]),"¿Y el costo?\nQuiero iniciar lunes 14 de septiembre de 2026",$catalog);
memory_ok($mixedDate['commercial_context']['course_id']==='c14','A side question cannot hide a date declaration in the same burst');
$registration=hache_sharky_orchestrate($ignacia,['id'=>'register','interactive_id'=>'action:register_intensive'],['now'=>$now,'intensive_options'=>$catalog['courses']]);
memory_ok($registration['decision']['kind']==='registration_offer','Registration still requires explicit consent');
$registration=hache_sharky_orchestrate($registration['state'],['id'=>'yes','interactive_id'=>'flow:yes'],['now'=>$now,'intensive_options'=>$catalog['courses']]);
memory_ok($registration['state']['flow']['step']==='name','Validated course and schedule not re-asked in controlled flow');
memory_ok($registration['decision']['action']===null,'No enrollment mutation without identity, birthdate and confirmation');
$scheduleOnly=$ignacia;unset($scheduleOnly['commercial_context']['course_id'],$scheduleOnly['commercial_context']['fecha_inicio']);
[$scheduleOnly]=$pair=hache_sharky_orchestrator_registration_course_step($scheduleOnly,['sede_clave'=>'PALAPAS'],['intensive_options'=>$catalog['courses']],$now);
$selected=hache_sharky_orchestrate($scheduleOnly,['id'=>'pick-date','interactive_id'=>'course:c14'],['now'=>$now,'intensive_options'=>$catalog['courses']]);
memory_ok($selected['state']['flow']['step']==='name','Selecting a date inside controlled registration must reuse a previously confirmed valid schedule');
foreach(['Me da miedo el agua','Tuve una mala experiencia','Puedo estar en el agua','Quiero aprender a nadar mariposa','No quiero aprender a nadar','¿Quiero aprender a nadar?'] as $unclear)memory_ok(hache_sharky_whatsapp_detect_swim_level($unclear)===null,'Do not infer beginner from fear/ambiguous or negated request: '.$unclear);
memory_ok(hache_sharky_whatsapp_detect_swim_level('Ya sé nadar')==='swims','Explicit skilled swimmer remains supported');
$burst=hache_sharky_orchestrator_batch([['id'=>'b1','text'=>'¿Puedo ir con traje de baño normal?','timestamp_ms'=>1],['id'=>'b2','text'=>'En Palapas','timestamp_ms'=>2]]);
[$batched,$payload]=memory_turn(memory_new(),$burst['text'],$catalog,'Sí, puedes usar traje de baño completo.');
memory_ok($batched['commercial_context']['sede_clave']==='PALAPAS','Question plus venue burst preserves semantic choice');
memory_ok(substr_count(hache_sharky_draft_payload_text($payload),'traje de baño completo')===1,'Burst renders one answer');
$burst=hache_sharky_orchestrator_batch([['id'=>'d1','text'=>'De septiembre','timestamp_ms'=>1],['id'=>'d2','text'=>'2026','timestamp_ms'=>2]]);
$base=memory_new();$base['commercial_context']['program']='intensive';
[$dateFragment]=memory_turn($base,$burst['text'],$catalog);
memory_ok($dateFragment['commercial_context']['date_preference']===['month'=>'septiembre','year'=>2026],'Month/year fragments form one pending preference');
memory_ok(empty($dateFragment['commercial_context']['course_id']),'Month/year alone never confirms a specific course');
foreach(['flow:yes','flow:confirm','action:human','action:register_intensive'] as $id)memory_ok(!hache_sharky_whatsapp_batch_joinable_interactive($id),'Transactional button remains outside debounce: '.$id);
$human=hache_sharky_orchestrate($ivan,['id'=>'human','text'=>'Hola'],['human_takeover'=>true]);
memory_ok($human['decision']['kind']==='silent_human_takeover','Human takeover stays silent');
$duplicate=hache_sharky_orchestrate($ivan,['id'=>'turn:'.hash('sha256','7-8'),'text'=>'7-8']);
memory_ok($duplicate['decision']['kind']==='duplicate','Duplicate remains suppressed');
[$invalidSchedule]=memory_turn($ivan,'Quiero 22-23',$catalog);
memory_ok(empty($invalidSchedule['commercial_context']['schedule_id'])&&hache_sharky_commercial_next($invalidSchedule)['slot']==='schedule','Unavailable explicit schedule must not leave a stale enrollment CTA');
[$invalidCourse]=memory_turn($ignacia,'Quiero iniciar lunes 21 de septiembre de 2026',$catalog);
memory_ok(empty($invalidCourse['commercial_context']['course_id'])&&hache_sharky_commercial_next($invalidCourse)['slot']==='course','Unavailable explicit course must not leave the old course confirmed');
$shadow=hache_sharky_brain_shadow_evaluate($ignacia,$ignacia,['text'=>'14 de septiembre de 2026'],['decision'=>hache_sharky_commercial_reply($ignacia,'Listo.')]);
memory_ok($shadow['match']===true&&$shadow['live_action']==='show_commercial_menu','Brain shadow observes immediate enrollment invitation accurately');
$shadow=hache_sharky_brain_shadow_evaluate($base,$base,['text'=>'Hola'],['decision'=>hache_sharky_commercial_reply($base,'Seguimos.')]);
memory_ok($shadow['match']===true&&$shadow['live_action']==='answer_user','Brain shadow observes commercial missing-slot continuation');
fwrite(STDOUT,"SHARKY_COMMERCIAL_MEMORY_OK\n");
