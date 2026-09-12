<?php

declare(strict_types=1);

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

$palapas=$state;$palapas['commercial_context']['sede_clave']='PALAPAS';
$unchanged=hache_sharky_learning_guard_enforce_venue_priority($equalVenue,$palapas);
learning_correction_ok($unchanged===$equalVenue,'A confirmed venue must never be overwritten by the priority guard.');
learning_correction_ok(hache_sharky_learning_guard_prevenue_reply('¿Qué precio tiene?',$palapas,1200)===null,'Pre-venue guard must not intercept after venue confirmation.');

$unknown=$state;$unknown['commercial_context']['program']=null;
learning_correction_ok(hache_sharky_learning_guard_enforce_venue_priority($equalVenue,$unknown)===$equalVenue,'Venue priority guard must not choose a venue before canonical product.');
learning_correction_ok(hache_sharky_learning_guard_prevenue_reply('¿Dónde están las sedes?',$unknown,1200)===null,'General venue help before product must remain untouched.');

$dispatcher=file_get_contents(__DIR__.'/../public/api/sharky-whatsapp-dispatch.php')?:'';
learning_correction_ok(str_contains($dispatcher,"sharky-learning-correction-guards.php"),'WhatsApp loopback dispatcher must load approved learning guards.');
learning_correction_ok(str_contains($dispatcher,'hache_sharky_learning_guard_prevenue_reply'),'Dispatcher must answer pre-venue intensive price/schedule questions deterministically.');
learning_correction_ok(str_contains($dispatcher,'hache_sharky_learning_guard_enforce_confirmed_swim'),'Dispatcher must protect confirmed swim context on model output.');
learning_correction_ok(str_contains($dispatcher,'hache_sharky_learning_guard_enforce_venue_priority'),'Dispatcher must enforce Monteverde-first on model output.');

echo "SHARKY_LEARNING_APPROVED_CORRECTIONS_OK\n";
