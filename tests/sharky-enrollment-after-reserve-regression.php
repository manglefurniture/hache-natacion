<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-orchestrator.php';
require_once __DIR__.'/../config/sharky-payment-reminder.php';
require_once __DIR__.'/../config/sharky-commerce-runtime.php';

function reserve_flow_ok(bool $ok,string $message): void
{
    if(!$ok){fwrite(STDERR,"SHARKY ENROLLMENT AFTER RESERVE FAIL: {$message}\n");exit(1);}
}

$source=(string)file_get_contents(__DIR__.'/../config/sharky-commerce-flows.php');
reserve_flow_ok(str_contains($source,"in_array(\$step,['course','name'],true)"),'Enrollment launch must support the post-reserve name step.');
reserve_flow_ok(str_contains($source,'$isPostReserveName'),'Outbound commerce upgrade must detect the post-reserve name prompt.');
reserve_flow_ok(str_contains($source,"str_contains(\$body,'Escribe el nombre completo de la persona que tomará el curso')"),'Only the established registration-name prompt may be upgraded after reserve consent.');
reserve_flow_ok(str_contains($source,"if (\$flowId === null) return \$payload;"),'Chat name capture must remain the fallback when the enrollment Flow is unavailable.');
reserve_flow_ok(str_contains($source,'hash_equals($expectedCourseId,$courseId)')&&str_contains($source,'hash_equals($expectedScheduleId,$scheduleId)'),'Post-reserve Flow submission must stay bound to the confirmed course and schedule.');
reserve_flow_ok(str_contains($source,"'Completar inscripción'"),'The existing enrollment Flow CTA must remain the launch target.');

$now=1788877800;
$state=hache_sharky_orchestrator_state(null,$now);
$state['identity']['kind']='prospect';
$state['commercial_context']['program']='intensive';
$state['commercial_context']['sede_clave']='MONTEVERDE';
$state=hache_sharky_orchestrator_flow($state,'register_intensive','name',[
    'sede_clave'=>'MONTEVERDE',
    'course_id'=>'course-mv-20260921',
    'fecha_inicio'=>'2026-09-21',
    'course_price'=>1200,
    'schedule_id'=>'schedule-mv-0800',
    'schedule_label'=>'08:00–09:00',
],$now);

$options=[[
    'id'=>'course-mv-20260921',
    'sede_clave'=>'MONTEVERDE',
    'fecha_inicio'=>'2026-09-21',
    'precio'=>1200,
    'schedules'=>[
        ['id'=>'schedule-mv-0800','label'=>'08:00–09:00'],
        ['id'=>'schedule-mv-1900','label'=>'19:00–20:00'],
    ],
]];

$event=[
    'from'=>'529980000001',
    'commerce'=>[
        'flow_kind'=>'enrollment',
        'user_action'=>'submit',
        'venue_key'=>'MONTEVERDE',
        'full_name'=>'Cliente Prueba',
        'birthdate'=>'1990-01-15',
        'course_id'=>'course-mv-20260921',
        'schedule_id'=>'schedule-mv-0800',
    ],
];

[$confirmedState,$confirmedDecision]=hache_sharky_commerce_enrollment_submit($state,$event,[
    'now'=>$now+1,
    'today'=>'2026-09-08',
    'min_age'=>12,
    'intensive_options'=>$options,
]);
reserve_flow_ok(($confirmedDecision['kind']??'')==='registration_confirm','Matching post-reserve form must continue to deterministic confirmation.');
reserve_flow_ok(($confirmedState['flow']['name']??'')==='register_intensive'&&($confirmedState['flow']['step']??'')==='confirm','Post-reserve form must reuse register_intensive/confirm.');
reserve_flow_ok(($confirmedState['flow']['data']['course_id']??'')==='course-mv-20260921','Confirmed course must be preserved.');
reserve_flow_ok(($confirmedState['flow']['data']['schedule_id']??'')==='schedule-mv-0800','Confirmed schedule must be preserved.');
reserve_flow_ok(($confirmedState['flow']['data']['name']??'')==='Cliente Prueba','Form name must enter the existing registration state.');
reserve_flow_ok(($confirmedState['flow']['data']['birthdate']??'')==='1990-01-15','Form birthdate must enter the existing registration state.');

$staleSchedule=$event;
$staleSchedule['commerce']['schedule_id']='schedule-mv-1900';
[$staleState,$staleDecision]=hache_sharky_commerce_enrollment_submit($state,$staleSchedule,[
    'now'=>$now+2,
    'today'=>'2026-09-08',
    'min_age'=>12,
    'intensive_options'=>$options,
]);
reserve_flow_ok(($staleDecision['kind']??'')==='registration_course_invalid','A form that changes the already confirmed schedule must fail closed.');
reserve_flow_ok(($staleState['flow']['step']??'')==='name','Rejected post-reserve form must not advance registration state.');
reserve_flow_ok(($staleDecision['action']['type']??'')==='refresh_intensive_options','Changed/stale selection must refresh live backend options.');

$staleCourse=$event;
$staleCourse['commerce']['course_id']='course-mv-stale';
[$staleCourseState,$staleCourseDecision]=hache_sharky_commerce_enrollment_submit($state,$staleCourse,[
    'now'=>$now+3,
    'today'=>'2026-09-08',
    'min_age'=>12,
    'intensive_options'=>$options,
]);
reserve_flow_ok(($staleCourseDecision['kind']??'')==='registration_course_invalid','A form that changes the already confirmed start date must fail closed.');
reserve_flow_ok(($staleCourseState['flow']['step']??'')==='name','Rejected stale course must not advance registration state.');

fwrite(STDOUT,"SHARKY_ENROLLMENT_AFTER_RESERVE_OK\n");
