<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-orchestrator.php';

function lateral_ok(bool $condition,string $message): void
{
    if(!$condition){fwrite(STDERR,"SHARKY ENROLLMENT LATERAL SCHEDULE FAIL: {$message}\n");exit(1);}
}

$now=1789097400;
$options=[
    [
        'id'=>'mv-oct-05','sede_clave'=>'MONTEVERDE','fecha_inicio'=>'2026-10-05','precio'=>1200,
        'schedules'=>[
            ['id'=>'mv-08','label'=>'08:00–09:00'],
            ['id'=>'mv-19','label'=>'19:00–20:00'],
            ['id'=>'mv-20','label'=>'20:00–21:00'],
        ],
    ],
    [
        'id'=>'pal-oct-05','sede_clave'=>'PALAPAS','fecha_inicio'=>'2026-10-05','precio'=>1200,
        'schedules'=>[
            ['id'=>'pal-07','label'=>'07:00–08:00'],
            ['id'=>'pal-08','label'=>'08:00–09:00'],
            ['id'=>'pal-09','label'=>'09:00–10:00'],
            ['id'=>'pal-20','label'=>'20:00–21:00'],
        ],
    ],
];
$context=['now'=>$now,'today'=>'2026-09-10','min_age'=>12,'intensive_options'=>$options];

$state=hache_sharky_orchestrator_state(null,$now);
$state['identity']=array_replace($state['identity'],['kind'=>'prospect','source'=>'whatsapp_unmatched']);
$state['commercial_context']=array_replace($state['commercial_context'],[
    'program'=>'intensive','sede_clave'=>'MONTEVERDE','swim_level'=>'beginner',
]);
$state=hache_sharky_orchestrator_flow($state,'register_intensive','course',['sede_clave'=>'MONTEVERDE'],$now);

// Exact production wording: a lateral information request must not be consumed
// as a course selection and must not create refresh/revalidation actions.
$result=hache_sharky_orchestrate($state,[
    'id'=>'lateral-1','from'=>'529900000100','type'=>'text','interactive_id'=>'',
    'text'=>'Quiero los horarios de la otra sede.',
],$context);
$message=(string)($result['decision']['message']??'');
lateral_ok(($result['decision']['kind']??'')==='side_question','Exact screenshot wording must be handled as a side question.');
lateral_ok(($result['decision']['action']??null)===null,'Lateral schedule lookup must never emit a business action.');
lateral_ok(str_contains($message,'Palapas Protudec'),'Opposite venue from Monteverde must resolve to Palapas.');
foreach(['07:00–08:00','08:00–09:00','09:00–10:00','20:00–21:00'] as $hour){
    lateral_ok(str_contains($message,$hour),'Palapas reply must contain verified intensive hour '.$hour.'.');
}
lateral_ok(!str_contains($message,'06:00–07:00'),'Regular-only Monteverde time must never leak into the lateral intensive answer.');
lateral_ok(str_contains($message,'inscripción actual sigue en Colegio Monteverde'),'The answer must state that current enrollment stayed in Monteverde.');
lateral_ok(($result['state']['flow']['name']??'')==='register_intensive'&&($result['state']['flow']['step']??'')==='course','Protected enrollment must remain on the exact prior step.');
lateral_ok(($result['state']['flow']['data']['sede_clave']??'')==='MONTEVERDE','Protected flow venue must remain Monteverde.');
lateral_ok(($result['state']['commercial_context']['sede_clave']??'')==='MONTEVERDE','Commercial venue must remain synchronized with the protected flow.');
lateral_ok(!str_contains(hache_sharky_orchestrator_normalize($message),'revalidacion obligatoria'),'Internal revalidation errors must never surface for a lateral information request.');

// Simulate the adapter having briefly interpreted a named browse phrase as a
// commercial venue browse before the controlled flow receives it. The flow is
// authoritative and must restore the original enrollment venue.
$drifted=$state;
$drifted['commercial_context']['sede_clave']='PALAPAS';
$named=hache_sharky_orchestrate($drifted,[
    'id'=>'lateral-2','from'=>'529900000100','type'=>'text','interactive_id'=>'',
    'text'=>'Quiero ver los horarios de Palapas.',
],$context);
$namedMessage=(string)($named['decision']['message']??'');
lateral_ok(($named['decision']['kind']??'')==='side_question','Named informational venue schedule request must stay informational.');
lateral_ok(str_contains($namedMessage,'Palapas Protudec'),'Named Palapas lookup must answer Palapas.');
lateral_ok(($named['state']['commercial_context']['sede_clave']??'')==='MONTEVERDE','Informational Palapas lookup must restore the active Monteverde enrollment venue.');
lateral_ok(($named['state']['flow']['data']['sede_clave']??'')==='MONTEVERDE','Named lookup must not rewrite protected flow data.');
lateral_ok(($named['decision']['action']??null)===null,'Named lookup must not execute or refresh any operation.');

// Reverse relative resolution must also work.
$palState=$state;
$palState['commercial_context']['sede_clave']='PALAPAS';
$palState=hache_sharky_orchestrator_flow($palState,'register_intensive','course',['sede_clave'=>'PALAPAS'],$now);
$reverse=hache_sharky_orchestrate($palState,[
    'id'=>'lateral-3','from'=>'529900000101','type'=>'text','interactive_id'=>'',
    'text'=>'¿Qué horarios tiene la otra sede?',
],$context);
$reverseMessage=(string)($reverse['decision']['message']??'');
lateral_ok(str_contains($reverseMessage,'Colegio Monteverde'),'Opposite venue from Palapas must resolve to Monteverde.');
foreach(['08:00–09:00','19:00–20:00','20:00–21:00'] as $hour){
    lateral_ok(str_contains($reverseMessage,$hour),'Monteverde reply must contain verified intensive hour '.$hour.'.');
}
lateral_ok(!str_contains($reverseMessage,'07:00–08:00'),'Palapas-only morning time must not appear in Monteverde lateral answer.');
lateral_ok(($reverse['state']['commercial_context']['sede_clave']??'')==='PALAPAS','Reverse lookup must preserve Palapas as the enrollment venue.');

// A lateral lookup later in the flow must restore all course/schedule scope from
// protected flow data rather than losing selections captured before the question.
$nameState=$state;
$nameData=[
    'sede_clave'=>'MONTEVERDE','course_id'=>'mv-oct-05','fecha_inicio'=>'2026-10-05','course_price'=>1200,
    'schedule_id'=>'mv-19','schedule_label'=>'19:00–20:00',
];
$nameState=hache_sharky_orchestrator_flow($nameState,'register_intensive','name',$nameData,$now);
$nameState['commercial_context']['sede_clave']='PALAPAS';
unset($nameState['commercial_context']['course_id'],$nameState['commercial_context']['fecha_inicio'],$nameState['commercial_context']['course_price'],$nameState['commercial_context']['schedule_id'],$nameState['commercial_context']['schedule_label']);
$nameLookup=hache_sharky_orchestrate($nameState,[
    'id'=>'lateral-4','from'=>'529900000102','type'=>'text','interactive_id'=>'',
    'text'=>'Horarios de Palapas',
],$context);
lateral_ok(($nameLookup['decision']['kind']??'')==='side_question','Schedule lookup must be allowed while name step is protected.');
lateral_ok(($nameLookup['state']['flow']['step']??'')==='name','Name step must not advance on a schedule lookup.');
foreach($nameData as $key=>$value){
    lateral_ok(($nameLookup['state']['commercial_context'][$key]??null)===$value,'Protected '.$key.' must be restored after lateral lookup.');
}

// Stale interactive course ids still fail closed, but are now refreshed inside
// the orchestrator instead of leaking the internal refresh pseudo-action to the
// generic sensitive-action executor.
$stale=hache_sharky_orchestrate($state,[
    'id'=>'stale-course','from'=>'529900000103','type'=>'interactive',
    'interactive_id'=>'course:does-not-exist','text'=>'Fecha anterior',
],$context);
lateral_ok(($stale['decision']['kind']??'')==='registration_course','Stale course tap must rebuild the current course list.');
lateral_ok(($stale['decision']['action']??null)===null,'Stale course tap must not emit refresh_intensive_options into the generic action executor.');
lateral_ok(($stale['state']['flow']['step']??'')==='course','Stale course tap must remain safely on course selection.');
lateral_ok(str_contains((string)$stale['decision']['message'],'ya no está disponible'),'Stale course tap must explain that the old option expired.');

// Ordinary free text at the course step can no longer become an internal action.
$free=hache_sharky_orchestrate($state,[
    'id'=>'free-course','from'=>'529900000104','type'=>'text','interactive_id'=>'','text'=>'No entendí',
],$context);
lateral_ok(($free['decision']['kind']??'')==='registration_course','Unknown free text at course step must safely re-render course choices.');
lateral_ok(($free['decision']['action']??null)===null,'Unknown free text at course step must never reach a revalidation action.');

// A real valid course selection still follows the original controlled path.
$valid=hache_sharky_orchestrate($state,[
    'id'=>'valid-course','from'=>'529900000105','type'=>'interactive',
    'interactive_id'=>'course:mv-oct-05','text'=>'5 de octubre de 2026',
],$context);
lateral_ok(($valid['decision']['kind']??'')==='registration_schedule','Valid current course selection must still advance to schedule selection.');
lateral_ok(($valid['state']['flow']['step']??'')==='schedule','Valid course tap must preserve the original protected transition.');

fwrite(STDOUT,"SHARKY_ENROLLMENT_LATERAL_SCHEDULE_OK\n");
