<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-lab-worker.php';

function guided_entry_ok(bool $ok,string $message): void
{
    if(!$ok){fwrite(STDERR,"SHARKY GUIDED ENTRY FAIL: $message\n");exit(1);}
}

$tz=new DateTimeZone('America/Cancun');
$reference=new DateTimeImmutable('2026-09-08',$tz);
$next=hache_sharky_start_authority_parse_date('el proximo lunes',$reference);
guided_entry_ok($next instanceof DateTimeImmutable&&$next->format('Y-m-d')==='2026-09-14','“el próximo lunes” must resolve against Cancun today.');
$nextAlias=hache_sharky_start_authority_parse_date('lunes que viene',$reference);
guided_entry_ok($nextAlias instanceof DateTimeImmutable&&$nextAlias->format('Y-m-d')==='2026-09-14','“lunes que viene” must resolve to the same Monday.');
$mondayReference=new DateTimeImmutable('2026-09-14',$tz);
$strictNext=hache_sharky_start_authority_parse_date('proximo lunes',$mondayReference);
guided_entry_ok($strictNext instanceof DateTimeImmutable&&$strictNext->format('Y-m-d')==='2026-09-21','On Monday, “próximo lunes” must mean +7 days.');

$fresh=hache_sharky_orchestrator_state(null,1788796800);
$fresh['identity']=array_replace($fresh['identity'],['kind'=>'prospect','verified'=>false,'source'=>'whatsapp_unmatched']);
$webIntensive='Hola Hache Natación, quiero información sobre el curso intensivo';
$webRegular='Hola Hache Natación, quiero información sobre las clases regulares';
$entry=hache_sharky_entry_context($fresh,$webIntensive);
guided_entry_ok($entry===['source'=>'web','interest'=>'intensive'],'Website intensive prefill must be recognized as entry context.');
$entry=hache_sharky_entry_context($fresh,$webRegular);
guided_entry_ok($entry===['source'=>'web','interest'=>'regular'],'Website regular prefill must be recognized as entry context.');

$meta=$fresh;
$meta['referral']['latest']=[
    'source_type'=>'ad','source_id'=>'1200000001','headline'=>'Intensivo septiembre',
    'body'=>'Aprende a nadar','ctwa_clid'=>'clid-abc','captured_at'=>1788796800,
];
$entry=hache_sharky_entry_context($meta,'Hola');
guided_entry_ok($entry===['source'=>'meta_ad','interest'=>'intensive'],'Meta intensive ad must contextualize the entry as intensive.');
$metaFallback=$meta;$metaFallback['referral']['latest']['headline']='Aprende a nadar';$metaFallback['referral']['latest']['body']='Inscripciones abiertas';
$entry=hache_sharky_entry_context($metaFallback,'Hola');
guided_entry_ok($entry===['source'=>'meta_ad','interest'=>'intensive'],'Current single Meta campaign must safely fall back to intensive entry interest.');
$metaExplicitRegular=hache_sharky_entry_context($meta,'Quiero información de clases regulares');
guided_entry_ok($metaExplicitRegular===['source'=>'meta_ad','interest'=>'regular'],'Explicit user interest must override an intensive Meta referral while preserving Meta as the source.');
$entry=hache_sharky_entry_context($fresh,'Hola');
guided_entry_ok($entry===['source'=>'direct','interest'=>null],'Unknown direct entry must remain generic.');

$guided=hache_sharky_orchestrator_flow($fresh,'qualify_prospect','swim',[],1788796800);
$guided=hache_sharky_entry_apply($guided,$webIntensive);
guided_entry_ok(($guided['commercial_context']['entry_source']??null)==='web','Entry source must persist.');
guided_entry_ok(($guided['commercial_context']['entry_interest']??null)==='intensive','Entry interest must persist.');
guided_entry_ok(empty($guided['commercial_context']['program']),'Entry interest must not masquerade as a confirmed program.');
guided_entry_ok(($guided['flow']['data']['preferred_program']??null)==='intensive','Guided qualification must carry the entry program as a preference.');
$metaGuided=hache_sharky_orchestrator_flow($meta,'qualify_prospect','swim',[],1788796800);
$metaGuided=hache_sharky_entry_apply($metaGuided,'Quiero clases regulares');
guided_entry_ok(($metaGuided['commercial_context']['entry_interest']??null)==='regular','Explicit regular interest must persist even when referral advertises intensive.');
guided_entry_ok(($metaGuided['flow']['data']['preferred_program']??null)==='regular','Explicit regular interest must guide qualification instead of the referral fallback.');

$decision=hache_sharky_orchestrator_decision('qualification_swim','Para orientarte bien, ¿ya sabes nadar o estás empezando desde cero?',['type'=>'buttons','buttons'=>[
    hache_sharky_orchestrator_button('qualify:swims','Ya sé nadar'),
    hache_sharky_orchestrator_button('qualify:beginner','Desde cero'),
]]);
$payload=hache_sharky_whatsapp_render('529981112233',$decision);
$presented=hache_sharky_lab_present_once($payload,$fresh,$webIntensive);
$body=hache_sharky_draft_payload_text($presented);
guided_entry_ok(str_contains($body,'Soy Sharky 🦈, el asistente IA de Hache Natación.'),'First outbound must disclose AI identity.');
guided_entry_ok(str_contains($body,'vienes desde nuestra página')&&str_contains($body,'curso intensivo'),'Website intensive greeting must explain why the conversation is contextualized.');
guided_entry_ok(mb_strlen($body)<=1024,'Contextual interactive greeting must preserve Meta body limit.');
$generic=hache_sharky_lab_present_once($payload,$fresh,'Hola');
$genericBody=hache_sharky_draft_payload_text($generic);
guided_entry_ok(str_contains($genericBody,'tenemos opciones para quien empieza desde cero'),'Unknown source must get a short generic Hache introduction.');
guided_entry_ok(!str_contains($genericBody,'anuncio')&&!str_contains($genericBody,'nuestra página'),'Generic greeting must not invent a source.');

$catalog=[
    'plans'=>[],
    'schedules'=>[
        ['id'=>'h8','label'=>'08:00–09:00'],
        ['id'=>'h19','label'=>'19:00–20:00'],
        ['id'=>'h20','label'=>'20:00–21:00'],
    ],
    'courses'=>[
        ['id'=>'c14','sede_clave'=>'MONTEVERDE','fecha_inicio'=>'2026-09-14','precio'=>1200,'schedules'=>[['id'=>'h8','label'=>'08:00–09:00'],['id'=>'h20','label'=>'20:00–21:00']]],
        ['id'=>'c21','sede_clave'=>'MONTEVERDE','fecha_inicio'=>'2026-09-21','precio'=>1200,'schedules'=>[['id'=>'h8','label'=>'08:00–09:00']]],
    ],
];
$commercial=$fresh;
$commercial['commercial_context']['program']='intensive';
$commercial['commercial_context']['sede_clave']='MONTEVERDE';
$commercial=hache_sharky_commercial_capture($commercial,'8 a 9',$catalog,'2026-09-08');
guided_entry_ok(($commercial['commercial_context']['schedule_id']??null)==='h8','Typed schedule must be equivalent to selecting the available 08:00–09:00 option.');
$commercial=hache_sharky_commercial_capture($commercial,'el próximo lunes',$catalog,'2026-09-08');
guided_entry_ok(($commercial['commercial_context']['course_id']??null)==='c14','Typed “el próximo lunes” must select the matching backend course.');
guided_entry_ok(($commercial['commercial_context']['fecha_inicio']??null)==='2026-09-14','Relative Monday must persist the exact backend date.');

$bareDay=$fresh;
$bareDay['commercial_context']['program']='intensive';$bareDay['commercial_context']['sede_clave']='MONTEVERDE';
$bareDay['commercial_context']['schedule_id']='h8';$bareDay['commercial_context']['schedule_label']='08:00–09:00';
$bareDay=hache_sharky_commercial_capture($bareDay,'el 14',$catalog,'2026-09-08');
guided_entry_ok(($bareDay['commercial_context']['course_id']??null)==='c14','A unique typed day must behave like choosing that displayed Monday.');
$singleCourseCatalog=$catalog;$singleCourseCatalog['courses']=[$catalog['courses'][0]];
$staleCourse=$bareDay;
$staleCourse=hache_sharky_commercial_capture($staleCourse,'el 21',$singleCourseCatalog,'2026-09-08');
guided_entry_ok(empty($staleCourse['commercial_context']['course_id']),'An unavailable bare-day change must clear the previously selected course instead of enrolling into stale data.');
guided_entry_ok((hache_sharky_commercial_next($staleCourse)['slot']??null)==='course','After an unresolved bare-day change Sharky must return to choosing a valid start date.');

$questionState=$bareDay;
unset($questionState['commercial_context']['course_id'],$questionState['commercial_context']['fecha_inicio'],$questionState['commercial_context']['course_price']);
$before=hache_sharky_commercial_snapshot($questionState);
$questionState=hache_sharky_commercial_capture($questionState,'No sé qué fecha es el próximo lunes',$catalog,'2026-09-08');
guided_entry_ok(hache_sharky_commercial_snapshot($questionState)===$before,'Asking what date next Monday is must not silently select a course.');
$relativeAnswer=hache_sharky_relative_date_answer('No sé qué fecha es el próximo lunes',$questionState,[
    'today'=>'2026-09-08','intensive_options'=>$catalog['courses'],
]);
guided_entry_ok(is_string($relativeAnswer)&&str_contains($relativeAnswer,'lunes 14 de septiembre de 2026'),'Sharky must answer the exact relative date instead of saying it depends on today.');
guided_entry_ok(str_contains((string)$relativeAnswer,'está disponible'),'Relative date answer should confirm availability when the backend contains that Monday for the selected venue.');

// Guided-first: 3 valid schedules fit native WhatsApp reply buttons.
$scheduleState=$fresh;$scheduleState['commercial_context']['program']='intensive';$scheduleState['commercial_context']['sede_clave']='MONTEVERDE';
$scheduleReply=hache_sharky_commercial_reply($scheduleState,'',$catalog);
guided_entry_ok(($scheduleReply['ui']['type']??null)==='buttons','Up to three schedules must render as reply buttons instead of a text bullet dump.');
$scheduleIds=array_column($scheduleReply['ui']['buttons']??[],'id');
guided_entry_ok($scheduleIds===['action:commercial:schedule:h8','action:commercial:schedule:h19','action:commercial:schedule:h20'],'Schedule buttons must carry stable structured IDs.');
$schedulePayload=hache_sharky_whatsapp_render('529981112233',$scheduleReply);
guided_entry_ok(($schedulePayload['type']??null)==='interactive'&&($schedulePayload['interactive']['type']??null)==='button','Guided schedules must reach Meta as an interactive button payload.');
guided_entry_ok(mb_strlen((string)($schedulePayload['interactive']['body']['text']??''))<=1024,'Guided schedule body must respect Meta interactive limits.');

// More than three options must use a native list, never truncate silently to 3 buttons.
$manyScheduleCatalog=$catalog;
$manyScheduleCatalog['schedules'][]=['id'=>'h7','label'=>'07:00–08:00'];
$manyScheduleReply=hache_sharky_commercial_reply($scheduleState,'',$manyScheduleCatalog);
guided_entry_ok(($manyScheduleReply['ui']['type']??null)==='list','Four schedules must switch to WhatsApp native list UI.');
guided_entry_ok(count($manyScheduleReply['ui']['options']??[])===4,'Native list must preserve every currently valid schedule option.');

// Tapping a schedule writes exactly the same durable selection as typing it,
// then advances to real backend dates with guided controls.
$pdo=new PDO('sqlite::memory:');
$context=['today'=>'2026-09-08','intensive_options'=>$catalog['courses'],'min_age'=>12];
$scheduleTap=hache_sharky_commercial_interactive_input($pdo,$scheduleState,[
    'interactive_id'=>'action:commercial:schedule:h8','text'=>'08:00–09:00',
],$context);
guided_entry_ok(is_array($scheduleTap),'Commercial schedule button must be handled deterministically.');
[$afterSchedule,$afterScheduleDecision]=$scheduleTap;
guided_entry_ok(($afterSchedule['commercial_context']['schedule_id']??null)==='h8','Schedule tap must persist exact schedule_id.');
guided_entry_ok(($afterSchedule['commercial_context']['schedule_label']??null)==='08:00–09:00','Schedule tap must persist exact backend label.');
guided_entry_ok((hache_sharky_commercial_next($afterSchedule)['slot']??null)==='course','Schedule tap must move forward to course start selection.');
guided_entry_ok(($afterScheduleDecision['ui']['type']??null)==='buttons','Two compatible course starts should render as buttons.');
$courseIds=array_column($afterScheduleDecision['ui']['buttons']??[],'id');
guided_entry_ok($courseIds===['action:commercial:course:c14','action:commercial:course:c21'],'Course buttons must represent the compatible backend Mondays only.');

$courseTap=hache_sharky_commercial_interactive_input($pdo,$afterSchedule,[
    'interactive_id'=>'action:commercial:course:c14','text'=>'Lun 14 sep',
],$context);
guided_entry_ok(is_array($courseTap),'Commercial course button must be handled deterministically.');
[$afterCourse,$afterCourseDecision]=$courseTap;
guided_entry_ok(($afterCourse['commercial_context']['course_id']??null)==='c14'&&($afterCourse['commercial_context']['fecha_inicio']??null)==='2026-09-14','Course tap must persist course_id and exact start date.');
guided_entry_ok((hache_sharky_commercial_next($afterCourse)['slot']??null)==='enroll','After schedule+course Sharky must advance to enrollment CTA.');
guided_entry_ok(array_column($afterCourseDecision['ui']['buttons']??[],'id')===['action:register_intensive'],'Completed intensive selection must expose Inscribirme immediately.');

// A stale tap cannot move the funnel backwards or overwrite a later slot.
$staleScheduleTap=hache_sharky_commercial_interactive_input($pdo,$afterSchedule,[
    'interactive_id'=>'action:commercial:schedule:h20','text'=>'20:00–21:00',
],$context);
guided_entry_ok(is_array($staleScheduleTap),'Stale commercial taps must receive a safe current-step response.');
guided_entry_ok(($staleScheduleTap[0]['commercial_context']['schedule_id']??null)==='h8','A stale schedule tap must not overwrite the already confirmed schedule while course is pending.');
guided_entry_ok((hache_sharky_commercial_next($staleScheduleTap[0])['slot']??null)==='course','Stale tap must leave the user on the current course step.');

// If a chosen schedule is not available on a Monday, that Monday must not be
// offered as a guided date even though free text can still request it and cause
// normal revalidation.
$h20State=$scheduleState;$h20State['commercial_context']['schedule_id']='h20';$h20State['commercial_context']['schedule_label']='20:00–21:00';
$h20CourseReply=hache_sharky_commercial_reply($h20State,'',$catalog);
guided_entry_ok(array_column($h20CourseReply['ui']['buttons']??[],'id')===['action:commercial:course:c14'],'Date controls must filter out courses that do not offer the selected schedule.');

// More than three backend Mondays use native list controls.
$manyCourseCatalog=$catalog;
foreach([
    ['id'=>'c28','fecha_inicio'=>'2026-09-28'],['id'=>'c05','fecha_inicio'=>'2026-10-05'],
] as $extra)$manyCourseCatalog['courses'][]=['id'=>$extra['id'],'sede_clave'=>'MONTEVERDE','fecha_inicio'=>$extra['fecha_inicio'],'precio'=>1200,'schedules'=>[['id'=>'h8','label'=>'08:00–09:00']]];
$manyCourseReply=hache_sharky_commercial_reply($afterSchedule,'',$manyCourseCatalog);
guided_entry_ok(($manyCourseReply['ui']['type']??null)==='list'&&count($manyCourseReply['ui']['options']??[])===4,'Four compatible Mondays must use a native list with all four dates.');

// Regular plans keep plan_id authority even when two plans have the same
// sessions/week. The user sees both names instead of Sharky collapsing them to “5 días”.
$regular=$fresh;$regular['commercial_context']['program']='regular';$regular['commercial_context']['sede_clave']='PALAPAS';$regular['commercial_context']['sessions_per_week']=5;
$regularCatalog=[
    'plans'=>[
        ['id'=>'p-old','nombre'=>'Plan 5x OLD','sesiones_semana'=>5,'precio'=>800],
        ['id'=>'p-new','nombre'=>'Plan 5x','sesiones_semana'=>5,'precio'=>1000],
        ['id'=>'p-3','nombre'=>'Plan 3x','sesiones_semana'=>3,'precio'=>800],
    ],
    'schedules'=>[['id'=>'pr8','label'=>'08:00–09:00']],
    'courses'=>[],
];
$regularReply=hache_sharky_commercial_reply($regular,'',$regularCatalog);
guided_entry_ok(($regularReply['ui']['type']??null)==='buttons','Two same-frequency plans with short distinct names can be rendered as buttons.');
guided_entry_ok(array_column($regularReply['ui']['buttons']??[],'id')===['action:commercial:plan:p-old','action:commercial:plan:p-new'],'Same-frequency plans must stay distinct by plan_id and name.');

// Commercial state-changing choices are intentionally outside the question
// debounce; they travel the fast interactive path and cannot be coalesced.
guided_entry_ok(!hache_sharky_whatsapp_batch_joinable_interactive('action:commercial:schedule:h8'),'Commercial schedule selection must bypass debounce batching.');
guided_entry_ok(!hache_sharky_whatsapp_batch_joinable_interactive('action:commercial:course:c14'),'Commercial course selection must bypass debounce batching.');
$batchingSource=file_get_contents(__DIR__.'/../config/sharky-whatsapp-batching.php')?:'';
guided_entry_ok(str_contains($batchingSource,'hache_sharky_commercial_interactive_input($pdo,$deferredState,$event,$commercialContext)'),'Production delivery-lock path must route guided commercial taps through structured memory before the free-form adapter.');

guided_entry_ok(hache_sharky_orchestrator_program_choice('intensivo')==='intensive','Typing “intensivo” must remain equivalent to its guided button.');
guided_entry_ok(hache_sharky_orchestrator_sede_choice('Monteverde')==='MONTEVERDE','Typing “Monteverde” must remain equivalent to its guided button.');

fwrite(STDOUT,"SHARKY_GUIDED_REFERRAL_DATE_OK\n");
