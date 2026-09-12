<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-language-guide.php';

function language_ok(bool $condition,string $message): void
{
    if(!$condition){fwrite(STDERR,"SHARKY LANGUAGE GUIDE FAIL: {$message}\n");exit(1);}
}

$now=1789140000;
$state=hache_sharky_orchestrator_state(null,$now);
$state['identity']=array_replace($state['identity'],['kind'=>'prospect','source'=>'whatsapp_unmatched']);
$state=hache_sharky_orchestrator_flow($state,'qualify_prospect','swim',[],$now);

$beginnerCases=[
    'De ceros',
    'Desde ceros',
    'Quiero aprender a nadar y flotar',
    'No ce nadar',
    'Nada de nada',
    'No ce nada de nada, pero sin miedo',
];
foreach($beginnerCases as $case){
    language_ok(hache_sharky_language_prepare_text($state,$case)==='Desde cero','Beginner phrase must canonicalize: '.$case);
}
language_ok(hache_sharky_language_prepare_text($state,'Nunca')==='Nunca','A bare Nunca must stay ambiguous outside the background step.');
language_ok(hache_sharky_language_prepare_text($state,'Quiero aprender a nadar mejor')==='Quiero aprender a nadar mejor','A qualified learning goal must not be rewritten as beginner.');
language_ok(hache_sharky_language_prepare_text($state,'Quiero aprender a nadar mariposa; ya sé pecho')==='Quiero aprender a nadar mariposa; ya sé pecho','A learning goal with explicit swimming evidence must remain available for clarification.');

$swimsCases=['Nado un poco','Nado perrito','Me defiendo en el agua','Me mantengo a flote'];
foreach($swimsCases as $case){
    language_ok(hache_sharky_language_prepare_text($state,$case)==='Ya sé nadar','Swimmer phrase must canonicalize without assuming formal training: '.$case);
}

$background=$state;
$background['commercial_context']['swim_level']='swims';
$background=hache_sharky_orchestrator_flow($background,'qualify_prospect','background',[],$now+1);
language_ok(hache_sharky_language_prepare_text($background,'Nunca')==='Por mi cuenta','Bare Nunca must mean no formal training only while answering the background question.');
language_ok(hache_sharky_language_prepare_text($background,'Nunca.')==='Por mi cuenta','Punctuated bare Nunca must stay contextual to the background step.');
language_ok(hache_sharky_language_prepare_text($background,'Nunca he tomado clases')==='Por mi cuenta','No formal training must canonicalize as self-taught.');
language_ok(hache_sharky_language_prepare_text($background,'Aprendí sola')==='Por mi cuenta','Self-taught colloquial answer must canonicalize.');
language_ok(hache_sharky_language_prepare_text($background,'Sí he tomado clases con profesor')==='He tomado clases','Formal training must canonicalize.');

$venue=$state;
$venue['commercial_context']=array_replace($venue['commercial_context'],[
    'swim_level'=>'beginner','program'=>'intensive','recommended_program'=>'intensive',
]);
$venue=hache_sharky_orchestrator_flow($venue,'qualify_prospect','sede',['venue_proposal'=>'MONTEVERDE'],$now+2);
foreach(['Si','Sí.','Sí me funciona','Me funciona','Está bien','Ok','Vale'] as $case){
    language_ok(hache_sharky_language_prepare_text($venue,$case)==='Monteverde','Short affirmation must bind only to the pending Monteverde proposal: '.$case);
}
language_ok(hache_sharky_language_prepare_text($venue,'No')==='No','A venue rejection must remain available for the existing Palapas fallback.');
language_ok(hache_sharky_language_prepare_text($venue,'Palapas')==='Palapas','An explicit Palapas choice must never be rewritten.');
$noProposal=$venue;
$noProposal['flow']['data']['venue_proposal']='PALAPAS';
language_ok(hache_sharky_language_prepare_text($noProposal,'Si')==='Si','A bare affirmation must not become Monteverde without the explicit pending proposal.');
language_ok(hache_sharky_language_prepare_text($state,'Si')==='Si','A bare affirmation outside the venue step must remain ambiguous.');

$commercial=$state;
$commercial=hache_sharky_orchestrator_clear_flow($commercial);
$commercial['commercial_context']=array_replace($commercial['commercial_context'],[
    'swim_level'=>'beginner','program'=>'intensive','recommended_program'=>'intensive','sede_clave'=>'MONTEVERDE',
]);
$commercial['last_user_text']='¿Qué horarios tienen en la mañana?';
$before=$commercial;
$prepared=hache_sharky_language_prepare_text($commercial,'¿No tienes más horario?');
language_ok($prepared==='¿Qué horarios de la mañana tiene la otra sede?','More-schedule follow-up must keep morning scope and browse the other venue.');
language_ok($commercial===$before,'Language preparation must not mutate the structured commercial state.');

// La autoridad que responde consultas de la otra sede con horarios reales ya
// está cubierta por la regresión específica del orquestador. Aquí verificamos
// que esta nueva capa converge exactamente a esa intención sin cambiar sede.
$lateralRegression=(string)file_get_contents(__DIR__.'/sharky-enrollment-lateral-schedule-regression.php');
language_ok(str_contains($lateralRegression,"'text'=>'Quiero los horarios de la otra sede.'"),'Existing orchestrator regression must keep opposite-venue schedule browsing covered.');
language_ok(str_contains($lateralRegression,"'sede_clave']??'')==='MONTEVERDE'"),'Existing regression must preserve the active venue during informational browse.');

$regular=$commercial;
$regular['commercial_context']['program']='regular';
language_ok(hache_sharky_language_prepare_text($regular,'¿No tienes más horario?')==='¿No tienes más horario?','Intensive fallback must not rewrite a regular-classes query.');
language_ok(hache_sharky_language_prepare_text($commercial,'¿Hay más horarios en Palapas?')==='¿Hay más horarios en Palapas?','Explicit venue query must never be rewritten as an opposite-venue fallback.');

$worker=(string)file_get_contents(__DIR__.'/../bin/sharky-inbox-dispatch.php');
language_ok(str_contains($worker,"require_once __DIR__.'/../config/sharky-language-guide.php';"),'Durable inbox recovery must load the language normalization layer.');
$preparePos=strpos($worker,'$event=hache_sharky_language_prepare_event($pdo,$event);');
$processPos=strpos($worker,'hache_sharky_lab_process_event($pdo,$event',$preparePos===false?0:$preparePos);
language_ok($preparePos!==false&&$processPos!==false&&$preparePos<$processPos,'Recovered inbox events must be normalized before the shared Sharky processor.');

fwrite(STDOUT,"SHARKY_LANGUAGE_GUIDE_OK\n");
