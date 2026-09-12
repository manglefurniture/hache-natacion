<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-deterministic-replies.php';
require_once __DIR__.'/../config/sharky-schedule-scope-guard.php';
require_once __DIR__.'/../config/sharky-learning-correction-guards.php';

function learning_correction_ok(bool $condition,string $message): void
{
    if(!$condition){fwrite(STDERR,"FAIL: $message\n");exit(1);}
}

$state=[
    'identity'=>['kind'=>'prospect'],
    'commercial_context'=>[
        'program'=>'intensive',
        'swim_level'=>'beginner',
        'sede_clave'=>null,
    ],
];

$repeated='El curso intensivo es el indicado. ¿Sabes nadar aunque sea un poquito o empiezas desde cero?';
$swimSafe=hache_sharky_learning_guard_enforce_confirmed_swim($repeated,$state);
learning_correction_ok(!str_contains(mb_strtolower($swimSafe,'UTF-8'),'sabes nadar'),'A confirmed swim level must not be asked again.');
learning_correction_ok(str_contains($swimSafe,'curso intensivo'),'Useful non-repeated content must survive the swim guard.');

$equalVenue='Sí 😊 Enseñamos desde cero con nuestro curso intensivo. ¿En qué sede te gustaría hacerlo: Colegio Monteverde o Palapas Protudec?';
$venueSafe=hache_sharky_learning_guard_enforce_venue_priority($equalVenue,$state);
learning_correction_ok(str_contains($venueSafe,'Te propongo primero Colegio Monteverde'),'Unconfirmed venue must be framed with Monteverde first.');
learning_correction_ok(!str_contains($venueSafe,'¿En qué sede te gustaría'),'Equal-choice venue question must be removed.');

$direct=hache_sharky_learning_guard_prevenue_reply('¿Qué horarios y precios tienen?',$state,1200);
learning_correction_ok(is_string($direct)&&str_contains($direct,'3 semanas'),'Direct intensive price question must answer duration.');
learning_correction_ok(str_contains((string)$direct,'lunes a viernes'),'Direct intensive price question must answer frequency.');
learning_correction_ok(str_contains((string)$direct,'$1,200 MXN'),'Direct intensive price question must answer current price.');
learning_correction_ok(str_contains((string)$direct,'dependen de la sede'),'Schedule request before venue must explain venue scope.');
learning_correction_ok(str_contains((string)$direct,'Te propongo primero Colegio Monteverde'),'Direct question must continue with Monteverde-first venue proposal.');
learning_correction_ok(!str_contains(mb_strtolower((string)$direct,'UTF-8'),'sabes nadar'),'Direct question must not restart level qualification.');

learning_correction_ok(hache_sharky_learning_guard_prevenue_reply('¿Cuánto cuesta el kit de gorro y goggles?',$state,1200)===null,'Kit price must remain in its authoritative flow.');
learning_correction_ok(hache_sharky_learning_guard_prevenue_reply('¿Cuánto cuesta la inscripción?',$state,1200)===null,'Enrollment fee must remain in its authoritative flow.');
learning_correction_ok(hache_sharky_learning_guard_prevenue_reply('¿Cuál es el recargo por pagar con tarjeta?',$state,1200)===null,'Card fee must remain in its authoritative flow.');
learning_correction_ok(hache_sharky_learning_guard_prevenue_reply('¿A qué hora cierran?',$state,1200)===null,'Operating-hours questions must not be treated as course schedule questions.');
learning_correction_ok(hache_sharky_learning_guard_prevenue_reply('¿Qué precio tiene el curso?',$state,1200)!==null,'Explicit course price must still be handled before venue.');
learning_correction_ok(hache_sharky_learning_guard_prevenue_reply('¿Qué horarios tienen?',$state,1200)!==null,'Generic schedule must still refer to the active intensive.');
learning_correction_ok(hache_sharky_learning_guard_prevenue_reply('¿Cuánto cuesta?',$state,1200)!==null,'Generic price must still refer to the active intensive.');

$locationState=$state;
$locationState['previous_assistant_text']='Perfecto. 📍 Te propongo primero Colegio Monteverde. ¿Te funciona esta sede?';
$locationDirect=hache_sharky_learning_guard_pending_location_reply('¿En dónde está?',$locationState);
learning_correction_ok(is_string($locationDirect)&&$locationDirect!=='','A location question must inherit the single venue just proposed.');
learning_correction_ok(!str_contains((string)$locationDirect,'¿Necesitas la ubicación de Colegio Monteverde o la de Palapas Protudec?'),'Inherited single venue must not ask the user to choose the venue again.');

// El productor real no siempre serializa el turno anterior del asistente. Con
// producto canónico y sede aún vacía, una referencia singular sigue apuntando a
// la propuesta obligatoria de Monteverde.
$locationWithoutAssistant=$state;
$locationWithoutAssistant['previous_assistant_text']='';
$locationFallback=hache_sharky_learning_guard_pending_location_reply('¿En dónde está?',$locationWithoutAssistant);
learning_correction_ok(is_string($locationFallback)&&str_contains($locationFallback,'Colegio Monteverde'),'Singular location reference must resolve to the canonical Monteverde proposal without assistant history.');
learning_correction_ok(!str_contains((string)$locationFallback,'Palapas Protudec'),'Singular Monteverde fallback must not re-open both venues.');

$locationChoiceState=$state;
$locationChoiceState['previous_user_text']='¿En dónde está?';
$locationChoiceState['previous_assistant_text']='Claro. ¿Necesitas la ubicación de Colegio Monteverde o la de Palapas Protudec?';
$locationChoice=hache_sharky_learning_guard_pending_location_reply('Monteverde',$locationChoiceState);
learning_correction_ok(is_string($locationChoice)&&$locationChoice!=='','Venue-only reply after a location question must complete location intent.');
learning_correction_ok(!str_contains(mb_strtolower((string)$locationChoice,'UTF-8'),'horarios vigentes'),'Pending location intent must not jump to schedules.');

$mixedCash=hache_sharky_learning_guard_prevenue_reply('¿Cuánto cuesta el curso si pago en efectivo?',$state,1200);
learning_correction_ok(is_string($mixedCash)&&str_contains($mixedCash,'$1,200 MXN'),'Explicit course price with cash context must keep the authoritative intensive price.');
$mixedTransfer=hache_sharky_learning_guard_prevenue_reply('¿Cuánto sale el intensivo si pago por transferencia?',$state,1200);
learning_correction_ok(is_string($mixedTransfer)&&str_contains($mixedTransfer,'$1,200 MXN'),'Explicit intensive price with transfer context must remain deterministic.');
learning_correction_ok(hache_sharky_learning_guard_prevenue_reply('¿Cuánto cuesta pagar con tarjeta?',$state,1200)===null,'Payment-only price question must stay in the payment authority flow.');
learning_correction_ok(hache_sharky_learning_guard_prevenue_reply('¿Cuánto cuesta el curso si pago con tarjeta?',$state,1200)===null,'Course price with card payment must stay in surcharge/payment authority.');
learning_correction_ok(hache_sharky_learning_guard_prevenue_reply('¿Cuánto cuesta el curso y la inscripción?',$state,1200)===null,'Mixed course and enrollment fee question must not hide the independent enrollment authority.');

$historyData=['history'=>[
    ['role'=>'assistant','content'=>'Te propongo primero Colegio Monteverde.'],
    ['role'=>'user','content'=>'me quedaría más cerca la de las palapas'],
    ['role'=>'assistant','content'=>'Perfecto, entonces sería en Palapas Protudec.'],
]];
$recovered=hache_sharky_learning_guard_recover_recent_venue($historyData,$state);
learning_correction_ok(($recovered['commercial_context']['sede_clave']??null)==='PALAPAS','Natural proximity choice must recover Palapas as the active venue.');
$loader=static function(string $program,string $sede): array {
    if($program!=='intensive')return [];
    return $sede==='PALAPAS'
        ?['07:00–08:00','08:00–09:00','09:00–10:00','20:00–21:00']
        :['08:00–09:00','19:00–20:00','20:00–21:00'];
};
$evening=hache_sharky_schedule_guard_scoped_reply('por la tarde??',$recovered,$loader)??'';
learning_correction_ok(str_contains($evening,'Palapas Protudec')&&str_contains($evening,'20:00–21:00'),'Evening follow-up after Palapas choice must stay in Palapas intensive.');
learning_correction_ok(!str_contains($evening,'Colegio Monteverde'),'Palapas evening follow-up must not mix Monteverde schedules.');
learning_correction_ok(!str_contains($evening,'19:00–20:00'),'Monteverde-only intensive slot must not leak into Palapas.');

$palapas=$state;$palapas['commercial_context']['sede_clave']='PALAPAS';
$unchanged=hache_sharky_learning_guard_enforce_venue_priority($equalVenue,$palapas);
learning_correction_ok($unchanged===$equalVenue,'A confirmed venue must never be overwritten by the priority guard.');
learning_correction_ok(hache_sharky_learning_guard_prevenue_reply('¿Qué precio tiene?',$palapas,1200)===null,'Pre-venue guard must not intercept after venue confirmation.');

$unknown=$state;$unknown['commercial_context']['program']=null;
learning_correction_ok(hache_sharky_learning_guard_enforce_venue_priority($equalVenue,$unknown)===$equalVenue,'Venue priority guard must not choose a venue before canonical product.');
learning_correction_ok(hache_sharky_learning_guard_prevenue_reply('¿Dónde están las sedes?',$unknown,1200)===null,'General venue help before product must remain untouched.');

$dispatcher=file_get_contents(__DIR__.'/../public/api/sharky-whatsapp-dispatch.php')?:'';
learning_correction_ok(str_contains($dispatcher,"sharky-learning-correction-guards.php"),'WhatsApp loopback dispatcher must load approved learning guards.');
learning_correction_ok(str_contains($dispatcher,'hache_sharky_learning_guard_recover_recent_venue'),'Dispatcher must recover a recent natural venue selection before schedule scoping.');
learning_correction_ok(str_contains($dispatcher,'hache_sharky_learning_guard_prevenue_reply'),'Dispatcher must answer pre-venue intensive price/schedule questions deterministically.');
learning_correction_ok(str_contains($dispatcher,'hache_sharky_learning_guard_enforce_confirmed_swim'),'Dispatcher must protect confirmed swim context on model output.');
learning_correction_ok(str_contains($dispatcher,'hache_sharky_learning_guard_enforce_venue_priority'),'Dispatcher must enforce Monteverde-first on model output.');

echo "SHARKY_LEARNING_APPROVED_CORRECTIONS_OK\n";
