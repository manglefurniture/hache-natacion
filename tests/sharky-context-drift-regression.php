<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-orchestrator.php';
require_once __DIR__.'/../config/sharky-whatsapp-adapter.php';

function drift_ok(bool $condition,string $message): void
{
    if(!$condition){fwrite(STDERR,"SHARKY CONTEXT DRIFT FAIL: {$message}\n");exit(1);}
}

$state=hache_sharky_orchestrator_state(null,1788489000);
$state['identity']=array_replace($state['identity'],['kind'=>'prospect','verified'=>true,'source'=>'self_declared']);
$state['commercial_context']['sede_clave']='MONTEVERDE';

drift_ok(
    hache_sharky_whatsapp_detect_relative_venue_preference($state,'Estoy desde 0 pero quiero la otra sede que tienen.')==='PALAPAS',
    '"quiero la otra sede" must flip Monteverde to Palapas.'
);
$state=hache_sharky_whatsapp_apply_natural_venue_preference($state,'Estoy desde 0 pero quiero la otra sede que tienen.');
drift_ok(($state['commercial_context']['sede_clave']??null)==='PALAPAS','Natural venue preference must persist Palapas.');
$state=hache_sharky_whatsapp_apply_natural_swim_level($state,'Estoy desde 0 pero quiero la otra sede que tienen.');
drift_ok(($state['commercial_context']['swim_level']??null)==='beginner','Starting from zero must be durable context.');
drift_ok(($state['commercial_context']['program']??null)==='intensive','Beginner statement must reuse the existing controlled recommendation of intensive.');

drift_ok(hache_sharky_whatsapp_offer_affirmation('Perfecto!'),'Perfecto must advance a ready intensive funnel.');
drift_ok(hache_sharky_whatsapp_offer_affirmation('Me parece bien.'),'Me parece bien must advance a ready intensive funnel.');
drift_ok(hache_sharky_whatsapp_offer_affirmation('Anja??'),'Colloquial Anja/Ajá continuation must not be treated as a person name.');
drift_ok(!hache_sharky_whatsapp_offer_affirmation('No'),'No must not be treated as consent.');

drift_ok(hache_sharky_whatsapp_registration_help_continuation('No me puedes ayudar tú??'),'Registration help continuation must stay inside Sharky.');

drift_ok(hache_sharky_whatsapp_answer_looks_incomplete("¡Claro!\n\n😊 Para darte informes correctos necesito primero ubicar qué te interesa:\n\n•"),'Dangling discovery bullet must be rejected.');
drift_ok(hache_sharky_whatsapp_answer_looks_incomplete('Como ya mencionaste que quieres “la otra sede”,'),'Dangling comma must be rejected.');
drift_ok(hache_sharky_whatsapp_answer_looks_incomplete("¡Genial!\n\n😊 Para avanzar:"),'Dangling Para avanzar must be rejected.');
drift_ok(hache_sharky_whatsapp_answer_looks_incomplete("Sí, claro 😊 Yo te guío.\n\nAntes de registrarte, dime por favor:"),'Dangling registration prompt must be rejected.');
drift_ok(hache_sharky_whatsapp_answer_looks_incomplete("¡Claro!\n\n😊\n\n¿En qué necesitas ayuda exactamente?\n\n•"),'Question followed by empty bullet must be rejected.');
drift_ok(!hache_sharky_whatsapp_answer_looks_incomplete('Perfecto. Ya tengo tu sede y el curso. ¿Quieres horarios o precio?'),'Complete answer must remain valid.');

$recovery=hache_sharky_whatsapp_incomplete_recovery($state);
$normalized=hache_sharky_orchestrator_normalize($recovery);
drift_ok(str_contains($normalized,'palapas protudec'),'Recovery must preserve the corrected venue.');
drift_ok(str_contains($normalized,'curso intensivo'),'Recovery must preserve the selected/recommended program.');
drift_ok(!str_contains($normalized,'monteverde'),'Recovery must not resurrect the previous venue.');

$repeat=hache_sharky_whatsapp_enforce_confirmed_context('¿Tú ya sabes nadar o estás empezando desde cero?',$state);
drift_ok(!str_contains(hache_sharky_orchestrator_normalize($repeat),'sabes nadar'),'Known swim level must suppress repeated swim discovery.');

$instruction=hache_sharky_whatsapp_style_instruction(['kind'=>'conversation'],$state);
drift_ok(str_contains(hache_sharky_orchestrator_normalize($instruction),'experiencia: empieza desde cero'),'Model context must include the known beginner level.');

echo "SHARKY_CONTEXT_DRIFT_REGRESSION_OK\n";
