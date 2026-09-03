<?php

declare(strict_types=1);

require __DIR__.'/../config/sharky-whatsapp-adapter.php';

function guided_ok(bool $condition,string $message): void
{
    if(!$condition){fwrite(STDERR,"SHARKY GUIDED ONBOARDING FAIL: $message\n");exit(1);}
}

// A comparative/question-form venue mention must never become a confirmed preference.
guided_ok(
    hache_sharky_whatsapp_detect_venue_preference('¿Palapas me queda mejor que Monteverde?')===null,
    'A venue comparison/question must not persist Palapas as a confirmed choice.'
);
guided_ok(
    hache_sharky_whatsapp_detect_venue_preference('Palapas me queda mejor')==='PALAPAS',
    'A natural affirmative venue preference must still persist Palapas.'
);

// If the prospect already chose a program before declaring they are new, qualification
// may validate experience but must not silently overwrite that explicit choice.
$prospect=hache_sharky_orchestrator_state(null,1788383000);
$prospect['identity']=array_replace($prospect['identity'],[
    'kind'=>'prospect','verified'=>true,'source'=>'self_declared',
]);
$prospect['commercial_context']['program']='intensive';
[$preferredState,$preferredDecision]=hache_sharky_whatsapp_qualification_start($prospect,1788383000);
guided_ok(
    ($preferredState['flow']['data']['preferred_program']??null)==='intensive',
    'Qualification must carry a previously confirmed intensive choice in flow data.'
);
guided_ok(
    ($preferredState['flow']['step']??null)==='swim',
    'Qualification must still begin by asking swimming experience.'
);
guided_ok(
    str_contains(hache_sharky_orchestrator_normalize((string)($preferredDecision['message']??'')),'sabes nadar'),
    'The first guided question must be about swimming experience, not venue.'
);

// Formal swimmers with no prior program get a real client-goal choice instead of an
// automatic regular assignment. The interactive state machine must recognize it.
$programState=hache_sharky_orchestrator_flow($prospect,'qualify_prospect','program',[],1788383010);
guided_ok(
    hache_sharky_whatsapp_interactive_is_current($programState,['interactive_id'=>'qualify:intensive'])===true,
    'The guided flow must accept the intensive program choice.'
);
guided_ok(
    hache_sharky_whatsapp_interactive_is_current($programState,['interactive_id'=>'qualify:regular'])===true,
    'The guided flow must accept the regular program choice.'
);
guided_ok(
    hache_sharky_whatsapp_interactive_is_current($programState,['interactive_id'=>'sede:palapas'])===false,
    'A venue button from another step must remain stale while program is pending.'
);

$adapterSource=file_get_contents(__DIR__.'/../config/sharky-whatsapp-adapter.php')?:'';
guided_ok(
    str_contains($adapterSource,"qualify:intensive")&&str_contains($adapterSource,"qualify:regular"),
    'The guided flow must include both client program choices for experienced swimmers.'
);
guided_ok(
    str_contains($adapterSource,'¡Hola! Soy Sharky 🦈, el asistente IA de Hache Natación.')
    && str_contains($adapterSource,"assistant_presentation_queued"),
    'The deterministic identity prompt must preserve a first presentation while suppressing later re-introductions.'
);

$v2Source=file_get_contents(__DIR__.'/../public/api/sharky-v2.php')?:'';
guided_ok(
    str_contains($v2Source,"mb_substr(\$content,0,6000)"),
    'Trusted WhatsApp system instructions must not be truncated at the former 2200-character cap.'
);
guided_ok(
    str_contains($v2Source,"if (\$channel === 'whatsapp')")
    && str_contains($v2Source,"(\$turn['role'] ?? '') !== 'system'"),
    'System turns must only be incorporated after the endpoint resolves a trusted WhatsApp loopback channel.'
);

echo "SHARKY_GUIDED_ONBOARDING_OK\n";
