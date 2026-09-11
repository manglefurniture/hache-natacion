<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-whatsapp-adapter.php';
require_once __DIR__.'/../config/sharky-brain-live-router.php';
require_once __DIR__.'/../config/sharky-post-pr72.php';

function brain_conversational_ok(bool $condition,string $message): void
{
    if(!$condition){fwrite(STDERR,"SHARKY BRAIN CONVERSATIONAL FAIL: {$message}\n");exit(1);}
}

function hache_sharky_lab_answer(string $text,string $instruction,array $state,array $context): string
{
    $GLOBALS['brain_conversational_model_calls']=(int)($GLOBALS['brain_conversational_model_calls']??0)+1;
    $GLOBALS['brain_conversational_instruction']=$instruction;
    $GLOBALS['brain_conversational_model_text']=$text;
    return (string)($GLOBALS['brain_conversational_model_answer']??'Claro. Dime qué quieres resolver y te oriento.');
}

function brain_conversational_state(?array $flow=null): array
{
    $state=hache_sharky_orchestrator_state(null,1788886800);
    $state['identity']=array_replace($state['identity'],[
        'kind'=>'prospect',
        'verified'=>false,
        'source'=>'whatsapp_unmatched',
    ]);
    $state['flow']=$flow;
    return $state;
}

$defaults=hache_sharky_brain_2ba_config_defaults();
brain_conversational_ok(
    ($defaults[HACHE_SHARKY_BRAIN_CONVERSATIONAL_ENABLED_KEY]??null)==='1',
    'The conversational experiment must default ON for the requested production trial.'
);
brain_conversational_ok(
    hache_sharky_brain_2ba_config_value_valid(HACHE_SHARKY_BRAIN_CONVERSATIONAL_ENABLED_KEY,'0')
    &&hache_sharky_brain_2ba_config_value_valid(HACHE_SHARKY_BRAIN_CONVERSATIONAL_ENABLED_KEY,'1')
    &&!hache_sharky_brain_2ba_config_value_valid(HACHE_SHARKY_BRAIN_CONVERSATIONAL_ENABLED_KEY,'2'),
    'Conversational kill switch must accept only binary values.'
);

$rows=hache_sharky_brain_2ba_config_rows($defaults);
$conversationRow=null;
foreach($rows as $row){
    if(($row['clave']??'')===HACHE_SHARKY_BRAIN_CONVERSATIONAL_ENABLED_KEY){$conversationRow=$row;break;}
}
brain_conversational_ok(
    is_array($conversationRow)&&($conversationRow['tipo']??'')==='checkbox',
    'Admin must expose the conversational experiment as a checkbox kill switch.'
);

$contact='529980001234';
$config=[
    HACHE_SHARKY_BRAIN_2BA_ENABLED_KEY=>'1',
    HACHE_SHARKY_BRAIN_2BA_CANARY_KEY=>'10',
    HACHE_SHARKY_BRAIN_CONVERSATIONAL_ENABLED_KEY=>'1',
];
$before=brain_conversational_state(null);
$guidedState=brain_conversational_state([
    'name'=>'qualify_prospect',
    'step'=>'swim',
    'data'=>[],
]);
$guidedState['commercial_context']['entry_source']='meta_ad';
$guidedState['commercial_context']['entry_interest']='intensive';
$coalescedText="Hola, quiero información\nTambién quiero saber precios y horarios";
$guidedState['last_user_text']=$coalescedText;
$guidedRaw=[
    'state'=>$guidedState,
    'decision'=>hache_sharky_orchestrator_decision('qualification_swim','Elige una opción.'),
    'payload'=>hache_sharky_whatsapp_text_payload($contact,'Elige una opción.'),
    'action_result'=>null,
];
$event=['id'=>'brain:open','from'=>$contact,'type'=>'text','text'=>'También quiero saber precios y horarios'];

$GLOBALS['brain_conversational_model_calls']=0;
$GLOBALS['brain_conversational_model_answer']="¡Hola!\n\n¡Claro!\n\n¿Ya sabes nadar o estás empezando desde cero?";
$open=hache_sharky_brain_2ba_apply($before,$guidedRaw,$event,$config,$contact,true,1788886801);
brain_conversational_ok(
    ($open['state']['flow']['name']??null)==='qualify_prospect'
    &&($open['state']['flow']['step']??null)==='swim',
    'Conversational mode must retain the qualification cursor so short next-turn replies keep their exact meaning.'
);
brain_conversational_ok(
    ($open['state']['brain_conversational_experiment']??false)===true,
    'Open conversations must carry a durable experiment marker so OFF can restore them safely.'
);
brain_conversational_ok(
    ($open['decision']['kind']??'')==='conversation'&&($open['payload']['type']??'')==='text',
    'The opened qualification turn must become a natural text conversation.'
);
brain_conversational_ok(
    ($open['_brain_2ba']['applied']??false)===true
    &&($open['_brain_conversational']['mode']??'')==='open',
    'Worker durable-boundary marker must be reused so state and payload are committed together.'
);
brain_conversational_ok(
    ($GLOBALS['brain_conversational_model_calls']??0)===1,
    'Opening a guided qualification must call the conversational model exactly once.'
);
brain_conversational_ok(
    ($GLOBALS['brain_conversational_model_text']??'')===$coalescedText,
    'Brain must answer the coalesced debounce text retained in state, not the worker original fragment.'
);
$openingBody=(string)($open['payload']['text']['body']??'');
brain_conversational_ok(
    !str_contains($openingBody,'¡Hola!')&&!str_contains($openingBody,'¡Claro!')
    &&str_contains($openingBody,'¿Ya sabes nadar'),
    'The experimental opening must remove redundant greeting/acknowledgement filler before deterministic presentation is added.'
);
brain_conversational_ok(
    str_contains((string)($GLOBALS['brain_conversational_instruction']??''),'operación sensible'),
    'Brain prompt must explicitly deny authority over sensitive operations.'
);
brain_conversational_ok(
    str_contains((string)($GLOBALS['brain_conversational_instruction']??''),'no preguntes intensivo vs. clases regulares')
    &&str_contains((string)($GLOBALS['brain_conversational_instruction']??''),'no vuelques todos los horarios'),
    'Experimental prompt must respect ad intent and keep mobile replies concise.'
);
brain_conversational_ok(
    str_contains((string)($GLOBALS['brain_conversational_instruction']??''),'Paso pendiente de calificación: NIVEL'),
    'Brain must receive the exact pending qualification slot while keeping the visible reply conversational.'
);
unset($GLOBALS['brain_conversational_model_answer']);

$guardRaw=$guidedRaw;
$guardRaw['state']['assistant_presentation_queued']=true;
$GLOBALS['brain_conversational_model_answer']="Hola\nHola\n\nTe ayudo con eso.";
$guarded=hache_sharky_brain_2ba_apply($before,$guardRaw,$event,$config,$contact,true,1788886802);
$guardBody=(string)($guarded['payload']['text']['body']??'');
brain_conversational_ok(
    substr_count($guardBody,'Hola')<=1,
    'Experimental model output must pass through the normal WhatsApp cleanup pipeline.'
);
unset($GLOBALS['brain_conversational_model_answer']);

brain_conversational_ok(
    hache_sharky_brain_conversational_explicit_pause('Deje analizar distancia hogar, trabajo y horarios por favor'),
    'A clear request to stop and analyze options must be recognized as an explicit pause.'
);
brain_conversational_ok(
    !hache_sharky_brain_conversational_explicit_pause('Quiero ver horarios por favor'),
    'A normal request to see information must never be mistaken for a pause.'
);
brain_conversational_ok(
    !hache_sharky_brain_conversational_explicit_pause('Déjame ver los horarios'),
    '“Déjame ver los horarios” is an information request and must not pause the conversation.'
);
brain_conversational_ok(
    !hache_sharky_brain_conversational_explicit_pause('Permíteme ver las opciones'),
    '“Permíteme ver las opciones” is an information request and must not opt out follow-ups.'
);
$pauseRaw=$open;
unset($pauseRaw['_brain_2ba'],$pauseRaw['_brain_conversational']);
$pauseRaw['state']['last_user_text']="Deje analizar distancia hogar, trabajo y horarios por favor\nQuedo pendiente";
$pauseRaw['decision']=hache_sharky_orchestrator_decision('conversation','Dime dónde vives y qué horario prefieres.');
$pauseRaw['payload']=hache_sharky_whatsapp_text_payload($contact,'Dime dónde vives y qué horario prefieres.');
$paused=hache_sharky_brain_2ba_apply($open['state'],$pauseRaw,['text'=>'Quedo pendiente'],$config,$contact,true,1788886803);
$pausedBody=(string)($paused['payload']['text']['body']??'');
brain_conversational_ok(
    ($paused['_brain_conversational']['mode']??'')==='paused'
    &&($paused['decision']['kind']??'')==='flow_paused'
    &&str_contains($pausedBody,'Tómate tu tiempo')
    &&!str_contains($pausedBody,'?'),
    'An explicit pause must stop the sales push and wait without asking another question.'
);

$policy=hache_sharky_post72_whatsapp_style_policy();
brain_conversational_ok(
    str_contains($policy,'evita muros de texto')
    &&str_contains($policy,'resume lo esencial')
    &&str_contains($policy,'entry_source es meta_ad')
    &&str_contains($policy,'No repitas como explicación'),
    'Global WhatsApp style policy must encode the early-evidence tuning without narrowing protected operations.'
);

$routerSource=(string)file_get_contents(__DIR__.'/../config/sharky-brain-live-router.php');
foreach([
    'hache_sharky_whatsapp_clean_answer',
    'hache_sharky_whatsapp_enforce_confirmed_context',
    'hache_sharky_whatsapp_enforce_no_reintroduction',
    'hache_sharky_whatsapp_answer_looks_incomplete',
    'hache_sharky_whatsapp_incomplete_recovery',
] as $guardFunction){
    brain_conversational_ok(
        str_contains($routerSource,$guardFunction),
        'Conversational Brain must reuse guard '.$guardFunction.'.'
    );
}
brain_conversational_ok(
    str_contains($routerSource,'$state[\'last_user_text\']'),
    'Router source must prefer durable coalesced last_user_text for the opening turn.'
);

$adminSource=(string)file_get_contents(__DIR__.'/../public/sharky-admin.php');
brain_conversational_ok(
    str_contains($adminSource,'open_conversation_live')
    &&str_contains($adminSource,'routing_mode')
    &&str_contains($adminSource,'Brain conversacional · 100% prospectos'),
    'Production admin must render the conversational live mode instead of mislabeling it as 2B-A.'
);

$protectedState=brain_conversational_state([
    'name'=>'register_intensive',
    'step'=>'student_name',
    'data'=>[],
]);
$protectedRaw=[
    'state'=>$protectedState,
    'decision'=>hache_sharky_orchestrator_decision('registration_name','Dime tu nombre.'),
    'payload'=>hache_sharky_whatsapp_text_payload($contact,'Dime tu nombre.'),
    'action_result'=>null,
];
$GLOBALS['brain_conversational_model_calls']=0;
$protected=hache_sharky_brain_2ba_apply($before,$protectedRaw,['text'=>'Ariel'],$config,$contact,true,1788886804);
brain_conversational_ok(
    ($protected['state']['flow']['name']??'')==='register_intensive'
    &&($GLOBALS['brain_conversational_model_calls']??0)===0,
    'Registration and other protected flows must remain fully deterministic.'
);

$actionRaw=$guidedRaw;
$actionRaw['action_result']=['ok'=>true,'code'=>'PAYMENT_RECORDED'];
$GLOBALS['brain_conversational_model_calls']=0;
$actionProtected=hache_sharky_brain_2ba_apply($before,$actionRaw,$event,$config,$contact,true,1788886805);
brain_conversational_ok(
    ($actionProtected['action_result']['code']??'')==='PAYMENT_RECORDED'
    &&($GLOBALS['brain_conversational_model_calls']??0)===0,
    'Executed business actions must never be rewritten by conversational Brain.'
);

$GLOBALS['brain_conversational_model_calls']=0;
$group=hache_sharky_brain_2ba_apply($before,$guidedRaw,$event,$config,$contact,false,1788886806);
brain_conversational_ok(
    ($group['state']['flow']['name']??'')==='qualify_prospect'
    &&($GLOBALS['brain_conversational_model_calls']??0)===0,
    'Group chats must stay outside the conversational experiment.'
);

$off=[
    HACHE_SHARKY_BRAIN_2BA_ENABLED_KEY=>'1',
    HACHE_SHARKY_BRAIN_2BA_CANARY_KEY=>'10',
    HACHE_SHARKY_BRAIN_CONVERSATIONAL_ENABLED_KEY=>'0',
];
$openRaw=$open;
unset($openRaw['_brain_2ba'],$openRaw['_brain_conversational']);
$restored=hache_sharky_brain_2ba_apply($open['state'],$openRaw,['text'=>'Quiero seguir'],$off,$contact,true,1788886807);
brain_conversational_ok(
    ($restored['state']['flow']['name']??'')==='qualify_prospect'
    &&($restored['state']['flow']['step']??'')==='swim',
    'Switching Brain OFF must preserve the existing deterministic qualification cursor.'
);
brain_conversational_ok(
    !array_key_exists('brain_conversational_experiment',$restored['state']),
    'The experiment marker must be removed after deterministic restoration.'
);
brain_conversational_ok(
    ($restored['_brain_conversational']['fallback']??'')==='deterministic',
    'OFF restoration must be observable as an explicit deterministic fallback.'
);

fwrite(STDOUT,"SHARKY_BRAIN_CONVERSATIONAL_EXPERIMENT_OK\n");
