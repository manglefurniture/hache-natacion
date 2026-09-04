<?php

declare(strict_types=1);

require __DIR__.'/../config/sharky-whatsapp-adapter.php';

function commercial_next_ok(bool $condition,string $message): void
{
    if(!$condition){fwrite(STDERR,"SHARKY COMMERCIAL NEXT ACTION FAIL: $message\n");exit(1);}
}

$state=hache_sharky_orchestrator_state(null,1788460000);
$state['identity']=array_replace($state['identity'],[
    'kind'=>'prospect','verified'=>true,'source'=>'self_declared',
]);
$state['commercial_context']['program']='intensive';
$state['commercial_context']['sede_clave']='PALAPAS';

$menu=hache_sharky_whatsapp_commercial_next_action($state);
commercial_next_ok(($menu['kind']??null)==='commercial_next_action','Commercially ready intensive prospect must receive a next-action decision.');
commercial_next_ok(
    array_column($menu['ui']['buttons']??[],'id')===['action:commercial_schedules','action:commercial_price','action:register_intensive'],
    'Intensive menu must expose Horarios, Precio and Inscribirme in that order.'
);
$payload=hache_sharky_whatsapp_render('529980000000',$menu);
commercial_next_ok(($payload['type']??null)==='interactive','Next-action decision must render as WhatsApp interactive buttons.');
commercial_next_ok(count($payload['interactive']['action']['buttons']??[])===3,'Intensive menu must render exactly three buttons.');

// Schedule/price buttons deliberately remain contextual questions: their visible titles
// reach the normal conversation path with program + venue memory. Registration keeps the
// existing controlled intent and consent flow, while stale intensive actions are rejected.
commercial_next_ok(hache_sharky_whatsapp_interactive_is_current($state,['interactive_id'=>'action:commercial_schedules']),'Schedule action must be valid while there is no controlled flow.');
commercial_next_ok(hache_sharky_whatsapp_interactive_is_current($state,['interactive_id'=>'action:commercial_price']),'Price action must be valid while there is no controlled flow.');
commercial_next_ok(hache_sharky_orchestrator_intent('Horarios','action:commercial_schedules')==='conversation','Schedule action must reach contextual conversation using the confirmed commercial state.');
commercial_next_ok(hache_sharky_orchestrator_intent('Precio','action:commercial_price')==='conversation','Price action must reach contextual conversation using the confirmed commercial state.');
commercial_next_ok(hache_sharky_orchestrator_intent('Inscribirme','action:register_intensive')==='register_intensive','Registration action must keep the existing controlled registration intent.');

foreach(['Oki','Continuar','Información','Ok','Dale','Perfecto'] as $continuation){
    commercial_next_ok(
        hache_sharky_whatsapp_low_information_reengagement($continuation),
        $continuation.' must be treated as a continuation of the confirmed commercial context.'
    );
}
commercial_next_ok(!hache_sharky_whatsapp_low_information_reengagement('¿Cuánto cuesta?'),'Substantive price question must not be collapsed into the generic menu.');
commercial_next_ok(!hache_sharky_whatsapp_low_information_reengagement('Quiero cambiar a Monteverde'),'Explicit context correction must not be collapsed into the generic menu.');

$regular=$state;
$regular['commercial_context']['program']='regular';
$regularMenu=hache_sharky_whatsapp_commercial_next_action($regular);
commercial_next_ok(array_column($regularMenu['ui']['buttons']??[],'id')===['action:commercial_schedules','action:commercial_price'],'Regular menu must not offer automatic intensive registration.');
commercial_next_ok(!hache_sharky_whatsapp_interactive_is_current($regular,['interactive_id'=>'action:register_intensive']),'An old intensive registration button must become stale after the confirmed program changes to regular.');

$pdo=new PDO('sqlite::memory:');
$regularSedeFlow=hache_sharky_orchestrator_flow($regular,'qualify_prospect','sede',[],1788460010);
[$regularAfterSede,$regularAfterSedeDecision]=hache_sharky_whatsapp_qualification_input($pdo,$regularSedeFlow,['text'=>'Palapas','interactive_id'=>'sede:palapas'],1788460011,12);
commercial_next_ok(($regularAfterSede['flow']??null)===null,'Selecting a regular venue must finish guided discovery instead of opening a daypart gate.');
commercial_next_ok(($regularAfterSedeDecision['kind']??null)==='commercial_next_action','The normal regular venue path must converge on the next-action menu.');
commercial_next_ok(array_column($regularAfterSedeDecision['ui']['buttons']??[],'id')===['action:commercial_schedules','action:commercial_price'],'The normal regular path must expose Horarios and Precio immediately after venue selection.');

// Historical production regression: once regular + Palapas is confirmed, the LLM
// cannot make Sharky ask the venue again or resurrect Monteverde as a new choice.
$knownPalapas=$regular;
$knownPalapas['commercial_context']['age']=59;
$filtered=hache_sharky_whatsapp_enforce_confirmed_context(
    'Tenemos clases regulares en Monteverde y Palapas. ¿En qué sede prefieres tomar las clases: Monteverde o Palapas Protudec?',
    $knownPalapas
);
commercial_next_ok(!hache_sharky_whatsapp_answer_asks_slot($filtered,'sede'),'Confirmed Palapas context must never re-ask venue.');

$source=file_get_contents(__DIR__.'/../config/sharky-whatsapp-adapter.php')?:'';
commercial_next_ok(substr_count($source,'hache_sharky_whatsapp_commercial_next_action($state')>=4,'Commercial completion and reengagement paths must converge on the deterministic menu.');
commercial_next_ok(str_contains($source,"'action:commercial_schedules','Horarios'"),'Schedule button must remain deterministic.');
commercial_next_ok(str_contains($source,"'action:commercial_price','Precio'"),'Price button must remain deterministic.');

echo "SHARKY_COMMERCIAL_NEXT_ACTION_OK\n";
