<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-product-boundary-guard.php';
require_once __DIR__.'/../config/sharky-commercial-memory.php';

function product_boundary_ok(bool $condition,string $message): void
{
    if(!$condition){fwrite(STDERR,"SHARKY PRODUCT BOUNDARY FAIL: {$message}\n");exit(1);}
}

$beginner=[
    'identity'=>['kind'=>'prospect'],
    'commercial_context'=>[
        'program'=>'intensive','recommended_program'=>'intensive','entry_interest'=>'intensive',
        'swim_level'=>'beginner','sede_clave'=>'MONTEVERDE',
    ],
];
$noFormal=[
    'identity'=>['kind'=>'prospect'],
    'commercial_context'=>[
        'program'=>'intensive','recommended_program'=>'intensive','swim_level'=>'swims',
        'background'=>'no_formal','sede_clave'=>'MONTEVERDE',
    ],
];
$selfTaught=$noFormal;$selfTaught['commercial_context']['background']='self_taught';
$unknownBackground=$noFormal;unset($unknownBackground['commercial_context']['background']);
$formal=$noFormal;$formal['commercial_context']['background']='formal';
$fresh=['identity'=>['kind'=>'prospect'],'commercial_context'=>[]];

product_boundary_ok(hache_sharky_product_boundary_weekly_frequency('Me gustaría 2 veces por semana')===2,'Real conversation: 2 veces por semana must be recognized as frequency, not as a product.');
product_boundary_ok(hache_sharky_product_boundary_weekly_frequency('Quiero tres clases a la semana')===3,'Spelled-out weekly frequencies must be recognized.');
product_boundary_ok(hache_sharky_product_boundary_weekly_frequency('Cinco días x semana')===5,'x semana wording must be recognized.');
product_boundary_ok(hache_sharky_product_boundary_weekly_frequency('El intensivo dura 3 semanas')===null,'Course duration must not be mistaken for weekly frequency.');

foreach([
    'Nunca he tomado clases de natación',
    'Nado un poco pero no he tomado clases de natación',
    'He nadado un poco, jamás he recibido clases',
    'Aprendí por mi cuenta',
] as $text){
    product_boundary_ok(hache_sharky_product_boundary_no_formal_signal($text),'No-formal natural language must activate the hard eligibility rule: '.$text);
}
foreach([
    'No he tomado clases esta semana',
    'Nunca he tomado clases en la mañana',
] as $text){
    product_boundary_ok(!hache_sharky_product_boundary_no_formal_signal($text),'Local schedule/attendance wording must not overwrite formal training history: '.$text);
    product_boundary_ok(hache_sharky_commercial_background_choice($text)===null,'Commercial memory must also ignore local schedule/attendance wording: '.$text);
}
product_boundary_ok(hache_sharky_commercial_background_choice('He nadado un poco pero nunca he tomado clases')==='no_formal','Commercial memory must capture a genuine no-formal training statement.');

product_boundary_ok(hache_sharky_product_boundary_regular_restricted($beginner),'A beginner is never auto-eligible for regular classes.');
product_boundary_ok(hache_sharky_product_boundary_regular_restricted($noFormal),'A swimmer without formal lessons is never auto-eligible for regular classes.');
product_boundary_ok(hache_sharky_product_boundary_regular_restricted($selfTaught),'A self-taught swimmer is never auto-eligible for regular classes.');
product_boundary_ok(!hache_sharky_product_boundary_regular_restricted($formal),'A prospect with formal lessons may be eligible for regular classes.');
product_boundary_ok(hache_sharky_product_boundary_regular_pending_background($unknownBackground),'A swimmer with unknown training history must be qualified before regular classes are offered.');
product_boundary_ok(hache_sharky_product_boundary_regular_pending_background($fresh),'A fresh prospect with no qualification evidence must be treated as pending before regular classes are offered.');

foreach(['Prefiero clases regulares','Quiero la mensualidad','Me gustaría 2 veces por semana','Quiero 3 clases por semana'] as $text){
    $reply=hache_sharky_product_boundary_reply($text,$beginner)??'';
    $norm=hache_sharky_product_boundary_normalize($reply);
    product_boundary_ok($reply!=='','Beginner regular request/frequency must be intercepted: '.$text);
    product_boundary_ok(str_contains($norm,'no son una opcion')&&str_contains($norm,'persona del equipo'),'Beginner must be sent to human assessment for any regular-class exception.');
    product_boundary_ok(!str_contains($norm,'$1000')&&!str_contains($norm,'$1200'),'The hard eligibility reply must not sell regular plans.');
}

$combined=hache_sharky_product_boundary_reply('Nado un poco pero nunca he tomado clases; prefiero regulares',$formal)??'';
product_boundary_ok(str_contains(hache_sharky_product_boundary_normalize($combined),'persona del equipo'),'A current no-formal statement must override stale formal context and require human assessment.');

$pending=hache_sharky_product_boundary_reply('Me gustaría 2 veces por semana',$unknownBackground)??'';
product_boundary_ok(str_contains(hache_sharky_product_boundary_normalize($pending),'has tomado clases formales'),'“Sé nadar un poco” with unknown training history must ask about formal lessons before offering regular classes.');
$pendingRegular=hache_sharky_product_boundary_reply('Prefiero clases regulares',$unknownBackground)??'';
product_boundary_ok(str_contains(hache_sharky_product_boundary_normalize($pendingRegular),'has tomado clases formales'),'An explicit regular preference still requires training-history qualification when background is unknown.');

product_boundary_ok(hache_sharky_product_boundary_reply('Prefiero clases regulares',$formal)===null,'Only a prospect with formal training may explicitly switch to regular classes automatically.');

$dirty=$beginner;
$dirty['commercial_context']=array_replace($dirty['commercial_context'],[
    'program'=>'regular','plan_id'=>'r3','plan_name'=>'Regular 3','sessions_per_week'=>3,'plan_price'=>1000,
]);
$clean=hache_sharky_product_boundary_sanitize_state($dirty);
product_boundary_ok(($clean['commercial_context']['program']??null)==='intensive','A contaminated beginner state must be forced back to intensive.');
product_boundary_ok(($clean['commercial_context']['recommended_program']??null)==='intensive','The intensive recommendation must remain canonical for beginners.');
foreach(['plan_id','plan_name','sessions_per_week','plan_price'] as $key)product_boundary_ok(!array_key_exists($key,$clean['commercial_context']),'Regular-only state must be removed from a restricted prospect: '.$key);

$cleanIntensive=$beginner;unset($cleanIntensive['commercial_context']['recommended_program']);
$cleanIntensiveAfter=hache_sharky_commercial_force_intensive_eligibility($cleanIntensive);
product_boundary_ok(!isset($cleanIntensiveAfter['commercial_context']['recommended_program']),'A harmless turn must not add recommendation memory to an already-confirmed intensive state.');

$modelRegular="Clases regulares:\n• 3 clases por semana: $1000 MXN\n• 5 clases por semana: $1200 MXN";
$guarded=hache_sharky_product_boundary_model_answer($modelRegular,$beginner,'Precio de las clases')??'';
product_boundary_ok(!str_contains(hache_sharky_product_boundary_normalize($guarded),'3 clases por semana')&&str_contains(hache_sharky_product_boundary_normalize($guarded),'persona del equipo'),'Model output cannot leak a regular plan to a beginner.');
$guardedPending=hache_sharky_product_boundary_model_answer($modelRegular,$unknownBackground,'¿Qué planes hay?');
product_boundary_ok(str_contains(hache_sharky_product_boundary_normalize($guardedPending),'has tomado clases formales'),'Model output cannot offer regular plans before formal-history qualification.');
$guardedFresh=hache_sharky_product_boundary_model_answer($modelRegular,$fresh,'¿Qué opciones tienen?');
product_boundary_ok(str_contains(hache_sharky_product_boundary_normalize($guardedFresh),'has tomado clases formales'),'A model-authored regular offer must be blocked even on a first-turn prospect with empty commercial context.');
$intensiveAnswer='El curso intensivo dura 3 semanas, de lunes a viernes.';
product_boundary_ok(hache_sharky_product_boundary_model_answer($intensiveAnswer,$beginner,'¿Cómo funciona?')===$intensiveAnswer,'An intensive-only answer must remain untouched.');

$memoryDirty=$dirty;
$memoryClean=hache_sharky_commercial_force_intensive_eligibility($memoryDirty);
product_boundary_ok(($memoryClean['commercial_context']['program']??null)==='intensive','Commercial memory must enforce intensive eligibility, not only the model firewall.');
product_boundary_ok(!isset($memoryClean['commercial_context']['plan_id']),'Commercial memory must discard a regular plan from a restricted prospect.');

$uiState=$beginner;
unset($uiState['commercial_context']['program']);
$uiState['commercial_context']['recommended_program']='intensive';
$ui=hache_sharky_commercial_control_ui($uiState,[],['slot'=>'program']);
$ids=array_column($ui['buttons']??[],'id');
product_boundary_ok($ids===['qualify:intensive'],'The guided UI must not expose “Ver regulares” to a restricted prospect.');

$guided=hache_sharky_orchestrator_state(null,time());
$guided['identity']=array_replace($guided['identity'],['kind'=>'prospect','verified'=>false]);
$guided['commercial_context']['swim_level']='swims';
$guided=hache_sharky_orchestrator_flow($guided,'qualify_prospect','background',[],time());
$guided=hache_sharky_commercial_reconcile_guidance($guided,'Nado un poco pero nunca he tomado clases de natación');
product_boundary_ok(($guided['commercial_context']['background']??null)==='no_formal','Natural no-formal statement must persist as no_formal.');
product_boundary_ok(($guided['commercial_context']['program']??null)==='intensive','No-formal qualification must select intensive directly instead of reopening product choice.');
product_boundary_ok(($guided['commercial_context']['recommended_program']??null)==='intensive','No-formal qualification must keep intensive as the canonical recommendation.');

product_boundary_ok(!hache_sharky_product_boundary_explicit_regular_choice('De las dos'),'“De las dos” must never count as an explicit product switch.');
product_boundary_ok(!hache_sharky_product_boundary_explicit_regular_choice('Ambas'),'“Ambas” must never count as an explicit product switch.');

$dispatcher=file_get_contents(__DIR__.'/../public/api/sharky-whatsapp-dispatch.php')?:'';
product_boundary_ok(str_contains($dispatcher,'sharky-product-boundary-guard.php'),'WhatsApp dispatcher must load the product boundary guard.');
product_boundary_ok(str_contains($dispatcher,'hache_sharky_product_boundary_sanitize_state($state,$message)'),'Commercial state must be sanitized before deterministic/model routing.');
product_boundary_ok(str_contains($dispatcher,'hache_sharky_product_boundary_reply($deterministicInput,$state)'),'Product eligibility must run before the open model path.');
product_boundary_ok(str_contains($dispatcher,'hache_sharky_product_boundary_model_answer($answer,$state,$message)'),'Model output must pass through the final product-eligibility firewall.');

$policy=file_get_contents(__DIR__.'/../config/sharky-post-pr72.php')?:'';
product_boundary_ok(str_contains($policy,'REGLA DE ELEGIBILIDAD'),'The model policy must state the hard product eligibility rule explicitly.');
product_boundary_ok(str_contains($policy,'Una excepción a regulares solo puede decidirla una persona del equipo'),'Regular-class exceptions for restricted prospects must remain human-only.');

fwrite(STDOUT,"SHARKY_PRODUCT_BOUNDARY_OK\n");