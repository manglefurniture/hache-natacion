<?php

declare(strict_types=1);
if(!function_exists('mb_substr')){function mb_substr(string $s,int $start,?int $length=null,?string $enc=null):string{return $length===null?substr($s,$start):substr($s,$start,$length);}}
if(!function_exists('mb_strlen')){function mb_strlen(string $s,?string $enc=null):int{return strlen($s);}}
if(!function_exists('mb_strtolower')){function mb_strtolower(string $s,?string $enc=null):string{return strtolower($s);}}
require __DIR__.'/../config/sharky-orchestrator.php';

function ok(bool $condition,string $message):void{if(!$condition){fwrite(STDERR,"FAIL: $message\n");exit(1);}}
function eq(mixed $a,mixed $b,string $message):void{if($a!==$b){fwrite(STDERR,"FAIL: $message\nExpected: ".var_export($b,true)."\nActual: ".var_export($a,true)."\n");exit(1);}}

$now=1788382800;
$today='2026-09-02';

$event=['id'=>'wamid.1','from'=>'529983238893','text'=>'Hola','referral'=>[
 'source_type'=>'ad','source_id'=>'1200000001','source_url'=>'https://facebook.com/x','headline'=>'Intensivo septiembre','body'=>'Aprende a nadar','ctwa_clid'=>'clid-abc'
]];
$r=hache_sharky_orchestrate(null,$event,['now'=>$now,'today'=>$today,'identity'=>['found'=>false]]);
eq($r['decision']['kind'],'conversation_identity_prompt','unknown contact gets natural identity prompt');
eq($r['state']['referral']['first']['source_type'],'ad','first referral captured');
eq($r['state']['referral']['first']['ctwa_clid'],'clid-abc','ctwa_clid captured');
$first=$r['state']['referral']['first'];
$r2=hache_sharky_orchestrate($r['state'],['id'=>'wamid.2','from'=>'529983238893','text'=>'otra cosa','referral'=>['source_type'=>'post','source_id'=>'post-2']],['now'=>$now+2,'today'=>$today]);
eq($r2['state']['referral']['first'],$first,'first-touch attribution never overwritten');
eq($r2['state']['referral']['latest']['source_type'],'post','latest referral is updated');

$known=hache_sharky_orchestrate(null,['id'=>'known.1','from'=>'529900000001','text'=>'Hola'],[
 'now'=>$now,'today'=>$today,'identity'=>['found'=>true,'student_id'=>'stu-1','name'=>'Ariel','sede_clave'=>'MONTEVERDE','status'=>'ACTIVO']
]);
eq($known['state']['identity']['kind'],'student','known WhatsApp recognized as student');
eq($known['decision']['kind'],'conversation','known student stays conversational');

$knownAd=hache_sharky_orchestrate(null,['id'=>'known.ad','from'=>'529900000001','text'=>'Mañana no voy','referral'=>['source_type'=>'ad','source_id'=>'ad-77']],[
 'now'=>$now,'today'=>$today,'identity'=>['found'=>true,'student_id'=>'stu-1','name'=>'Ariel','sede_clave'=>'MONTEVERDE','status'=>'ACTIVO']
]);
eq($knownAd['state']['identity']['kind'],'student','student identity wins over marketing origin');
eq($knownAd['state']['referral']['first']['source_id'],'ad-77','ad attribution still retained for student');
eq($knownAd['decision']['kind'],'absence_offer','absence intent detected for recognized student');

$a=hache_sharky_orchestrate($knownAd['state'],['id'=>'abs.yes','from'=>'529900000001','text'=>'sí'],['now'=>$now+1,'today'=>$today]);
eq($a['decision']['kind'],'absence_date','yes starts controlled absence flow');
$a=hache_sharky_orchestrate($a['state'],['id'=>'abs.date','from'=>'529900000001','text'=>'mañana'],['now'=>$now+2,'today'=>$today]);
eq($a['decision']['kind'],'absence_confirm','date asks for final confirmation');
eq($a['state']['flow']['data']['date_from'],'2026-09-03','relative date is concrete');
$a=hache_sharky_orchestrate($a['state'],['id'=>'abs.confirm','from'=>'529900000001','text'=>'confirmo'],['now'=>$now+3,'today'=>$today]);
eq($a['decision']['kind'],'absence_execute','final confirmation creates action proposal');
eq($a['decision']['action']['type'],'create_absence','absence action emitted');
ok(($a['decision']['action']['requires_revalidation']??false)===true,'absence requires backend revalidation');

$unknownStudent=hache_sharky_orchestrate(null,['id'=>'u.1','from'=>'529900000002','text'=>'Soy alumno'],['now'=>$now,'today'=>$today,'identity'=>['found'=>false]]);
eq($unknownStudent['decision']['kind'],'verification_required','unknown number student claim needs verification');
eq($unknownStudent['state']['flow']['name'],'identify_student','verification flow started');

$new=hache_sharky_orchestrate(null,['id'=>'new.1','from'=>'529900000003','text'=>'Soy nuevo, quiero información'],['now'=>$now,'today'=>$today,'identity'=>['found'=>false]]);
eq($new['state']['identity']['kind'],'prospect','new lead classified as prospect');
eq($new['decision']['kind'],'conversation','prospect remains conversational');
$offer=hache_sharky_orchestrate($new['state'],['id'=>'new.2','from'=>'529900000003','text'=>'Quiero registrarme al intensivo'],['now'=>$now+1,'today'=>$today]);
eq($offer['decision']['kind'],'registration_offer','registration is offered, not executed');
eq($offer['state']['flow']['step'],'offer','controlled flow waits for explicit yes');

$options=[[
 'id'=>'course-1','sede_clave'=>'MONTEVERDE','fecha_inicio'=>'2026-09-07','label'=>'7 al 25 de septiembre','schedules'=>[
  ['id'=>'h-7','label'=>'07:00–08:00'],['id'=>'h-8','label'=>'08:00–09:00']
 ]
]];
$x=hache_sharky_orchestrate($offer['state'],['id'=>'reg.yes','from'=>'529900000003','text'=>'sí'],['now'=>$now+2,'today'=>$today,'intensive_options'=>$options]);
eq($x['decision']['kind'],'registration_sede','explicit yes starts controlled registration');
$x=hache_sharky_orchestrate($x['state'],['id'=>'reg.sede','from'=>'529900000003','interactive_id'=>'sede:monteverde','text'=>''],['now'=>$now+3,'today'=>$today,'intensive_options'=>$options]);
eq($x['decision']['kind'],'registration_course','sede moves to course list');
$x=hache_sharky_orchestrate($x['state'],['id'=>'reg.course','from'=>'529900000003','interactive_id'=>'course:course-1','text'=>''],['now'=>$now+4,'today'=>$today,'intensive_options'=>$options]);
eq($x['decision']['kind'],'registration_schedule','course moves to schedule list');
$x=hache_sharky_orchestrate($x['state'],['id'=>'reg.schedule','from'=>'529900000003','interactive_id'=>'schedule:h-7','text'=>''],['now'=>$now+5,'today'=>$today,'intensive_options'=>$options]);
eq($x['decision']['kind'],'registration_name','schedule moves to name');
$x=hache_sharky_orchestrate($x['state'],['id'=>'reg.name','from'=>'529900000003','text'=>'Juan Pérez López'],['now'=>$now+6,'today'=>$today,'intensive_options'=>$options]);
eq($x['decision']['kind'],'registration_birthdate','name moves to birthdate');
$x=hache_sharky_orchestrate($x['state'],['id'=>'reg.birth','from'=>'529900000003','text'=>'01/01/2000'],['now'=>$now+7,'today'=>$today,'intensive_options'=>$options,'min_age'=>12]);
eq($x['decision']['kind'],'registration_confirm','valid age reaches final confirmation');
$x=hache_sharky_orchestrate($x['state'],['id'=>'reg.confirm','from'=>'529900000003','text'=>'confirmo'],['now'=>$now+8,'today'=>$today,'intensive_options'=>$options,'min_age'=>12]);
eq($x['decision']['action']['type'],'register_intensive','registration action emitted only after confirm');
ok(($x['decision']['action']['requires_revalidation']??false)===true,'registration requires last-second backend revalidation');

$y=hache_sharky_orchestrate($offer['state'],['id'=>'age.yes','from'=>'529900000003','text'=>'sí'],['now'=>$now+2,'today'=>$today,'intensive_options'=>$options]);
$y=hache_sharky_orchestrate($y['state'],['id'=>'age.sede','from'=>'529900000003','interactive_id'=>'sede:monteverde'],['now'=>$now+3,'today'=>$today,'intensive_options'=>$options]);
$y=hache_sharky_orchestrate($y['state'],['id'=>'age.course','from'=>'529900000003','interactive_id'=>'course:course-1'],['now'=>$now+4,'today'=>$today,'intensive_options'=>$options]);
$y=hache_sharky_orchestrate($y['state'],['id'=>'age.schedule','from'=>'529900000003','interactive_id'=>'schedule:h-7'],['now'=>$now+5,'today'=>$today,'intensive_options'=>$options]);
$y=hache_sharky_orchestrate($y['state'],['id'=>'age.name','from'=>'529900000003','text'=>'Alumno Menor'],['now'=>$now+6,'today'=>$today,'intensive_options'=>$options]);
$y=hache_sharky_orchestrate($y['state'],['id'=>'age.birth','from'=>'529900000003','text'=>'01/01/2020'],['now'=>$now+7,'today'=>$today,'intensive_options'=>$options,'min_age'=>12]);
eq($y['decision']['kind'],'registration_age_rejected','underage registration is stopped deterministically');
eq($y['state']['mode'],'conversation','age rejection exits controlled flow');

$c=hache_sharky_orchestrate($offer['state'],['id'=>'cancel.1','from'=>'529900000003','text'=>'cancelar'],['now'=>$now+2,'today'=>$today]);
eq($c['decision']['kind'],'flow_cancelled','cancel exits controlled flow');
eq($c['state']['flow'],null,'flow cleared on cancel');

$take=hache_sharky_orchestrate($known['state'],['id'=>'take.1','from'=>'529900000001','text'=>'¿hola?'],['now'=>$now+10,'today'=>$today,'human_takeover'=>true]);
eq($take['decision']['kind'],'silent_human_takeover','manual human reply suppresses Sharky');

$dup=hache_sharky_orchestrate($known['state'],['id'=>'known.1','from'=>'529900000001','text'=>'otra vez'],['now'=>$now+11,'today'=>$today]);
eq($dup['decision']['kind'],'duplicate','same Meta message id is ignored');

$old=hache_sharky_orchestrator_flow(hache_sharky_orchestrator_state(null,$now),'absence','confirm',['date_from'=>'2026-09-03','date_to'=>'2026-09-03'],$now);
$expired=hache_sharky_orchestrate($old,['id'=>'late.yes','from'=>'529900000001','text'=>'sí'],['now'=>$now+HACHE_SHARKY_FLOW_TTL+1,'today'=>$today,'identity'=>['found'=>true,'student_id'=>'stu-1']]);
ok(($expired['decision']['action']??null)===null,'expired flow cannot execute an action');

$batch=hache_sharky_orchestrator_batch([
 ['id'=>'b1','text'=>'Hola Hache Natación, quiero información sobre el curso intensivo','timestamp_ms'=>1000],
 ['id'=>'b2','text'=>'Hola buenas noches','timestamp_ms'=>1700],
 ['id'=>'b3','text'=>'Soy principiante','timestamp_ms'=>2300],
]);
eq(count($batch['ids']),3,'batch retains all ids');
eq($batch['text'],"Hola Hache Natación, quiero información sobre el curso intensivo\nHola buenas noches\nSoy principiante",'rapid messages become one model turn');

$buttons=hache_sharky_orchestrator_identity_prompt()['ui']['buttons'];
ok(count($buttons)<=3,'reply-button prompts never exceed WhatsApp button limit');

fwrite(STDOUT,"Sharky orchestrator regression: OK\n");
