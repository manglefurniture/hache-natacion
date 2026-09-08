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
    'schedules'=>[['id'=>'h8','label'=>'08:00–09:00'],['id'=>'h20','label'=>'20:00–21:00']],
    'courses'=>[
        ['id'=>'c14','sede_clave'=>'MONTEVERDE','fecha_inicio'=>'2026-09-14','precio'=>1200,'schedules'=>[['id'=>'h8','label'=>'08:00–09:00']]],
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

$needsDate=$questionState;
$reply=hache_sharky_commercial_reply($needsDate,'',$catalog);
$replyMessage=(string)($reply['message']??'');
guided_entry_ok(str_contains($replyMessage,'Los cursos intensivos comienzan los lunes'),'Missing intensive date must be guided by the Monday business rule.');
guided_entry_ok(str_contains($replyMessage,'14 de septiembre de 2026')&&str_contains($replyMessage,'21 de septiembre de 2026'),'Date guidance must show actual backend start options.');
guided_entry_ok(str_contains($replyMessage,'“el próximo lunes”'),'Date guidance must tell the user natural language is accepted.');

$scheduleState=$fresh;$scheduleState['commercial_context']['program']='intensive';$scheduleState['commercial_context']['sede_clave']='MONTEVERDE';
$scheduleReply=hache_sharky_commercial_reply($scheduleState,'',$catalog);
guided_entry_ok(str_contains((string)$scheduleReply['message'],'08:00–09:00')&&str_contains((string)$scheduleReply['message'],'20:00–21:00'),'Missing schedule must show actual backend schedule options instead of an open-ended question.');

guided_entry_ok(hache_sharky_orchestrator_program_choice('intensivo')==='intensive','Typing “intensivo” must remain equivalent to its guided button.');
guided_entry_ok(hache_sharky_orchestrator_sede_choice('Monteverde')==='MONTEVERDE','Typing “Monteverde” must remain equivalent to its guided button.');

fwrite(STDOUT,"SHARKY_GUIDED_REFERRAL_DATE_OK\n");
