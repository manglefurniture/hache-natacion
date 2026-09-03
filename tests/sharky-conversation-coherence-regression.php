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

$options=[[
    'id'=>'course-pal-1',
    'sede_clave'=>'PALAPAS',
    'fecha_inicio'=>'2026-09-07',
    'label'=>'7 al 25 de septiembre',
    'schedules'=>[['id'=>'pal-20','label'=>'20:00–21:00']],
]];
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

$adapter=file_get_contents(__DIR__.'/../config/sharky-whatsapp-adapter.php')?:'';
coherence_ok(str_contains($adapter,'Contexto comercial ya confirmado por el usuario'),'model instruction must receive confirmed commercial context');
coherence_ok(str_contains($adapter,'No vuelvas a preguntar estos datos'),'model must be explicitly forbidden from asking confirmed commercial fields again');
coherence_ok(str_contains($adapter,'hache_sharky_orchestrator_contextual_intent'),'WhatsApp pre-routing must use contextual intent too');

fwrite(STDOUT,"SHARKY_CONVERSATION_COHERENCE_OK\n");
