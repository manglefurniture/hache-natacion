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

$swimsCases=['Nado un poco','Nado perrito','Me defiendo en el agua','Me mantengo a flote'];
foreach($swimsCases as $case){
    language_ok(hache_sharky_language_prepare_text($state,$case)==='Ya sé nadar','Swimmer phrase must canonicalize without assuming formal training: '.$case);
}

$background=$state;
$background['commercial_context']['swim_level']='swims';
$background=hache_sharky_orchestrator_flow($background,'qualify_prospect','background',[],$now+1);
language_ok(hache_sharky_language_prepare_text($background,'Nunca he tomado clases')==='Por mi cuenta','No formal training must canonicalize as self-taught.');
language_ok(hache_sharky_language_prepare_text($background,'Aprendí sola')==='Por mi cuenta','Self-taught colloquial answer must canonicalize.');
language_ok(hache_sharky_language_prepare_text($background,'Sí he tomado clases con profesor')==='He tomado clases','Formal training must canonicalize.');

$commercial=$state;
$commercial=hache_sharky_orchestrator_clear_flow($commercial);
$commercial['commercial_context']=array_replace($commercial['commercial_context'],[
    'swim_level'=>'beginner','program'=>'intensive','recommended_program'=>'intensive','sede_clave'=>'MONTEVERDE',
]);
$commercial['last_user_text']='¿Qué horarios tienen en la mañana?';
$prepared=hache_sharky_language_prepare_text($commercial,'¿No tienes más horario?');
language_ok($prepared==='¿Qué horarios de la mañana tiene la otra sede?','More-schedule follow-up must keep morning scope and browse the other venue.');

$options=[
    [
        'id'=>'mv-course','sede_clave'=>'MONTEVERDE','fecha_inicio'=>'2026-10-05','precio'=>1200,
        'schedules'=>[
            ['id'=>'mv-08','label'=>'08:00–09:00'],
            ['id'=>'mv-19','label'=>'19:00–20:00'],
            ['id'=>'mv-20','label'=>'20:00–21:00'],
        ],
    ],
    [
        'id'=>'pal-course','sede_clave'=>'PALAPAS','fecha_inicio'=>'2026-10-05','precio'=>1200,
        'schedules'=>[
            ['id'=>'pal-07','label'=>'07:00–08:00'],
            ['id'=>'pal-08','label'=>'08:00–09:00'],
            ['id'=>'pal-09','label'=>'09:00–10:00'],
            ['id'=>'pal-20','label'=>'20:00–21:00'],
        ],
    ],
];
$result=hache_sharky_orchestrate($commercial,[
    'id'=>'language-schedule','from'=>'529900000200','type'=>'text','interactive_id'=>'','text'=>$prepared,
],[
    'now'=>$now+2,'today'=>'2026-09-11','min_age'=>12,'intensive_options'=>$options,
]);
$message=(string)($result['decision']['message']??'');
language_ok(($result['decision']['kind']??'')==='side_question','Alternative schedule must remain informational.');
language_ok(str_contains($message,'Palapas Protudec'),'Monteverde alternative lookup must answer Palapas.');
language_ok(str_contains($message,'07:00–08:00')&&str_contains($message,'08:00–09:00')&&str_contains($message,'09:00–10:00'),'Morning Palapas alternatives must come from verified backend options.');
language_ok(!str_contains($message,'20:00–21:00'),'Morning alternative lookup must not include the evening schedule.');
language_ok(($result['state']['commercial_context']['sede_clave']??'')==='MONTEVERDE','Browsing alternatives must not silently switch the active venue.');

$regular=$commercial;
$regular['commercial_context']['program']='regular';
language_ok(hache_sharky_language_prepare_text($regular,'¿No tienes más horario?')==='¿No tienes más horario?','Intensive fallback must not rewrite a regular-classes query.');
language_ok(hache_sharky_language_prepare_text($commercial,'¿Hay más horarios en Palapas?')==='¿Hay más horarios en Palapas?','Explicit venue query must never be rewritten as an opposite-venue fallback.');

fwrite(STDOUT,"SHARKY_LANGUAGE_GUIDE_OK\n");
