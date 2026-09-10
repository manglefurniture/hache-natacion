<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-whatsapp-adapter.php';
require_once __DIR__.'/../config/sharky-brain-live-router.php';
require_once __DIR__.'/../config/sharky-post-pr72.php';

function numeric_context_ok(bool $ok,string $message): void
{
    if(!$ok){fwrite(STDERR,"SHARKY BRAIN NUMERIC CONTEXT FAIL: {$message}\n");exit(1);}
}

function hache_sharky_lab_answer(string $text,string $instruction,array $state,array $context): string
{
    $GLOBALS['numeric_context_model_calls']=(int)($GLOBALS['numeric_context_model_calls']??0)+1;
    $GLOBALS['numeric_context_instruction']=$instruction;
    $GLOBALS['numeric_context_state']=$state;
    $GLOBALS['numeric_context_previous']=$context['previous_user_text']??null;
    return 'Entiendo. Tomo ese número como parte de tu referencia y sigo con la ubicación.';
}

function numeric_context_state(): array
{
    $state=hache_sharky_orchestrator_state(null,1789046400);
    $state['identity']=array_replace($state['identity'],[
        'kind'=>'prospect','verified'=>false,'source'=>'whatsapp_unmatched',
    ]);
    $state['flow']=null;
    $state['brain_conversational_experiment']=true;
    return $state;
}

$contact='529980009595';
$config=[
    HACHE_SHARKY_BRAIN_2BA_ENABLED_KEY=>'1',
    HACHE_SHARKY_BRAIN_2BA_CANARY_KEY=>'10',
    HACHE_SHARKY_BRAIN_CONVERSATIONAL_ENABLED_KEY=>'1',
];

$before=numeric_context_state();
$before['last_user_text']='Estoy por Nichupté y Chac Mool';
$rawState=$before;
$rawState['last_user_text']='95';
$rawState['commercial_context']['age']=95;
$raw=[
    'state'=>$rawState,
    'decision'=>hache_sharky_orchestrator_decision('prospect_age_rejected','Para tu edad (95 años) podemos orientarte.'),
    'payload'=>hache_sharky_whatsapp_text_payload($contact,'Para tu edad (95 años) podemos orientarte.'),
    'action_result'=>null,
];

numeric_context_ok(
    hache_sharky_brain_conversational_ambiguous_numeric_age_drift($before,$rawState,'95'),
    'A newly captured age from a bare number must be detected as ambiguous context drift.'
);
$GLOBALS['numeric_context_model_calls']=0;
$fixed=hache_sharky_brain_2ba_apply($before,$raw,['text'=>'95'],$config,$contact,true,1789046401);
$body=(string)($fixed['payload']['text']['body']??'');
numeric_context_ok(
    ($fixed['state']['commercial_context']['age']??null)===null,
    'Bare 95 must not survive as age when no age existed before the turn.'
);
numeric_context_ok(
    ($GLOBALS['numeric_context_model_calls']??0)===1,
    'Ambiguous numeric age drift must force one conversational re-answer instead of passing through the contaminated reply.'
);
numeric_context_ok(
    !str_contains($body,'95 años')&&!str_contains($body,'tu edad'),
    'The repaired reply must not expose the contaminated age interpretation.'
);
numeric_context_ok(
    ($GLOBALS['numeric_context_previous']??'')==='Estoy por Nichupté y Chac Mool',
    'The model must receive the previous user turn so a bare number can be resolved against active conversational context.'
);
numeric_context_ok(
    str_contains((string)($GLOBALS['numeric_context_instruction']??''),'número aislado'),
    'Brain policy must explicitly treat a bare number as ambiguous instead of guessing age.'
);

$explicitBefore=numeric_context_state();
$explicitState=$explicitBefore;
$explicitState['last_user_text']='Tengo 62 años';
$explicitState['commercial_context']['age']=62;
numeric_context_ok(
    !hache_sharky_brain_conversational_ambiguous_numeric_age_drift($explicitBefore,$explicitState,'Tengo 62 años'),
    'An explicit volunteered age must remain valid.'
);

$existingAge=numeric_context_state();
$existingAge['commercial_context']['age']=62;
$changed=$existingAge;$changed['last_user_text']='95';$changed['commercial_context']['age']=62;
numeric_context_ok(
    !hache_sharky_brain_conversational_ambiguous_numeric_age_drift($existingAge,$changed,'95'),
    'A bare number must not erase an already confirmed age.'
);

fwrite(STDOUT,"SHARKY_BRAIN_NUMERIC_CONTEXT_OK\n");
