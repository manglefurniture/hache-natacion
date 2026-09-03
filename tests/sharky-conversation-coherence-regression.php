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
$singleQuestion=hache_sharky_orchestrate($state,['id'=>'coh.question','from'=>$contact,'text'=>'¿Qué horarios tiene Palapas?'],['now'=>$now+7,'today'=>$today]);
coherence_eq($singleQuestion['state']['commercial_context']['sede_clave'],null,'informational venue question must not become a confirmed venue');
$changedProgram=hache_sharky_orchestrate($step1['state'],['id'=>'coh.change.program','from'=>$contact,'text'=>'Ya no intensivo, prefiero regulares'],['now'=>$now+8,'today'=>$today]);
coherence_eq($changedProgram['state']['commercial_context']['program'],'regular','explicit program change must replace prior intensive context');

// Review finding 2: batched turns still classify an intensive registration request for adapter handoff.
$studentState=hache_sharky_orchestrator_state(null,$now);
$studentState['identity']=array_replace($studentState['identity'],['kind'=>'student','verified'=>true,'source'=>'whatsapp_number','student_id'=>'stu-99']);
$batchedText="El intensivo\nQuiero inscribirme en Palapas";
coherence_eq(hache_sharky_orchestrator_contextual_intent($studentState,$batchedText),'register_intensive','batched program plus registration must be visible to existing-student prerouting');

// Review finding 3: a venue change while the offer is open replaces the copied flow venue.
$offerPal=hache_sharky_orchestrate($step1['state'],['id'=>'coh.offer.pal','from'=>$contact,'text'=>'Quiero inscribirme en Palapas'],['now'=>$now+9,'today'=>$today]);
$changeVenue=hache_sharky_orchestrate($offerPal['state'],['id'=>'coh.change.venue','from'=>$contact,'text'=>'Mejor Monteverde'],['now'=>$now+10,'today'=>$today,'intensive_options'=>$options]);
coherence_eq($changeVenue['state']['commercial_context']['sede_clave'],'MONTEVERDE','explicit venue change must replace durable venue context');
coherence_eq($changeVenue['state']['flow']['data']['sede_clave'],'MONTEVERDE','open registration offer must reconcile copied venue with current context');
coherence_eq($changeVenue['decision']['kind'],'registration_offer','changing venue must not itself consent to registration');
$confirmChangedVenue=hache_sharky_orchestrate($changeVenue['state'],['id'=>'coh.change.venue.yes','from'=>$contact,'text'=>'sí'],['now'=>$now+11,'today'=>$today,'intensive_options'=>$options]);
coherence_eq($confirmChangedVenue['decision']['kind'],'registration_course','consent after venue change must advance to course options');
coherence_eq($confirmChangedVenue['decision']['ui']['options'][0]['id']??null,'course:course-mv-1','course list must use the newly selected Monteverde venue');

// Review finding 4: negated registration language never opens a flow or handoff intent.
$negated=hache_sharky_orchestrate($step1['state'],['id'=>'coh.negated','from'=>$contact,'text'=>'No quiero inscribirme'],['now'=>$now+12,'today'=>$today]);
coherence_eq($negated['decision']['kind'],'conversation','negated registration request must remain conversational');
coherence_eq($negated['state']['flow'],null,'negated registration request must not open a controlled registration flow');
coherence_eq(hache_sharky_orchestrator_contextual_intent($studentState,'No quiero inscribirme'),'no','negated registration must not trigger existing-student registration handoff');

$adapter=file_get_contents(__DIR__.'/../config/sharky-whatsapp-adapter.php')?:'';
coherence_ok(str_contains($adapter,'Contexto comercial ya confirmado por el usuario'),'model instruction must receive confirmed commercial context');
coherence_ok(str_contains($adapter,'No vuelvas a preguntar estos datos'),'model must be explicitly forbidden from asking confirmed commercial fields again');
coherence_ok(str_contains($adapter,'hache_sharky_orchestrator_contextual_intent'),'WhatsApp pre-routing must use contextual intent too');
coherence_ok(str_contains($adapter,'existing_student_intensive_handoff'),'existing-student registration handoff guard must remain in the adapter');

fwrite(STDOUT,"SHARKY_CONVERSATION_COHERENCE_OK\n");
