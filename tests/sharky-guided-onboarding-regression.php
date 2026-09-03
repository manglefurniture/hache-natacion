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

// A guided prospect must always be able to cancel or request a human.
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

// The 30-minute flow TTL applies before adapter-direct qualification paths.
$oldFlow=hache_sharky_orchestrator_flow($prospect,'qualify_prospect','swim',[],1000);
$expired=hache_sharky_orchestrator_expire_flow($oldFlow,1000+HACHE_SHARKY_FLOW_TTL+1);
guided_ok(($expired['flow']??null)===null,'A qualification older than the flow TTL must expire.');
guided_ok(($expired['mode']??null)==='conversation','Expired qualification must return to conversation mode.');

// Explicit ages are remembered only when the person actually states them, including
// natural compound messages and common third-person descriptions of the participant.
guided_ok(hache_sharky_whatsapp_declared_age('Tengo 8 años')===8,'A volunteered age must be parsed deterministically.');
guided_ok(hache_sharky_whatsapp_declared_age('Tengo 43 años')===43,'Adult volunteered ages must remain parseable.');
guided_ok(hache_sharky_whatsapp_declared_age('Soy nuevo, tengo 8 años')===8,'A comma-separated new-prospect claim must preserve the volunteered age.');
guided_ok(hache_sharky_whatsapp_declared_age('Soy nuevo y tengo 8 años')===8,'A conjunction new-prospect claim must preserve the volunteered age.');
guided_ok(hache_sharky_whatsapp_declared_age('Soy nuevo y tengo 1 año')===1,'Singular año must be parsed in a compound message.');
guided_ok(hache_sharky_whatsapp_declared_age('Soy nueva, mi hija tiene 8 años')===8,'A daughter age stated in third person must be captured.');
guided_ok(hache_sharky_whatsapp_declared_age('Es para una niña de 8 años')===8,'A participant described as a child of N years must be captured.');
guided_ok(hache_sharky_whatsapp_declared_age('No tengo 8 años')===null,'A negated age clause must not be stored as the user age.');
guided_ok(hache_sharky_whatsapp_declared_age('Mi hija no tiene 8 años')===null,'A negated third-person age must not be captured.');
guided_ok(hache_sharky_whatsapp_declared_age('No tengo 8 años, tengo 43 años')===43,'A later affirmative age must win after an earlier negated clause.');

$underage=$escapeState;$underage['commercial_context']['age']=8;
$underageResult=hache_sharky_whatsapp_underage_rejection($underage,12);
guided_ok(is_array($underageResult),'A prospect below the minimum age must be rejected before guidance continues.');
guided_ok(($underageResult[0]['flow']??null)===null,'Underage rejection must clear an active qualification.');
guided_ok(($underageResult[1]['kind']??null)==='prospect_age_rejected','Underage rejection must use an explicit deterministic decision.');
$adult=$escapeState;$adult['commercial_context']['age']=43;
guided_ok(hache_sharky_whatsapp_underage_rejection($adult,12)===null,'An adult volunteered age must not block guidance.');

// Age capture happens before identity resolution. The gate itself is identity-independent
// when the current turn is explicitly commercial, so a first-turn registration cannot bypass it.
$unknownAge=hache_sharky_orchestrator_state(null,1788383045);
guided_ok(($unknownAge['identity']['kind']??null)==='unknown','The integration fixture must begin with unknown identity.');
$unknownAge=hache_sharky_whatsapp_capture_declared_age($unknownAge,'Soy nuevo, tengo 8 años');
guided_ok(($unknownAge['identity']['kind']??null)==='unknown','Age capture must not invent or resolve identity.');
guided_ok(($unknownAge['commercial_context']['age']??null)===8,'Compound age must be persisted while identity is still unknown.');
$firstTurnGate=hache_sharky_whatsapp_underage_gate($unknownAge,['text'=>'Soy nuevo, tengo 8 años','interactive_id'=>''],12);
guided_ok(is_array($firstTurnGate),'A new-prospect commercial transition must be blocked even while identity is still unknown.');
guided_ok(($firstTurnGate[1]['kind']??null)==='prospect_age_rejected','The first compound underage turn must resolve to the deterministic age rejection.');
$unknownRegistration=hache_sharky_orchestrator_state(null,1788383045);
$unknownRegistration=hache_sharky_whatsapp_capture_declared_age($unknownRegistration,'Soy nuevo, tengo 8 años y quiero inscribirme al intensivo');
$unknownRegistrationGate=hache_sharky_whatsapp_underage_gate($unknownRegistration,['text'=>'Soy nuevo, tengo 8 años y quiero inscribirme al intensivo','interactive_id'=>''],12);
guided_ok(is_array($unknownRegistrationGate),'Unknown identity plus explicit intensive registration must not bypass the minimum-age gate.');
guided_ok(($unknownRegistrationGate[0]['flow']??null)===null,'Rejected first-turn registration must not open a registration flow.');
$thirdPerson=hache_sharky_orchestrator_state(null,1788383045);
$thirdPerson=hache_sharky_whatsapp_capture_declared_age($thirdPerson,'Soy nueva, mi hija tiene 8 años');
guided_ok(($thirdPerson['commercial_context']['age']??null)===8,'Third-person participant age must persist before identity resolution.');
guided_ok(is_array(hache_sharky_whatsapp_underage_gate($thirdPerson,['text'=>'Soy nueva, mi hija tiene 8 años','interactive_id'=>''],12)),'Third-person underage new-prospect claim must close commercial onboarding.');

// The minimum-age rule is a COMMERCIAL gate, not a conversation jail. Operational
// questions and safety/service policies remain answerable after a rejected age.
$underageProspect=$prospect;
$underageProspect['commercial_context']['age']=8;
$ordinaryUnderageGate=hache_sharky_whatsapp_underage_gate($underageProspect,['text'=>'Quiero información del intensivo','interactive_id'=>''],12);
guided_ok(is_array($ordinaryUnderageGate)&&($ordinaryUnderageGate[1]['kind']??null)==='prospect_age_rejected','Ordinary commercial guidance must remain blocked below minimum age.');
guided_ok(is_array(hache_sharky_whatsapp_underage_gate($underageProspect,['text'=>'¿Cuánto cuesta el curso?','interactive_id'=>''],12)),'Price/course questions remain commercial and must be blocked below minimum age.');
guided_ok(hache_sharky_whatsapp_underage_gate($underageProspect,['text'=>'¿Se cancelan las clases por lluvia?','interactive_id'=>''],12)===null,'Weather policy must remain answerable after an underage rejection.');
guided_ok(hache_sharky_whatsapp_underage_gate($underageProspect,['text'=>'¿Tienen nado libre?','interactive_id'=>''],12)===null,'Nado libre policy must remain answerable after an underage rejection.');
guided_ok(hache_sharky_whatsapp_underage_gate($underageProspect,['text'=>'No puedo ir mañana','interactive_id'=>''],12)===null,'Absence intent must not be blocked by the commercial age gate.');
guided_ok(hache_sharky_whatsapp_underage_gate($underageProspect,['text'=>'Quiero hablar con una persona','interactive_id'=>''],12)===null,'Text human handoff must bypass the commercial age gate.');
guided_ok(hache_sharky_whatsapp_underage_gate($underageProspect,['text'=>'Hablar con equipo','interactive_id'=>'action:human'],12)===null,'Interactive human handoff must bypass the commercial age gate.');
guided_ok(hache_sharky_whatsapp_underage_gate($underageProspect,['text'=>'cancelar','interactive_id'=>''],12)===null,'Cancellation must bypass the commercial age gate.');
$underageFlow=hache_sharky_orchestrator_flow($underageProspect,'qualify_prospect','swim',[],1788383046);
guided_ok(hache_sharky_whatsapp_underage_gate($underageFlow,['text'=>'¿Se cancelan las clases por lluvia?','interactive_id'=>''],12)===null,'Operational weather questions inside qualification must bypass the gate and preserve the flow.');
$underageAdvance=hache_sharky_whatsapp_underage_gate($underageFlow,['text'=>'Desde cero','interactive_id'=>''],12);
guided_ok(is_array($underageAdvance)&&($underageAdvance[1]['kind']??null)==='prospect_age_rejected','A plain reply that would advance active qualification must still be blocked.');
$underageHumanGate=hache_sharky_whatsapp_underage_gate($underageFlow,['text'=>'Quiero hablar con una persona','interactive_id'=>''],12);
guided_ok($underageHumanGate===null,'An underage active qualification must let the human request reach the controlled escape.');
$underageHumanEscape=hache_sharky_whatsapp_qualification_escape($underageFlow,['text'=>'Quiero hablar con una persona','interactive_id'=>'']);
guided_ok(is_array($underageHumanEscape)&&($underageHumanEscape[1]['action']['type']??null)==='human_takeover','After bypassing the age gate, active qualification must still produce the controlled human takeover.');

// Changing venue in an advanced registration cannot leave the old course/schedule attached.
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
$ageCapturePos=strpos($adapterSource,'$state=hache_sharky_whatsapp_capture_declared_age');
$ageGatePos=strpos($adapterSource,'$ageRejection=hache_sharky_whatsapp_underage_gate');
$orchestratePos=strpos($adapterSource,'$stateBeforeOrchestrate=$state;');
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
    $ageCapturePos!==false&&$ageGatePos!==false&&$orchestratePos!==false&&$ageCapturePos<$ageGatePos&&$ageGatePos<$orchestratePos,
    'Identity-independent age capture and the commercial age gate must both run before the orchestrator can start a commercial flow.'
);
guided_ok(
    str_contains($adapterSource,'function hache_sharky_whatsapp_underage_commercial_event')
    && str_contains($adapterSource,"['human','cancel','absence','student_claim','no']"),
    'The minimum-age gate must be explicitly scoped to commercial progress and exempt non-commercial control intents.'
);
guided_ok(
    str_contains($adapterSource,'hache_sharky_whatsapp_weather_cancellation_request($text)||hache_sharky_whatsapp_nado_libre_request($text)'),
    'Operational weather and nado-libre policies must bypass the commercial age gate.'
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
