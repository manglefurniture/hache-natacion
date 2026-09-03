<?php

declare(strict_types=1);
if(!function_exists('mb_substr')){function mb_substr(string $s,int $start,?int $length=null,?string $enc=null):string{return $length===null?substr($s,$start):substr($s,$start,$length);}}
if(!function_exists('mb_strlen')){function mb_strlen(string $s,?string $enc=null):int{return strlen($s);}}
if(!function_exists('mb_strtolower')){function mb_strtolower(string $s,?string $enc=null):string{return strtolower($s);}}
require __DIR__.'/../config/sharky-orchestrator.php';

function coherence_ok(bool $condition,string $message):void
{
    if(!$condition){fwrite(STDERR,"SHARKY COHERENCE FAIL: $message\n");exit(1);}
}
function coherence_eq(mixed $actual,mixed $expected,string $message):void
{
    if($actual!==$expected){fwrite(STDERR,"SHARKY COHERENCE FAIL: $message\nExpected: ".var_export($expected,true)."\nActual: ".var_export($actual,true)."\n");exit(1);}
}

$now=1788457200;
$today='2026-09-03';
$contact='529900000099';
$state=hache_sharky_orchestrator_state(null,$now);
$state['identity']=array_replace($state['identity'],['kind'=>'prospect','verified'=>true,'source'=>'self_declared']);

// Productive bug reproduction: every turn starts from the state returned by
// the prior turn, exactly as the durable worker does.
$main1=hache_sharky_orchestrate(null,['id'=>'coh.main.1','from'=>$contact,'text'=>'Soy nuevo'],['now'=>$now,'today'=>$today,'identity'=>['found'=>false]]);
$main2=hache_sharky_orchestrate($main1['state'],['id'=>'coh.main.2','from'=>$contact,'text'=>'intensivo'],['now'=>$now+1,'today'=>$today]);
$main3=hache_sharky_orchestrate($main2['state'],['id'=>'coh.main.3','from'=>$contact,'text'=>'Palapas'],['now'=>$now+2,'today'=>$today]);
coherence_eq($main3['state']['identity']['kind'],'prospect','main sequence must retain prospect identity');
coherence_eq($main3['state']['commercial_context']['program'],'intensive','main sequence must retain intensive program');
coherence_eq($main3['state']['commercial_context']['sede_clave'],'PALAPAS','main sequence must retain Palapas venue');
$mainNext=hache_sharky_orchestrator_next_required_step($main3['state']);
coherence_eq($mainNext['slot'],'age','after identity, program and venue the only discovery slot pending is age');

$step1=hache_sharky_orchestrate($state,['id'=>'coh.1','from'=>$contact,'text'=>'El intensivo'],['now'=>$now,'today'=>$today]);
coherence_eq($step1['state']['commercial_context']['program'],'intensive','bare intensive selection must persist as commercial context');
coherence_eq($step1['decision']['kind'],'conversation','selecting a program alone must stay conversational');

$step2=hache_sharky_orchestrate($step1['state'],['id'=>'coh.2','from'=>$contact,'text'=>'Quiero inscribirme en palapas'],['now'=>$now+1,'today'=>$today]);
coherence_eq($step2['decision']['kind'],'registration_offer','registration request must reuse previously confirmed intensive context');
coherence_eq($step2['state']['flow']['step'],'offer','registration still requires explicit consent');
coherence_eq($step2['state']['flow']['data']['sede_clave'],'PALAPAS','Palapas must be remembered in the controlled registration flow');

$options=[
    [
        'id'=>'course-pal-1',
        'sede_clave'=>'PALAPAS',
        'fecha_inicio'=>'2026-09-07',
        'label'=>'7 al 25 de septiembre',
        'schedules'=>[['id'=>'pal-20','label'=>'20:00–21:00']],
    ],
    [
        'id'=>'course-mv-1',
        'sede_clave'=>'MONTEVERDE',
        'fecha_inicio'=>'2026-09-07',
        'label'=>'7 al 25 de septiembre',
        'schedules'=>[['id'=>'mv-20','label'=>'20:00–21:00']],
    ],
];
$step3=hache_sharky_orchestrate($step2['state'],['id'=>'coh.3','from'=>$contact,'text'=>'sí'],['now'=>$now+2,'today'=>$today,'intensive_options'=>$options]);
coherence_eq($step3['decision']['kind'],'registration_course','known Palapas selection must skip asking the venue again');
coherence_eq($step3['state']['flow']['step'],'course','flow must advance directly to course options');
coherence_ok(($step3['decision']['ui']['type']??'')==='list','course choice must render as an interactive list');
coherence_eq($step3['decision']['ui']['options'][0]['id']??null,'course:course-pal-1','Palapas active course must be offered');

$age=hache_sharky_orchestrate($step1['state'],['id'=>'coh.age','from'=>$contact,'text'=>'43'],['now'=>$now+3,'today'=>$today]);
coherence_eq($age['state']['commercial_context']['age'],43,'a standalone age answer must remain available as confirmed context');
coherence_eq($age['state']['commercial_context']['program'],'intensive','capturing age must not forget the selected program');

$regular=hache_sharky_orchestrate($state,['id'=>'coh.regular','from'=>$contact,'text'=>'Clases regulares'],['now'=>$now+4,'today'=>$today]);
$regularRegistration=hache_sharky_orchestrate($regular['state'],['id'=>'coh.regular.2','from'=>$contact,'text'=>'Quiero inscribirme en palapas'],['now'=>$now+5,'today'=>$today]);
coherence_eq($regularRegistration['state']['commercial_context']['program'],'regular','regular selection must remain authoritative');
coherence_eq($regularRegistration['decision']['kind'],'conversation','generic registration wording after regular selection must not be misrouted to intensive registration');

// Review finding 1: comparisons/questions are not confirmed choices, explicit changes are.
$ambiguous=hache_sharky_orchestrate($state,['id'=>'coh.ambiguous','from'=>$contact,'text'=>'¿Intensivo o clases regulares?\n¿Palapas o Monteverde?'],['now'=>$now+6,'today'=>$today]);
coherence_eq($ambiguous['state']['commercial_context']['program'],null,'program comparison must not become confirmed context');
coherence_eq($ambiguous['state']['commercial_context']['sede_clave'],null,'venue comparison must not become confirmed context');
$verbAmbiguous=hache_sharky_orchestrate($state,['id'=>'coh.ambiguous.verb','from'=>$contact,'text'=>'Prefiero intensivo o clases regulares\nPrefiero Palapas o Monteverde'],['now'=>$now+7,'today'=>$today]);
coherence_eq($verbAmbiguous['state']['commercial_context']['program'],null,'preference wording must not turn an either-or program comparison into a confirmed choice');
coherence_eq($verbAmbiguous['state']['commercial_context']['sede_clave'],null,'preference wording must not turn an either-or venue comparison into a confirmed choice');
$singleQuestion=hache_sharky_orchestrate($state,['id'=>'coh.question','from'=>$contact,'text'=>'¿Qué horarios tiene Palapas?'],['now'=>$now+8,'today'=>$today]);
coherence_eq($singleQuestion['state']['commercial_context']['sede_clave'],null,'informational venue question must not become a confirmed venue');
$shortVenue=hache_sharky_orchestrate($state,['id'=>'coh.short.venue','from'=>$contact,'text'=>'En Palapas'],['now'=>$now+9,'today'=>$today]);
coherence_eq($shortVenue['state']['commercial_context']['sede_clave'],'PALAPAS','short venue answer with preposition must count as an explicit selection');
$changedProgram=hache_sharky_orchestrate($step1['state'],['id'=>'coh.change.program','from'=>$contact,'text'=>'Ya no intensivo, prefiero regulares'],['now'=>$now+10,'today'=>$today]);
coherence_eq($changedProgram['state']['commercial_context']['program'],'regular','explicit program change must replace prior intensive context');

// Review finding 2: batched turns still classify an intensive registration request for adapter handoff.
$studentState=hache_sharky_orchestrator_state(null,$now);
$studentState['identity']=array_replace($studentState['identity'],['kind'=>'student','verified'=>true,'source'=>'whatsapp_number','student_id'=>'stu-99']);
$batchedText="El intensivo\nQuiero inscribirme en Palapas";
coherence_eq(hache_sharky_orchestrator_contextual_intent($studentState,$batchedText),'register_intensive','batched program plus registration must be visible to existing-student prerouting');

// Review finding 3: a venue change while the offer is open replaces the copied flow venue.
$offerPal=hache_sharky_orchestrate($step1['state'],['id'=>'coh.offer.pal','from'=>$contact,'text'=>'Quiero inscribirme en Palapas'],['now'=>$now+11,'today'=>$today]);
$changeVenue=hache_sharky_orchestrate($offerPal['state'],['id'=>'coh.change.venue','from'=>$contact,'text'=>'Mejor Monteverde'],['now'=>$now+12,'today'=>$today,'intensive_options'=>$options]);
coherence_eq($changeVenue['state']['commercial_context']['sede_clave'],'MONTEVERDE','explicit venue change must replace durable venue context');
coherence_eq($changeVenue['state']['flow']['data']['sede_clave'],'MONTEVERDE','open registration offer must reconcile copied venue with current context');
coherence_eq($changeVenue['decision']['kind'],'registration_offer','changing venue must not itself consent to registration');
$confirmChangedVenue=hache_sharky_orchestrate($changeVenue['state'],['id'=>'coh.change.venue.yes','from'=>$contact,'text'=>'sí'],['now'=>$now+13,'today'=>$today,'intensive_options'=>$options]);
coherence_eq($confirmChangedVenue['decision']['kind'],'registration_course','consent after venue change must advance to course options');
coherence_eq($confirmChangedVenue['decision']['ui']['options'][0]['id']??null,'course:course-mv-1','course list must use the newly selected Monteverde venue');

// Review finding 4: negated registration language never opens a flow or handoff intent.
$negated=hache_sharky_orchestrate($step1['state'],['id'=>'coh.negated','from'=>$contact,'text'=>'No quiero inscribirme'],['now'=>$now+14,'today'=>$today]);
coherence_eq($negated['decision']['kind'],'conversation','negated registration request must remain conversational');
coherence_eq($negated['state']['flow'],null,'negated registration request must not open a controlled registration flow');
coherence_eq(hache_sharky_orchestrator_contextual_intent($studentState,'No quiero inscribirme'),'no','negated registration must not trigger existing-student registration handoff');

// Final review P2 1: a negated program preference cannot become or remain confirmed context.
$negatedProgram=hache_sharky_orchestrate($state,['id'=>'coh.p2.neg.program','from'=>$contact,'text'=>'No quiero intensivo'],['now'=>$now+15,'today'=>$today]);
coherence_eq($negatedProgram['state']['commercial_context']['program'],null,'negated program preference must not persist intensive context');
$rejectedExisting=hache_sharky_orchestrate($step1['state'],['id'=>'coh.p2.reject.existing','from'=>$contact,'text'=>'No quiero intensivo'],['now'=>$now+16,'today'=>$today]);
coherence_eq($rejectedExisting['state']['commercial_context']['program'],null,'explicit rejection must invalidate an already remembered intensive choice');
$afterRejected=hache_sharky_orchestrate($rejectedExisting['state'],['id'=>'coh.p2.reject.follow','from'=>$contact,'text'=>'Quiero inscribirme'],['now'=>$now+17,'today'=>$today]);
coherence_eq($afterRejected['decision']['kind'],'conversation','generic registration after rejecting intensive must not reuse stale intensive context');
coherence_eq($afterRejected['state']['flow'],null,'generic registration after rejecting intensive must not open the intensive flow');
$rejectedVenue=hache_sharky_orchestrate($shortVenue['state'],['id'=>'coh.p2.reject.venue','from'=>$contact,'text'=>'No quiero Palapas'],['now'=>$now+18,'today'=>$today]);
coherence_eq($rejectedVenue['state']['commercial_context']['sede_clave'],null,'explicit venue rejection must invalidate the remembered venue');

// Final review P2 2: an affirmative choice before a question in the same message survives.
$choiceThenQuestion=hache_sharky_orchestrate($state,['id'=>'coh.p2.choice.question','from'=>$contact,'text'=>'Prefiero el intensivo. ¿Qué horarios tienen?'],['now'=>$now+19,'today'=>$today]);
coherence_eq($choiceThenQuestion['state']['commercial_context']['program'],'intensive','affirmative program choice before a question must remain confirmed');
coherence_eq($choiceThenQuestion['decision']['kind'],'conversation','choice plus informational question must stay conversational');
$choiceThenInvertedQuestion=hache_sharky_orchestrate($state,['id'=>'coh.p2.choice.question.nospace','from'=>$contact,'text'=>'Prefiero el intensivo ¿Qué horarios tienen?'],['now'=>$now+20,'today'=>$today]);
coherence_eq($choiceThenInvertedQuestion['state']['commercial_context']['program'],'intensive','inverted question mark must split a trailing question from the preceding choice');

// Final review P2 3: the most recent registration polarity wins inside a batch.
$correctiveBurst="No quiero inscribirme a regulares\nQuiero inscribirme al intensivo";
coherence_eq(hache_sharky_orchestrator_registration_polarity($correctiveBurst),true,'latest affirmative correction must override an earlier negated registration clause');
coherence_eq(hache_sharky_orchestrator_contextual_intent($studentState,$correctiveBurst),'register_intensive','corrected positive burst must preserve existing-student handoff intent');
$prospectCorrection=hache_sharky_orchestrate($state,['id'=>'coh.p2.corrective.burst','from'=>$contact,'text'=>$correctiveBurst],['now'=>$now+21,'today'=>$today]);
coherence_eq($prospectCorrection['decision']['kind'],'registration_offer','corrected positive burst must offer registration to a prospect');
$reverseBurst="Quiero inscribirme al intensivo\nNo quiero inscribirme";
coherence_eq(hache_sharky_orchestrator_registration_polarity($reverseBurst),false,'latest negation must override an earlier positive registration clause');
coherence_eq(hache_sharky_orchestrator_contextual_intent($studentState,$reverseBurst),'no','latest negation must suppress existing-student registration handoff');
$commaCorrection=hache_sharky_orchestrate($step1['state'],['id'=>'coh.p2.comma.program','from'=>$contact,'text'=>'No quiero intensivo, prefiero regulares'],['now'=>$now+22,'today'=>$today]);
coherence_eq($commaCorrection['state']['commercial_context']['program'],'regular','comma-separated correction must let the later explicit program choice win');

// Internal pre-review hardening: bare rejections clear stale memory, and a superseded "ya no" cannot cancel a later positive correction.
$bareProgramRejection=hache_sharky_orchestrate($step1['state'],['id'=>'coh.internal.bare.program','from'=>$contact,'text'=>'Ya no intensivo'],['now'=>$now+23,'today'=>$today]);
coherence_eq($bareProgramRejection['state']['commercial_context']['program'],null,'bare program rejection must clear an already remembered intensive choice');
$bareVenueRejection=hache_sharky_orchestrate($shortVenue['state'],['id'=>'coh.internal.bare.venue','from'=>$contact,'text'=>'Ya no Palapas'],['now'=>$now+24,'today'=>$today]);
coherence_eq($bareVenueRejection['state']['commercial_context']['sede_clave'],null,'bare venue rejection must clear an already remembered venue');
$softCancelCorrection="Ya no quiero inscribirme a regulares\nQuiero inscribirme al intensivo";
coherence_eq(hache_sharky_orchestrator_registration_polarity($softCancelCorrection),true,'later positive correction must override earlier ya-no registration clause');
coherence_eq(hache_sharky_orchestrator_contextual_intent($studentState,$softCancelCorrection),'register_intensive','superseded ya-no clause must not cancel a later positive existing-student handoff');
$softCancelProspect=hache_sharky_orchestrate($state,['id'=>'coh.internal.soft.cancel','from'=>$contact,'text'=>$softCancelCorrection],['now'=>$now+25,'today'=>$today]);
coherence_eq($softCancelProspect['decision']['kind'],'registration_offer','superseded ya-no clause must not cancel the later positive registration request');

$adapter=file_get_contents(__DIR__.'/../config/sharky-whatsapp-adapter.php')?:'';
coherence_ok(str_contains($adapter,'Contexto comercial ya confirmado por el usuario'),'model instruction must receive confirmed commercial context');
coherence_ok(str_contains($adapter,'No vuelvas a preguntar estos datos'),'model must be explicitly forbidden from asking confirmed commercial fields again');
coherence_ok(str_contains($adapter,'hache_sharky_orchestrator_contextual_intent'),'WhatsApp pre-routing must use contextual intent too');
coherence_ok(str_contains($adapter,'existing_student_intensive_handoff'),'existing-student registration handoff guard must remain in the adapter');

fwrite(STDOUT,"SHARKY_CONVERSATION_COHERENCE_OK\n");
