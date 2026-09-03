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

// A venue confirmed earlier in the conversation must not be requested again once the
// qualification has enough information to move forward.
$knownRegular=$prospect;
$knownRegular['commercial_context']['program']='regular';
$knownRegular['commercial_context']['sede_clave']='PALAPAS';
[$knownRegularState,$knownRegularDecision]=hache_sharky_whatsapp_qualification_sede_step($knownRegular,[],1788383020,'Perfecto, seguimos por clases regulares.');
guided_ok(
    ($knownRegularState['flow']['step']??null)==='daypart',
    'A known venue with regular classes must skip the venue question and advance to daypart.'
);
guided_ok(
    !str_contains(hache_sharky_orchestrator_normalize((string)($knownRegularDecision['message']??'')),'que sede'),
    'A known venue must never be asked again during regular qualification.'
);
guided_ok(
    str_contains(hache_sharky_orchestrator_normalize((string)($knownRegularDecision['message']??'')),'matutino'),
    'A known regular venue should advance directly to the morning/evening preference.'
);

$knownIntensive=$prospect;
$knownIntensive['commercial_context']['program']='intensive';
$knownIntensive['commercial_context']['sede_clave']='MONTEVERDE';
[$knownIntensiveState,$knownIntensiveDecision]=hache_sharky_whatsapp_qualification_sede_step($knownIntensive,[],1788383030,'Perfecto, podemos orientarte por el intensivo.');
guided_ok(
    ($knownIntensiveState['flow']??null)===null,
    'A known venue with intensive context should complete guided qualification without another venue step.'
);
guided_ok(
    ($knownIntensiveDecision['kind']??null)==='commercial_ready',
    'Known intensive program and venue should leave the prospect commercially ready.'
);

// Codex review P1: a guided prospect must always be able to cancel or request a human.
$escapeState=hache_sharky_orchestrator_flow($prospect,'qualify_prospect','swim',[],1788383040);
$cancelEscape=hache_sharky_whatsapp_qualification_escape($escapeState,['text'=>'cancelar','interactive_id'=>'']);
guided_ok(is_array($cancelEscape),'Text cancellation must be recognized inside qualification.');
guided_ok(($cancelEscape[0]['flow']??null)===null,'Cancellation must clear the qualification flow.');
guided_ok(($cancelEscape[1]['kind']??null)==='flow_cancelled','Cancellation must return the common cancelled decision.');
$buttonCancel=hache_sharky_whatsapp_qualification_escape($escapeState,['text'=>'Cancelar','interactive_id'=>'flow:cancel']);
guided_ok(is_array($buttonCancel)&&($buttonCancel[0]['flow']??null)===null,'The cancel button must also escape qualification.');
$humanEscape=hache_sharky_whatsapp_qualification_escape($escapeState,['text'=>'Quiero hablar con una persona','interactive_id'=>'']);
guided_ok(is_array($humanEscape)&&($humanEscape[0]['flow']??null)===null,'A human request must not get trapped in qualification.');
guided_ok(($humanEscape[1]['action']['type']??null)==='human_takeover','A human escape must preserve the controlled takeover action.');

// Codex review P2: the 30-minute flow TTL applies before adapter-direct qualification paths.
$oldFlow=hache_sharky_orchestrator_flow($prospect,'qualify_prospect','swim',[],1000);
$expired=hache_sharky_orchestrator_expire_flow($oldFlow,1000+HACHE_SHARKY_FLOW_TTL+1);
guided_ok(($expired['flow']??null)===null,'A qualification older than the flow TTL must expire.');
guided_ok(($expired['mode']??null)==='conversation','Expired qualification must return to conversation mode.');

// Codex review P2: a volunteered age below the minimum closes commercial guidance immediately.
guided_ok(hache_sharky_whatsapp_declared_age('Tengo 8 años')===8,'A volunteered age must be parsed deterministically.');
guided_ok(hache_sharky_whatsapp_declared_age('Tengo 43 años')===43,'Adult volunteered ages must remain parseable.');
$underage=$escapeState;$underage['commercial_context']['age']=8;
$underageResult=hache_sharky_whatsapp_underage_rejection($underage,12);
guided_ok(is_array($underageResult),'A prospect below the minimum age must be rejected before guidance continues.');
guided_ok(($underageResult[0]['flow']??null)===null,'Underage rejection must clear an active qualification.');
guided_ok(($underageResult[1]['kind']??null)==='prospect_age_rejected','Underage rejection must use an explicit deterministic decision.');
$adult=$escapeState;$adult['commercial_context']['age']=43;
guided_ok(hache_sharky_whatsapp_underage_rejection($adult,12)===null,'An adult volunteered age must not block guidance.');

// Codex review P2: changing venue in an advanced registration cannot leave the old
// course/schedule attached. Restart from offer with fresh consent and only the new venue.
$advanced=$prospect;
$advanced['commercial_context']['sede_clave']='PALAPAS';
$advanced=hache_sharky_orchestrator_flow($advanced,'register_intensive','name',[
    'sede_clave'=>'PALAPAS','course_id'=>'course-old','fecha_inicio'=>'2026-09-07','schedule_id'=>'schedule-old',
],1788383050);
$advancedNatural=hache_sharky_whatsapp_apply_natural_venue_preference($advanced,'Monteverde me queda mejor');
guided_ok(($advancedNatural['commercial_context']['sede_clave']??null)==='PALAPAS','Advanced registration must not mutate venue through conversational memory alone.');
$venueCorrection=hache_sharky_whatsapp_registration_venue_correction($advanced,['text'=>'Monteverde me queda mejor','interactive_id'=>''],1788383060);
guided_ok(is_array($venueCorrection),'An advanced registration must explicitly intercept a venue correction.');
guided_ok(($venueCorrection[0]['commercial_context']['sede_clave']??null)==='MONTEVERDE','The corrected venue must become the single commercial venue.');
guided_ok(($venueCorrection[0]['flow']['step']??null)==='offer','A venue change must restart the controlled registration at consent.');
guided_ok(($venueCorrection[0]['flow']['data']??null)===['sede_clave'=>'MONTEVERDE'],'Old course, schedule and personal data must be discarded after venue change.');
guided_ok(($venueCorrection[1]['kind']??null)==='registration_offer','Venue restart must return the explicit registration offer.');
guided_ok(($venueCorrection[1]['ui']['buttons'][0]['id']??null)==='flow:yes','Venue restart must require fresh explicit consent.');
$sameVenue=hache_sharky_whatsapp_registration_venue_correction($advanced,['text'=>'Palapas me queda mejor','interactive_id'=>''],1788383060);
guided_ok(is_array($sameVenue)&&($sameVenue[0]['flow']['step']??null)==='name','Repeating the same venue must not destroy the current registration step.');

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
$expirePos=strpos($adapterSource,'$state=hache_sharky_orchestrator_expire_flow($state,(int)$context[\'now\']);');
$staleGuardPos=strpos($adapterSource,'if(!hache_sharky_whatsapp_interactive_is_current($state,$event))');
$venueCorrectionPos=strpos($adapterSource,'$venueCorrection=hache_sharky_whatsapp_registration_venue_correction');
$naturalVenuePos=$staleGuardPos===false?false:strpos($adapterSource,'$state=hache_sharky_whatsapp_apply_natural_venue_preference',$staleGuardPos);
guided_ok(
    $expirePos!==false&&$staleGuardPos!==false&&$expirePos<$staleGuardPos,
    'Flow TTL must be applied before validating any adapter-direct interactive reply.'
);
guided_ok(
    $staleGuardPos!==false&&$naturalVenuePos!==false&&$staleGuardPos<$naturalVenuePos,
    'Natural venue persistence must happen only after stale interactive replies are rejected.'
);
guided_ok(
    $venueCorrectionPos!==false&&$naturalVenuePos!==false&&$venueCorrectionPos<$naturalVenuePos,
    'Advanced registration venue correction must run before conversational venue persistence.'
);
guided_ok(
    str_contains($adapterSource,"if(trim((string)(\$event['interactive_id']??''))==='')\$state=hache_sharky_whatsapp_apply_natural_venue_preference"),
    'Interactive replies must never mutate natural venue memory before their own flow validates them.'
);
guided_ok(
    substr_count($adapterSource,'hache_sharky_whatsapp_underage_rejection(')>=4,
    'Underage protection must cover helper definition, active prospect guidance and the new-prospect transition.'
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
