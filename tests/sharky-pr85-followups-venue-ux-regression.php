<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-whatsapp-adapter.php';

function pr85_followup_ok(bool $condition,string $message): void
{
    if(!$condition){fwrite(STDERR,"SHARKY PR85 FOLLOWUP FAIL: {$message}\n");exit(1);}
}

// P1: concise but substantive answers must survive the incomplete-answer guard.
pr85_followup_ok(!hache_sharky_whatsapp_answer_looks_incomplete('Claro. Cuesta 1200 pesos.'),'A short price answer must not be discarded.');
pr85_followup_ok(!hache_sharky_whatsapp_answer_looks_incomplete('Genial, tenemos cupo'),'A short substantive availability answer must not be discarded.');
pr85_followup_ok(hache_sharky_whatsapp_answer_looks_incomplete('¡Claro! 😊'),'A standalone acknowledgement must still be considered incomplete.');

// P1: explicit corrections must beat an earlier/negated beginner phrase.
pr85_followup_ok(hache_sharky_whatsapp_detect_swim_level('No estoy desde cero, ya sé nadar')==='swims','Negated beginner status plus explicit swimmer correction must resolve to swims.');
pr85_followup_ok(hache_sharky_whatsapp_detect_swim_level('No sé nadar')==='beginner','A real no-swim statement must remain beginner.');
pr85_followup_ok(hache_sharky_whatsapp_swim_level_from_input('', 'qualify:beginner')==='beginner','Beginner button must resolve to beginner level.');
pr85_followup_ok(hache_sharky_whatsapp_swim_level_from_input('', 'qualify:swims')==='swims','Swimmer button must resolve to swims level.');

$prospect=hache_sharky_orchestrator_state(null,1788490000);
$prospect['identity']=array_replace($prospect['identity'],['kind'=>'prospect','verified'=>true,'source'=>'self_declared']);
$beginnerState=hache_sharky_whatsapp_apply_swim_level_choice($prospect,'beginner');
pr85_followup_ok(($beginnerState['commercial_context']['swim_level']??null)==='beginner','Controlled beginner choice must persist swim_level.');
pr85_followup_ok(($beginnerState['commercial_context']['program']??null)==='intensive','Beginner choice may recommend intensive when no program is selected.');
$swimsState=hache_sharky_whatsapp_apply_swim_level_choice($prospect,'swims');
pr85_followup_ok(($swimsState['commercial_context']['swim_level']??null)==='swims','Controlled swimmer choice must persist swim_level.');

// P2: asking about the other venue must not mutate the durable venue.
$venueState=$prospect;
$venueState['commercial_context']['sede_clave']='MONTEVERDE';
pr85_followup_ok(hache_sharky_whatsapp_detect_relative_venue_preference($venueState,'Quiero saber los horarios de la otra sede')===null,'Informational other-venue request must not switch the durable venue.');
pr85_followup_ok(hache_sharky_whatsapp_detect_relative_venue_preference($venueState,'¿Me dices dónde está la otra sede?')===null,'Question-form other-venue request must not switch the durable venue.');
pr85_followup_ok(hache_sharky_whatsapp_detect_relative_venue_preference($venueState,'Estoy desde 0, pero quiero la otra sede que tienen.')==='PALAPAS','Explicit other-venue selection must still switch the venue.');

// Venue UX from production screenshots: explain before insisting on a choice.
pr85_followup_ok(hache_sharky_whatsapp_venue_help_request('No las conozco'),'Unknown venues must be recognized as a help request.');
pr85_followup_ok(hache_sharky_whatsapp_venue_help_request('Pero no sé cuáles son o dónde están.'),'Where-are-the-venues phrasing must be recognized as a help request.');
$venueHelp=hache_sharky_whatsapp_venue_help_message(null);
$venueHelpNorm=hache_sharky_orchestrator_normalize($venueHelp);
pr85_followup_ok(str_contains($venueHelpNorm,'colegio monteverde'),'Venue help must name Colegio Monteverde.');
pr85_followup_ok(str_contains($venueHelpNorm,'palapas protudec'),'Venue help must name Palapas Protudec.');
pr85_followup_ok(substr_count($venueHelp,'https://')>=2,'Venue help should provide both map links when using defaults.');

// Customer-visible Sharky output must consistently label the venue as Colegio Monteverde,
// without changing the internal MONTEVERDE key.
$renderDecision=hache_sharky_orchestrator_decision('venue_label_test','Tenemos sede en Monteverde.',['type'=>'buttons','buttons'=>[
    hache_sharky_orchestrator_button('sede:monteverde','Monteverde'),
    hache_sharky_orchestrator_button('sede:palapas','Palapas Protudec'),
]]);
$rendered=hache_sharky_whatsapp_render('529900000001',$renderDecision);
pr85_followup_ok(str_contains((string)($rendered['interactive']['body']['text']??''),'Colegio Monteverde'),'Rendered body must use Colegio Monteverde.');
pr85_followup_ok((string)($rendered['interactive']['action']['buttons'][0]['reply']['title']??'')==='Colegio Monteverde','Rendered Monteverde button must use Colegio Monteverde.');

// P2: overlapping affirmations must reach the intensive offer before generic reengagement.
pr85_followup_ok(hache_sharky_whatsapp_offer_affirmation('Perfecto'),'Perfecto must remain a valid offer affirmation.');
pr85_followup_ok(hache_sharky_whatsapp_low_information_reengagement('Perfecto'),'Perfecto intentionally overlaps with generic reengagement.');
$source=file_get_contents(__DIR__.'/../config/sharky-whatsapp-adapter.php')?:'';
$processPos=strpos($source,'function hache_sharky_whatsapp_process');
$process=$processPos===false?'':substr($source,$processPos);
$offerPos=strpos($process,"&&hache_sharky_whatsapp_offer_affirmation");
$reengagementPos=strpos($process,"&&hache_sharky_whatsapp_low_information_reengagement");
pr85_followup_ok($offerPos!==false&&$reengagementPos!==false&&$offerPos<$reengagementPos,'Offer affirmation routing must run before generic low-information reengagement.');

echo "SHARKY_PR85_FOLLOWUPS_VENUE_UX_REGRESSION_OK\n";
