<?php

declare(strict_types=1);

if(!function_exists('mb_substr')){function mb_substr(string $s,int $start,?int $length=null,?string $enc=null):string{return $length===null?substr($s,$start):substr($s,$start,$length);}}
if(!function_exists('mb_strlen')){function mb_strlen(string $s,?string $enc=null):int{return strlen($s);}}
if(!function_exists('mb_strtolower')){function mb_strtolower(string $s,?string $enc=null):string{return strtolower($s);}}

require __DIR__.'/../config/sharky-orchestrator.php';

function virtual_expect(bool $condition,string $message):void
{
    if($condition)return;
    fwrite(STDERR,"FAIL: {$message}\n");
    exit(1);
}

function virtual_eq(mixed $actual,mixed $expected,string $message):void
{
    if($actual===$expected)return;
    fwrite(STDERR,"FAIL: {$message}\nExpected: ".var_export($expected,true)."\nActual: ".var_export($actual,true)."\n");
    exit(1);
}

$now=1788537600;
$today='2026-09-04';
$contact='529983785432';

// Reproduce the production failure: WhatsApp returns a mixed-case opaque id,
// while the orchestrator normalizes interactive ids to lowercase. Virtual
// options must therefore be generated lowercase so the round trip still maps
// to the fresh backend option.
$state=hache_sharky_orchestrator_state(null,$now);
$state['identity']=array_replace($state['identity'],[
    'kind'=>'prospect','verified'=>true,'source'=>'self_declared',
]);
$state['commercial_context']=array_replace($state['commercial_context'],[
    'program'=>'intensive','sede_clave'=>'PALAPAS','swim_level'=>'beginner',
]);
$state=hache_sharky_orchestrator_flow($state,'register_intensive','course',['sede_clave'=>'PALAPAS'],$now);

$options=[[
    'id'=>'date:palapas:2026-11-09',
    'sede_clave'=>'PALAPAS',
    'fecha_inicio'=>'2026-11-09',
    'label'=>'Inicio 09/11/2026',
    'precio'=>null,
    'schedules'=>[
        ['id'=>'schedule-pal-19','label'=>'19:00–20:00'],
        ['id'=>'schedule-pal-20','label'=>'20:00–21:00'],
    ],
]];

$x=hache_sharky_orchestrate($state,[
    'id'=>'virtual.course','from'=>$contact,
    'interactive_id'=>'course:date:PALAPAS:2026-11-09','text'=>'Inicio 09/11/2026',
],[
    'now'=>$now+1,'today'=>$today,'intensive_options'=>$options,
]);
virtual_eq($x['decision']['kind'],'registration_schedule','valid virtual future date must advance to schedule selection');
virtual_eq($x['state']['flow']['step'],'schedule','virtual date must stay inside controlled registration flow');
virtual_eq($x['state']['flow']['data']['fecha_inicio'],'2026-11-09','selected virtual date must be persisted');
virtual_eq($x['state']['flow']['data']['course_id'],'date:palapas:2026-11-09','stable virtual option id must be persisted');
virtual_expect(($x['decision']['action']??null)===null,'selecting a virtual date must not execute a business mutation');

$x=hache_sharky_orchestrate($x['state'],[
    'id'=>'virtual.schedule','from'=>$contact,
    'interactive_id'=>'schedule:schedule-pal-19','text'=>'19:00–20:00',
],['now'=>$now+2,'today'=>$today,'intensive_options'=>$options]);
virtual_eq($x['decision']['kind'],'registration_name','schedule selection must continue registration');

$x=hache_sharky_orchestrate($x['state'],[
    'id'=>'virtual.name','from'=>$contact,'text'=>'María Prueba Virtual',
],['now'=>$now+3,'today'=>$today,'intensive_options'=>$options]);
virtual_eq($x['decision']['kind'],'registration_birthdate','name must advance to birthdate');

$x=hache_sharky_orchestrate($x['state'],[
    'id'=>'virtual.birth','from'=>$contact,'text'=>'01/01/1990',
],['now'=>$now+4,'today'=>$today,'intensive_options'=>$options,'min_age'=>12]);
virtual_eq($x['decision']['kind'],'registration_confirm','valid birthdate must reach final confirmation');

$x=hache_sharky_orchestrate($x['state'],[
    'id'=>'virtual.confirm','from'=>$contact,'text'=>'confirmo',
],['now'=>$now+5,'today'=>$today,'intensive_options'=>$options,'min_age'=>12]);
virtual_eq($x['decision']['kind'],'registration_execute','final confirmation must emit registration execution');
virtual_eq($x['decision']['action']['type']??null,'register_intensive','virtual date must end in normal intensive registration action');
virtual_eq($x['decision']['action']['fecha_inicio']??null,'2026-11-09','registration action must preserve virtual start date');
virtual_expect(($x['decision']['action']['requires_revalidation']??false)===true,'final write must still require backend revalidation');

$business=file_get_contents(__DIR__.'/../config/sharky-business-actions.php');
virtual_expect(is_string($business),'business action source must be readable');
virtual_expect(str_contains($business,"'date:'.strtolower((string)\$site['clave']).':'.\$date"),'virtual option ids must be generated lowercase');
virtual_expect(str_contains($business,'if (!$course) {'),'registration service must keep create-on-demand course path');
virtual_expect(str_contains($business,'INSERT INTO cursos_intensivos'),'create-on-demand path must create the intensive course only at final registration');
virtual_expect(str_contains($business,'Creado automáticamente desde registro conversacional de Sharky.'),'auto-created courses must remain auditable');

fwrite(STDOUT,"Sharky virtual intensive date regression: OK\n");
