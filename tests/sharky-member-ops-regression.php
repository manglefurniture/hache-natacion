<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-member-ops.php';
require_once __DIR__.'/../config/sharky-member-payments.php';

function member_ok(bool $ok,string $message): void{if(!$ok){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}}
function member_eq(mixed $actual,mixed $expected,string $message): void{member_ok($actual===$expected,$message.' expected='.var_export($expected,true).' actual='.var_export($actual,true));}

member_eq(hache_sharky_member_intent('Hola'),'greeting','Known members need a deterministic greeting route.');
member_eq(hache_sharky_member_intent('¿Hay clase hoy?'),'class_today','Today-class questions must be deterministic.');
member_eq(hache_sharky_member_intent('¿Cuánto debo de mensualidad?'),'payments','Payment questions must be deterministic.');
member_eq(hache_sharky_member_intent('Hoy no voy a poder ir'),'absence','Natural absence language must start the absence flow.');
member_eq(hache_sharky_member_intent('¿Cuántas reposiciones tengo?'),'repos','Repossession queries must be recognized.');
member_eq(hache_sharky_member_intent('Cancela mi clase de hoy'),'teacher_cancel','Teacher cancellation intent must be recognized.');
member_eq(hache_sharky_member_intent('','member:teacher_cancel'),'teacher_cancel','Teacher cancellation button must be recognized.');
member_eq(hache_sharky_member_intent('','member:absence_add_evidence'),'member:absence_add_evidence','Optional evidence button must stay deterministic.');

member_eq(hache_sharky_member_parse_date('hoy','2026-09-07'),'2026-09-07','Today token must resolve in Cancun-local flow date.');
member_eq(hache_sharky_member_parse_date('mañana','2026-09-07'),'2026-09-08','Tomorrow token must resolve.');
member_eq(hache_sharky_member_parse_date('09/09','2026-09-07'),'2026-09-09','DD/MM must resolve in current year.');
member_eq(hache_sharky_member_parse_date('2026-02-31','2026-09-07'),null,'Invalid dates must be rejected.');

$buttons=hache_sharky_member_buttons('529981234567','Hola',[
    ['id'=>'one','title'=>'Uno'],['id'=>'two','title'=>'Dos'],['id'=>'three','title'=>'Tres'],['id'=>'four','title'=>'Cuatro'],
]);
member_eq($buttons['type']??null,'interactive','Member home must use an interactive payload.');
member_eq(count($buttons['interactive']['action']['buttons']??[]),3,'WhatsApp button payload must enforce the three-button limit.');

$externalMonthly=hache_sharky_member_payment_external('student-1','MENSUALIDAD','monthly-1');
$externalMonthlyAgain=hache_sharky_member_payment_external('student-1','MENSUALIDAD','monthly-1');
$externalIntensive=hache_sharky_member_payment_external('student-1','INTENSIVO','course-1');
member_eq($externalMonthly,$externalMonthlyAgain,'Payment external references must be deterministic for reconciliation.');
member_ok($externalMonthly!==$externalIntensive,'Monthly and intensive payments need separate external references.');
member_ok(!str_contains($externalMonthly,'student-1'),'External references must not leak student identifiers.');

$partialPayment=['kind'=>'intensive','course_id'=>'course-1','price'=>1200.0,'paid'=>400.0,'due'=>800.0,'pending'=>true];
member_ok(hache_sharky_member_payment_partial_intensive($partialPayment),'An intensive with a valid partial payment must be detected.');
$partialContext=['identity'=>['student_id'=>'student-1'],'payment'=>$partialPayment];
member_eq(hache_sharky_member_payment_pending_from_context($partialContext),null,'A residual intensive balance must never create a second checkout.');
member_ok(str_contains(hache_sharky_member_payment_partial_message($partialPayment),'$800.00'),'Partial-payment answer must preserve the real remaining balance.');
$zeroPaidContext=['identity'=>['student_id'=>'student-1'],'payment'=>['kind'=>'intensive','course_id'=>'course-1','price'=>1200.0,'paid'=>0.0,'due'=>1200.0,'pending'=>true]];
member_ok(is_array(hache_sharky_member_payment_pending_from_context($zeroPaidContext)),'A zero-paid intensive may still open its first valid checkout.');

$root=dirname(__DIR__);
$memberMigration=(string)file_get_contents($root.'/database/migrations/20260907_sharky_member_ops.sql');
member_ok(str_contains($memberMigration,'CREATE TABLE IF NOT EXISTS profesores'),'Professor registry migration is required.');
member_ok(str_contains($memberMigration,'profesor_horarios'),'Teacher cancellation must be scoped by assigned schedules.');
member_ok(str_contains($memberMigration,'sharky_ausencia_evidencias'),'Absence evidence metadata must be durable.');
$paymentMigration=(string)file_get_contents($root.'/database/migrations/20260907_sharky_member_payments.sql');
member_ok(str_contains($paymentMigration,'sharky_member_payment_intents'),'Registered-student checkout needs durable payment intents.');

$webhook=(string)file_get_contents($root.'/public/api/whatsapp-orchestrator-lab.php');
member_ok(str_contains($webhook,'$memberEvidenceEvents=hache_sharky_member_extract_media_events($pdo,$payload)'),'Webhook must extract absence evidence before generic proof media.');
member_ok(str_contains($webhook,'$memberEvidenceIds[$memberEvidenceId]=true'),'Absence evidence must reserve its Meta message receipt.');
member_ok(str_contains($webhook,'!isset($memberEvidenceIds[(string)($event[\'id\']??\'\')])'),'Generic proof events must be deduplicated against absence evidence IDs.');
member_ok(str_contains($webhook,'hache_sharky_member_payment_process_event'),'Payment self-service must run before generic Sharky routing.');
member_ok(str_contains($webhook,'sharky_member_should_handle'),'Member operations must be explicitly gated before claiming an inbox event.');
$payments=(string)file_get_contents($root.'/config/sharky-member-payments.php');
member_ok(str_contains($payments,"tipo='INTENSIVO' AND estado='VALIDO' LIMIT 1 FOR UPDATE"),'MP reconciliation must revalidate the one-valid-intensive-payment invariant inside the transaction.');
$api=(string)file_get_contents($root.'/api/profesores.php');
member_ok(str_contains($api,"auth_require(['ADMIN'])"),'Only administrators may register or assign professors.');
member_ok(str_contains($api,'auth_csrf_validate'),'Professor administration POSTs must validate CSRF.');
member_ok(str_contains($api,"'csrf'=>auth_csrf_token()"),'Professor administration GET must provide a session CSRF token.');
member_ok(str_contains($api,"accion:'")===false,'Professor API must not contain UI-side action literals.');
$professorPage=(string)file_get_contents($root.'/public/profesores.php');
member_ok(str_contains($professorPage,'csrf:model.csrf'),'Professor administration UI must send the current CSRF token on mutations.');

fwrite(STDOUT,"SHARKY_MEMBER_OPS_REGRESSION_OK\n");
