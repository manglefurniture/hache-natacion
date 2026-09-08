<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-whatsapp-adapter.php';
require_once __DIR__.'/../config/sharky-brain-live-router.php';

function brain2ba_ok(bool $condition,string $message): void
{
    if(!$condition){fwrite(STDERR,"SHARKY BRAIN 2BA FAIL: {$message}\n");exit(1);}
}

function brain2ba_state(string $kind='prospect',?string $program=null,?string $sede=null,?array $flow=null): array
{
    $state=hache_sharky_orchestrator_state(null,1788886800);
    $state['identity']=array_replace($state['identity'],[
        'kind'=>$kind,
        'verified'=>$kind!=='unknown',
        'source'=>$kind==='prospect'?'whatsapp_unmatched':null,
    ]);
    $state['commercial_context']['program']=$program;
    $state['commercial_context']['sede_clave']=$sede;
    $state['flow']=$flow;
    return $state;
}

$config=[HACHE_SHARKY_BRAIN_2BA_ENABLED_KEY=>'1',HACHE_SHARKY_BRAIN_2BA_CANARY_KEY=>'100'];
$off=[HACHE_SHARKY_BRAIN_2BA_ENABLED_KEY=>'0',HACHE_SHARKY_BRAIN_2BA_CANARY_KEY=>'100'];
$contact='529980001234';

brain2ba_ok(hache_sharky_brain_2ba_live_actions()===['start_guided_qualification','show_commercial_menu'],'2B-A whitelist must contain only guided deterministic routes.');
brain2ba_ok(!in_array('answer_user',hache_sharky_brain_2ba_live_actions(),true),'answer_user must remain shadow-only.');
brain2ba_ok(!in_array('continue_discovery',hache_sharky_brain_2ba_live_actions(),true),'continue_discovery must remain shadow-only.');
brain2ba_ok(hache_sharky_brain_2ba_config_defaults()[HACHE_SHARKY_BRAIN_2BA_CANARY_KEY]==='10','Initial live cohort must remain a small 10% canary.');
brain2ba_ok(hache_sharky_brain_2ba_config_value_valid(HACHE_SHARKY_BRAIN_2BA_ENABLED_KEY,'0')&&hache_sharky_brain_2ba_config_value_valid(HACHE_SHARKY_BRAIN_2BA_ENABLED_KEY,'1'),'Kill switch accepts only binary values.');
brain2ba_ok(!hache_sharky_brain_2ba_config_value_valid(HACHE_SHARKY_BRAIN_2BA_CANARY_KEY,'101'),'Canary percentage must reject values above 100.');
brain2ba_ok(hache_sharky_brain_2ba_contact_in_canary($contact,100),'100% canary must include a prospect deterministically.');
brain2ba_ok(!hache_sharky_brain_2ba_contact_in_canary($contact,0),'0% canary must exclude every prospect.');

$before=brain2ba_state('unknown');
$after=brain2ba_state('prospect');
$after['assistant_presentation_queued']=true;
$raw=[
    'state'=>$after,
    'decision'=>hache_sharky_orchestrator_decision('conversation','Respuesta abierta que 2B-A debe sustituir por guía.'),
    'payload'=>hache_sharky_whatsapp_text_payload($contact,'Respuesta abierta que 2B-A debe sustituir por guía.'),
    'action_result'=>null,
];
$event=['id'=>'2ba:new','from'=>$contact,'type'=>'text','text'=>'Hola'];
$plan=hache_sharky_brain_2ba_plan($before,$raw,$event,$config,$contact,true);
brain2ba_ok(($plan['status']??'')==='apply'&&($plan['action']??'')==='start_guided_qualification','Unknown → unmatched prospect divergence may only be upgraded into guided qualification.');
$guided=hache_sharky_brain_2ba_apply($before,$raw,$event,$config,$contact,true,1788886801);
brain2ba_ok(($guided['state']['flow']['name']??'')==='qualify_prospect','Applied Brain route must open the existing qualification flow.');
brain2ba_ok(($guided['state']['flow']['step']??'')==='swim','Qualification must begin at the established swim step.');
brain2ba_ok(($guided['payload']['type']??'')==='interactive','Brain must reuse native deterministic controls instead of authoring free text.');
brain2ba_ok(($guided['state']['assistant_presentation_queued']??false)===true,'Brain routing must preserve the durable one-time presentation marker.');

$disabled=hache_sharky_brain_2ba_apply($before,$raw,$event,$off,$contact,true,1788886801);
brain2ba_ok(($disabled['decision']['kind']??'')==='conversation'&&!is_array($disabled['state']['flow']??null),'Kill switch off must leave the live result byte-for-route unchanged.');
$groupPlan=hache_sharky_brain_2ba_plan($before,$raw,$event,$config,$contact,false);
brain2ba_ok(($groupPlan['status']??'')==='excluded_channel','Groups must never enter 2B-A.');

$protected=brain2ba_state('prospect','intensive','MONTEVERDE',['name'=>'qualify_prospect','step'=>'venue','data'=>[]]);
$protectedRaw=[
    'state'=>$protected,
    'decision'=>hache_sharky_orchestrator_decision('conversation','No debe ganar sobre un flow.'),
    'payload'=>hache_sharky_whatsapp_text_payload($contact,'No debe ganar sobre un flow.'),
    'action_result'=>null,
];
$protectedPlan=hache_sharky_brain_2ba_plan($protected,$protectedRaw,['text'=>'hola'],$config,$contact,true);
brain2ba_ok(($protectedPlan['status']??'')==='blocked_protected','An active flow must be an absolute Brain live barrier.');

$actionProtected=$raw;
$actionProtected['action_result']=['ok'=>true,'code'=>'REGISTERED'];
$actionPlan=hache_sharky_brain_2ba_plan($before,$actionProtected,$event,$config,$contact,true);
brain2ba_ok(($actionPlan['status']??'')==='blocked_protected','Any executed business action must be an absolute Brain live barrier.');

$openBefore=brain2ba_state('prospect');
$openRaw=[
    'state'=>$openBefore,
    'decision'=>hache_sharky_orchestrator_decision('conversation_identity_prompt','Antes de seguir...'),
    'payload'=>hache_sharky_whatsapp_text_payload($contact,'Antes de seguir...'),
    'action_result'=>null,
];
$openPlan=hache_sharky_brain_2ba_plan($openBefore,$openRaw,['text'=>'cuéntame'],$config,$contact,true);
brain2ba_ok(($openPlan['status']??'')==='blocked_open_conversation','A Brain proposal to answer freely must remain shadow-only even inside the canary.');

$partial=brain2ba_state('prospect','intensive',null);
$ready=brain2ba_state('prospect','intensive','PALAPAS');
$ready['commercial_context']['swim_level']='beginner';
$ready['assistant_presentation_queued']=true;
$menuRaw=[
    'state'=>$ready,
    'decision'=>hache_sharky_orchestrator_decision('conversation','Texto abierto de progreso comercial.'),
    'payload'=>hache_sharky_whatsapp_text_payload($contact,'Texto abierto de progreso comercial.'),
    'action_result'=>null,
];
$menuPlan=hache_sharky_brain_2ba_plan($partial,$menuRaw,['text'=>'Palapas'],$config,$contact,true);
brain2ba_ok(($menuPlan['action']??'')==='show_commercial_menu','Completing known program + venue may be upgraded only to the deterministic commercial menu.');
$menu=hache_sharky_brain_2ba_apply($partial,$menuRaw,['text'=>'Palapas'],$config,$contact,true,1788886802);
brain2ba_ok(($menu['decision']['kind']??'')==='commercial_next_action','2B-A must reuse the established commercial next-action decision.');
brain2ba_ok(($menu['state']['commercial_context']['program']??'')==='intensive'&&($menu['state']['commercial_context']['sede_clave']??'')==='PALAPAS','Brain menu routing must preserve confirmed program and venue.');
brain2ba_ok(($menu['state']['commercial_context']['swim_level']??'')==='beginner','Brain menu routing must not erase an independent confirmed slot.');
brain2ba_ok(($menu['state']['assistant_presentation_queued']??false)===true,'Brain menu routing must never reopen Sharky presentation.');

$routerSource=(string)file_get_contents(__DIR__.'/../config/sharky-brain-live-router.php');
$workerSource=(string)file_get_contents(__DIR__.'/../config/sharky-lab-worker.php');
brain2ba_ok(str_contains($routerSource,"return ['start_guided_qualification','show_commercial_menu']"),'Whitelist must stay explicit in source.');
brain2ba_ok(str_contains($routerSource,"['answer_user','continue_discovery']"),'Open conversational actions must have an explicit deny gate.');
brain2ba_ok(str_contains($workerSource,'hache_sharky_brain_shadow_observe')&&strpos($workerSource,'hache_sharky_brain_shadow_observe')<strpos($workerSource,'hache_sharky_brain_2ba_apply'),'Shadow observation must happen before live Brain application.');
brain2ba_ok(str_contains($workerSource,"\$deferredState['state']=\$result['state']"),'Applied Brain state must share the durable delivery boundary with its payload.');

fwrite(STDOUT,"SHARKY_BRAIN_PHASE2BA_OK\n");