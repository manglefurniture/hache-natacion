<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-runtime.php';
require_once __DIR__.'/../config/sharky-takeover-maintenance.php';
require_once __DIR__.'/../config/sharky-member-routing.php';

function sharky_midnight_expect(bool $ok,string $message): void
{
    if(!$ok){fwrite(STDERR,"SHARKY MEMBER/MIDNIGHT FAIL: {$message}\n");exit(1);}
}

$beforeMidnight=['activated_at'=>'2026-09-08T04:59:59+00:00']; // 23:59:59 in Cancun on Sep 7.
$afterMidnight=['activated_at'=>'2026-09-08T05:00:01+00:00'];  // 00:00:01 in Cancun on Sep 8.
sharky_midnight_expect(hache_sharky_takeover_local_date($beforeMidnight)==='2026-09-07','UTC activation must be evaluated using Cancun calendar date.');
sharky_midnight_expect(hache_sharky_takeover_is_stale_for_date($beforeMidnight,'2026-09-08'),'A takeover from the previous Cancun day must be released.');
sharky_midnight_expect(!hache_sharky_takeover_is_stale_for_date($afterMidnight,'2026-09-08'),'A takeover created just after midnight must survive until the next midnight.');
sharky_midnight_expect(hache_sharky_takeover_is_stale_for_date(['activated_at'=>'broken'],'2026-09-08'),'Legacy/corrupt takeover markers must not survive forever.');

sharky_midnight_expect(hache_sharky_member_closure_text('Okey muchas gracias'),'A simple thank-you after a completed flow must close naturally.');
sharky_midnight_expect(hache_sharky_member_closure_text('Perfecto, gracias 😊'),'Friendly gratitude variants must close naturally.');
sharky_midnight_expect(!hache_sharky_member_closure_text('Gracias, pero tengo otra pregunta'),'A thank-you with a new request must not close the conversation.');
sharky_midnight_expect(hache_sharky_member_pending_registration(['student'=>['estado_administrativo'=>'PENDIENTE']]),'PENDIENTE is identity only, not an active student enrolment.');
sharky_midnight_expect(!hache_sharky_member_pending_registration(['student'=>['estado_administrativo'=>'ACTIVO']]),'ACTIVO must retain normal student operations.');
sharky_midnight_expect(hache_sharky_member_pending_schedule_problem('No he podido mantener mis horarios para levantarme temprano'),'Pending-enrolment schedule difficulty must be recognized without repeating the active-student menu.');

$root=dirname(__DIR__);
$router=(string)file_get_contents($root.'/config/sharky-member-routing.php');
$webhook=(string)file_get_contents($root.'/public/api/whatsapp-orchestrator-lab.php');
$worker=(string)file_get_contents($root.'/bin/sharky-inbox-dispatch.php');
$maintenance=(string)file_get_contents($root.'/config/sharky-takeover-maintenance.php');

sharky_midnight_expect(str_contains($router,'hache_sharky_member_route_event'),'Shared member routing helper must exist.');
sharky_midnight_expect(str_contains($router,'hache_sharky_takeover_active'),'Active human takeover must continue to silence Sharky until release.');
sharky_midnight_expect(str_contains($router,'hache_sharky_member_routing_handoff_requested'),'Known students must still be able to request a person explicitly.');
sharky_midnight_expect(str_contains($router,'hache_sharky_human_request'),'Explicit human requests must preserve the controlled handoff path.');
sharky_midnight_expect(!str_contains($router,"'student_human_takeover'"),'The new member lane must never auto-handoff simply because the phone belongs to a student.');
sharky_midnight_expect(str_contains($router,'hache_sharky_member_pending_registration'),'Member routing must distinguish a pending registration from an active student.');
sharky_midnight_expect(str_contains($router,'student-close'),'A pure gratitude turn must close without redisplaying the student menu.');
sharky_midnight_expect(str_contains($router,'student-pending'),'Pending registrations need their own conversational lane.');

$webMember=strpos($webhook,'hache_sharky_member_route_event($pdo,$event,$business)');
$webGeneric=strpos($webhook,'hache_sharky_lab_process_event($pdo,$event',$webMember===false?0:$webMember);
sharky_midnight_expect($webMember!==false&&$webGeneric!==false&&$webMember<$webGeneric,'Realtime webhook must route members before the legacy/general Sharky pipeline.');

$workerMember=strpos($worker,'hache_sharky_member_route_event($pdo,$event,$business)');
$workerGeneric=strpos($worker,'hache_sharky_lab_process_event($pdo,$event',$workerMember===false?0:$workerMember);
sharky_midnight_expect($workerMember!==false&&$workerGeneric!==false&&$workerMember<$workerGeneric,'Inbox recovery must preserve the same member lane before generic processing.');

$maintenancePos=strpos($worker,'hache_sharky_takeover_midnight_tick()');
$enabledPos=strpos($worker,'$enabled=static fn():bool');
sharky_midnight_expect($maintenancePos!==false&&$enabledPos!==false&&$maintenancePos<$enabledPos,'Midnight cleanup must run even when Sharky is temporarily disabled.');
sharky_midnight_expect(str_contains($maintenance,'$activatedDate<$today'),'Daily cleanup must release only takeovers from an earlier local calendar day.');
sharky_midnight_expect(str_contains($maintenance,'takeovers_midnight_released'),'Daily release count must be observable in Sharky metrics.');

fwrite(STDOUT,"SHARKY_MEMBER_ROUTING_MIDNIGHT_OK\n");
