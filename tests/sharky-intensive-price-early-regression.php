<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-whatsapp-adapter.php';
require_once __DIR__.'/../config/sharky-brain-live-router.php';
require_once __DIR__.'/../config/sharky-deterministic-replies.php';

function intensive_price_ok(bool $condition,string $message): void
{
    if(!$condition){fwrite(STDERR,"SHARKY INTENSIVE PRICE FAIL: {$message}\n");exit(1);}
}

$state=hache_sharky_orchestrator_state(null,1788886800);
$state['identity']=array_replace($state['identity'],[
    'kind'=>'prospect','verified'=>false,'source'=>'whatsapp_unmatched',
]);
$state['flow']=['name'=>'qualify_prospect','step'=>'sede','data'=>['venue_proposal'=>'MONTEVERDE']];
$state['commercial_context']['program']='intensive';
$state['commercial_context']['recommended_program']='intensive';
$state['commercial_context']['swim_level']='beginner';

$answer="Perfecto, desde cero lo mejor para ti es el curso intensivo (3 semanas, lunes a viernes).\n\n¿Cuál sede prefieres: Colegio Monteverde o Palapas Protudec?";
$guarded=hache_sharky_brain_conversational_intensive_offer_guard($answer,$state,['sharky_precio_intensivo'=>'1200'],'sede');
intensive_price_ok(
    str_contains($guarded,'curso intensivo (3 semanas, lunes a viernes, $1,200 MXN)'),
    'La primera oferta del intensivo debe incluir duración, frecuencia y precio.'
);
intensive_price_ok(
    str_contains($guarded,'¿Cuál sede prefieres'),
    'Agregar el precio no debe romper la siguiente pregunta de sede.'
);
intensive_price_ok(
    substr_count($guarded,'curso intensivo')===1,
    'La normalización no debe duplicar la oferta del intensivo.'
);

$custom=hache_sharky_brain_conversational_intensive_offer_guard('¿Qué sede prefieres?',$state,['sharky_precio_intensivo'=>'1350'],'sede');
intensive_price_ok(
    str_contains($custom,'3 semanas, lunes a viernes, $1,350 MXN'),
    'El guard debe usar el precio vigente del contexto comercial, no fijar $1,200.'
);

$liveConfig=hache_sharky_brain_2ba_merge_business_values(
    hache_sharky_brain_2ba_config_defaults(),
    ['sharky_precio_intensivo'=>'1350']
);
intensive_price_ok(
    ($liveConfig['sharky_precio_intensivo']??null)==='1350',
    'La configuración live de Brain debe incorporar el precio vigente del negocio.'
);

$regular=$state;$regular['commercial_context']['program']='regular';
intensive_price_ok(
    hache_sharky_brain_conversational_intensive_offer_guard($answer,$regular,['sharky_precio_intensivo'=>'1200'],'sede')===$answer,
    'El guard no debe modificar clases regulares.'
);
intensive_price_ok(
    hache_sharky_brain_conversational_intensive_offer_guard($answer,$state,['sharky_precio_intensivo'=>'1200'],'swim')===$answer,
    'El guard solo debe actuar al presentar el intensivo antes de elegir sede.'
);

$priceState=$state;
$priceState['commercial_context']['sede_clave']='MONTEVERDE';
intensive_price_ok(
    hache_sharky_deterministic_price_request('Son 1200 mensual?'),
    'Una pregunta frecuente con la palabra mensual debe entrar a la respuesta determinística de precio.'
);
$monthlyReply=hache_sharky_deterministic_reply('Son 1200 mensual?',$priceState)??'';
intensive_price_ok(
    str_contains($monthlyReply,'no es mensual')&&str_contains($monthlyReply,'curso completo de 3 semanas')&&str_contains($monthlyReply,'$1,200 MXN'),
    'El intensivo debe aclarar explícitamente que $1,200 cubre las 3 semanas completas y no un mes.'
);
$perMonthReply=hache_sharky_deterministic_reply('¿Son 1200 al mes?',$priceState)??'';
intensive_price_ok(
    str_contains($perMonthReply,'no es mensual')&&str_contains($perMonthReply,'curso completo de 3 semanas'),
    'La variante al mes también debe aclarar que el intensivo cubre 3 semanas completas.'
);
$plainPriceReply=hache_sharky_deterministic_reply('¿Cuánto cuesta?',$priceState)??'';
intensive_price_ok(
    !str_contains($plainPriceReply,'no es mensual')&&str_contains($plainPriceReply,'Duración: 3 semanas, lunes a viernes'),
    'Una pregunta normal de precio conserva la respuesta breve existente sin añadir una negación innecesaria.'
);
$startMonthReply=hache_sharky_deterministic_reply('¿Cuánto cuesta el curso que empieza el próximo mes?',$priceState)??'';
intensive_price_ok(
    !str_contains($startMonthReply,'no es mensual'),
    'Mes usado como fecha de inicio no debe confundirse con una pregunta de cobro mensual.'
);

$source=(string)file_get_contents(__DIR__.'/../config/sharky-brain-live-router.php');
$cleanup=strpos($source,'hache_sharky_brain_conversational_strip_opening_filler($answer)');
$guard=strpos($source,'hache_sharky_brain_conversational_intensive_offer_guard($answer,$openState,$business,$qualificationStep)');
intensive_price_ok(
    $cleanup!==false&&$guard!==false&&$guard>$cleanup,
    'El guard de precio debe estar conectado al pipeline final de Brain después de limpiar la respuesta.'
);
intensive_price_ok(
    str_contains($source,'hache_sharky_brain_2ba_merge_business_values($values,hache_sharky_business_values($pdo))'),
    'La ruta live debe cargar el precio vigente desde la configuración comercial antes de aplicar Brain.'
);

fwrite(STDOUT,"SHARKY_INTENSIVE_PRICE_EARLY_OK\n");
