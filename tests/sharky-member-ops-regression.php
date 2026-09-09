<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-whatsapp-adapter.php';
require_once __DIR__.'/../config/sharky-member-ops.php';
require_once __DIR__.'/../config/sharky-member-payments.php';
require_once __DIR__.'/../config/sharky-member-routing.php';

function member_ok(bool $ok,string $message): void{if(!$ok){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}}
function member_eq(mixed $actual,mixed $expected,string $message): void{member_ok($actual===$expected,$message.' expected='.var_export($expected,true).' actual='.var_export($actual,true));}

member_eq(hache_sharky_member_intent('Hola'),'greeting','Known members need a deterministic greeting route.');
member_eq(hache_sharky_member_intent('¿Hay clase hoy?'),'class_today','Today-class questions must be deterministic.');
member_eq(hache_sharky_member_intent('¿Cuánto debo de mensualidad?'),'payments','Payment questions must be deterministic.');
member_eq(hache_sharky_member_intent('','member:pay'),'payments','Pay-now button must stay in the deterministic payment lane.');
member_eq(hache_sharky_member_intent('','member:pay:transfer'),'payments','Payment-method buttons must stay in the deterministic payment lane.');
member_eq(hache_sharky_member_intent('Hoy no voy a poder ir'),'absence','Natural absence language must start the absence flow.');
member_eq(hache_sharky_member_intent('¿Cuántas reposiciones tengo?'),'repos','Repossession queries must be recognized.');
member_eq(hache_sharky_member_intent('Cancela mi clase de hoy'),'teacher_cancel','Teacher cancellation intent must be recognized.');
member_eq(hache_sharky_member_intent('','member:teacher_cancel'),'teacher_cancel','Teacher cancellation button must be recognized.');
member_eq(hache_sharky_member_intent('','member:absence_add_evidence'),'member:absence_add_evidence','Optional evidence button must stay deterministic.');

member_eq(hache_sharky_member_parse_date('hoy','2026-09-07'),'2026-09-07','Today token must resolve in Cancun-local flow date.');
member_eq(hache_sharky_member_parse_date('mañana','2026-09-07'),'2026-09-08','Tomorrow token must resolve.');
member_eq(hache_sharky_member_parse_date('09/09','2026-09-07'),'2026-09-09','DD/MM must resolve in current year.');
member_eq(hache_sharky_member_parse_date('2026-02-31','2026-09-07'),null,'Invalid dates must be rejected.');
member_eq(hache_sharky_member_date_label('2026-09-08'),'8 de septiembre','Human date labels must preserve the selected absence date.');

$buttons=hache_sharky_member_buttons('529981234567','Hola',[
    ['id'=>'one','title'=>'Uno'],['id'=>'two','title'=>'Dos'],['id'=>'three','title'=>'Tres'],['id'=>'four','title'=>'Cuatro'],
]);
member_eq($buttons['type']??null,'interactive','Member home must use an interactive payload.');
member_eq(count($buttons['interactive']['action']['buttons']??[]),3,'WhatsApp button payload must enforce the three-button limit.');

$pendingGreeting=[
    'identity'=>['name'=>'Ariel Fernández'],
    'program'=>'regular',
    'payment'=>['kind'=>'monthly','due'=>1200.0,'pending'=>true],
];
$greeting=hache_sharky_member_student_greeting($pendingGreeting);
member_ok(!str_contains($greeting,'1,200')&&!str_contains($greeting,'$'),'Initial greeting must never expose a pending balance amount.');
member_ok(!str_contains(mb_strtolower($greeting),'identifi'),'Recognition must stay silent in student-facing copy.');

$pendingRegistration=[
    'identity'=>['name'=>'Juan Pérez','student_id'=>'student-1'],
    'student'=>['estado_administrativo'=>'PENDIENTE'],
    'program'=>'intensive',
    'payment'=>['kind'=>'intensive','course_id'=>'course-1','price'=>1200.0,'paid'=>0.0,'due'=>1200.0,'pending'=>true],
];
$pendingPaymentPayload=hache_sharky_member_pending_payment_payload('529981234567',$pendingRegistration);
member_ok(is_array($pendingPaymentPayload),'An unpaid pending registration must expose a payment-first prompt.');
$pendingPaymentText=(string)($pendingPaymentPayload['interactive']['body']['text']??'');
member_ok(str_contains($pendingPaymentText,'$1,200.00 MXN')&&str_contains($pendingPaymentText,'curso intensivo'),'Pending intensive prompt must show the real balance and program.');
member_eq($pendingPaymentPayload['interactive']['action']['buttons'][0]['reply']['id']??null,'member:pay','Pending registration must offer one direct Pagar ahora button.');
member_ok(hache_sharky_member_pending_schedule_problem('Ese horario se me complica'),'Schedule questions must remain available instead of being trapped in payment.');

$transferState=['commercial_context'=>['_member_ops'=>['name'=>'member_payment_transfer','step'=>'evidence','updated_at'=>100]]];
$restartedState=hache_sharky_member_payment_restart_state($transferState,200);
member_eq(hache_sharky_member_flow($restartedState),null,'Reopening payment choice must clear a stale SPEI proof wait.');
$otherState=['commercial_context'=>['_member_ops'=>['name'=>'absence','step'=>'date','updated_at'=>100]]];
member_eq(hache_sharky_member_payment_restart_state($otherState,200),$otherState,'Reopening payment choice must not erase unrelated member flows.');

$classCtx=[
    'identity'=>['name'=>'Ariel Fernández'],
    'program'=>'regular',
    'student'=>['regular_inicio'=>'18:00:00'],
    'session_today'=>null,
    'today'=>'2026-09-07',
];
$classMessage=hache_sharky_member_class_message($classCtx);
member_ok(str_contains($classMessage,'sigue programada'),'Class status must answer the student directly when no cancellation exists.');
member_ok(!str_contains(mb_strtolower($classMessage),'backend'),'Student-facing class status must not mention backend internals.');

$externalMonthly=hache_sharky_member_payment_external('student-1','MENSUALIDAD','monthly-1');
$externalMonthlyAgain=hache_sharky_member_payment_external('student-1','MENSUALIDAD','monthly-1');
$externalIntensive=hache_sharky_member_payment_external('student-1','INTENSIVO','course-1');
member_eq($externalMonthly,$externalMonthlyAgain,'Payment external references must be deterministic for reconciliation.');
member_ok($externalMonthly!==$externalIntensive,'Monthly and intensive payments need separate external references.');
member_ok(!str_contains($externalMonthly,'student-1'),'External references must not leak student identifiers.');

$partialPayment=['kind'=>'intensive','course_id'=>'course-1','price'=>1200.0,'paid'=>400.0,'due'=>800.0,'pending'=>true];
member_ok(hache_sharky_member_payment_partial_intensive($partialPayment),'An intensive with a valid partial payment must be detected.');
$partialContext=['identity'=>['student_id'=>'student-1'],'payment'=>$partialPayment];
member_ok(is_array(hache_sharky_member_payment_pending_from_context($partialContext)),'A residual intensive balance must remain payable through the existing method selector.');
member_ok(str_contains(hache_sharky_member_payment_partial_message($partialPayment),'$800.00'),'Partial-payment answer must preserve the real remaining balance.');
$zeroPaidContext=['identity'=>['student_id'=>'student-1'],'payment'=>['kind'=>'intensive','course_id'=>'course-1','price'=>1200.0,'paid'=>0.0,'due'=>1200.0,'pending'=>true]];
member_ok(is_array(hache_sharky_member_payment_pending_from_context($zeroPaidContext)),'A zero-paid intensive may still open its first valid checkout.');

$methodPayload=hache_sharky_member_payment_method_payload('529981234567',5.0);
$methodButtons=$methodPayload['interactive']['action']['buttons']??[];
member_eq(array_column(array_column($methodButtons,'reply'),'id'),['member:pay:cash','member:pay:transfer','member:pay:card'],'Registered students must see cash first, then SPEI, then card.');
member_ok(str_contains((string)($methodPayload['interactive']['body']['text']??''),'opción preferencial'),'Cash must be presented as the preferred payment method.');

$pending=['student_id'=>'student-1','kind'=>'MENSUALIDAD','resource_id'=>'monthly-1','monthly_id'=>'monthly-1','intensive_id'=>null,'base'=>1200.0];
$transfer=hache_sharky_member_payment_transfer_payload('529981234567',$pending,[
    'sharky_pago_institucion'=>'Banco Demo','sharky_pago_beneficiario'=>'Hache Natación','sharky_pago_clabe'=>'123456789012345678',
]);
$transferText=(string)($transfer['text']['body']??'');
member_ok(str_contains($transferText,'$1,200.00 MXN')&&str_contains($transferText,'123456789012345678'),'SPEI option must show the real balance and configured bank details.');
member_ok(str_contains($transferText,'pendiente de verificación'),'Transfer proof must never validate a payment automatically.');
member_eq(HACHE_SHARKY_MEMBER_PAYMENT_PROOF_KIND,HACHE_SHARKY_MEMBER_EVIDENCE_KIND,'Transfer proof must stay in the member-owned media lane so webhook finalization cannot consume it early.');

$root=dirname(__DIR__);
$memberMigration=(string)file_get_contents($root.'/database/migrations/20260907_sharky_member_ops.sql');
member_ok(str_contains($memberMigration,'CREATE TABLE IF NOT EXISTS profesores'),'Professor registry migration is required.');
member_ok(str_contains($memberMigration,'profesor_horarios'),'Teacher cancellation must be scoped by assigned schedules.');
member_ok(str_contains($memberMigration,'sharky_ausencia_evidencias'),'Absence evidence metadata must be durable.');
$paymentMigration=(string)file_get_contents($root.'/database/migrations/20260907_sharky_member_payments.sql');
member_ok(str_contains($paymentMigration,'sharky_member_payment_intents'),'Registered-student checkout needs durable payment intents.');

$webhook=(string)file_get_contents($root.'/public/api/whatsapp-orchestrator-lab.php');
$memberRouter=(string)file_get_contents($root.'/config/sharky-member-routing.php');
member_ok(str_contains($webhook,'$memberOpsReady=hache_sharky_member_schema_ready($pdo)&&hache_sharky_member_payments_schema_ready($pdo)'),'Member operations must stay dormant until both additive schemas are present.');
member_ok(str_contains($webhook,'$memberEvidenceEvents=$memberOpsReady?hache_sharky_member_extract_media_events($pdo,$payload):[]'),'Member evidence extraction must stay dormant before migration.');
member_ok(str_contains($webhook,'$memberEvidenceIds[$memberEvidenceId]=true'),'Member media must reserve its Meta message receipt.');
member_ok(str_contains($webhook,'!isset($memberEvidenceIds[(string)($event[\'id\']??\'\')])'),'Generic proof events must be deduplicated against member-owned media IDs.');
member_ok(str_contains($webhook,'if($memberOpsReady){'),'Registered-member routing must be guarded by schema readiness at runtime.');
member_ok(str_contains($webhook,'hache_sharky_member_route_event'),'Webhook must delegate registered-member turns to the shared member router.');
member_ok(str_contains($memberRouter,'hache_sharky_member_payment_process_event'),'Shared member router must run payment self-service before general member operations.');
member_ok(str_contains($memberRouter,'hache_sharky_member_supported_event'),'Member operations must be explicitly gated before claiming an inbox event.');
$payments=(string)file_get_contents($root.'/config/sharky-member-payments.php');
member_ok(str_contains($payments,"SELECT id,importe FROM pagos WHERE alumno_id=:a AND intensivo_id=:i AND tipo='INTENSIVO' AND estado='VALIDO' FOR UPDATE"),'MP reconciliation must lock and sum prior intensive installments before adding another one.');
member_ok(str_contains($payments,'exceeds the remaining intensive balance'),'MP reconciliation must reject an overpayment race instead of blocking every second installment.');
member_ok(str_contains($payments,"'name'=>'member_payment_transfer','step'=>'evidence'"),'SPEI choice must arm a bounded member-owned proof flow.');
member_ok(str_contains($payments,"is_array(\$event['member_payment']??null)"),'Transfer proof must be consumed by the registered-student payment processor.');
$memberOps=(string)file_get_contents($root.'/config/sharky-member-ops.php');
member_ok(str_contains($memberOps,"['member_payment']=\$memberPayment"),'Member media extraction must bind transfer proof to the exact student payment context.');
member_ok(!str_contains($memberOps,'Sharky lo tomará del backend'),'Member copy must not expose backend internals.');
$api=(string)file_get_contents($root.'/api/profesores.php');
member_ok(str_contains($api,"auth_require(['ADMIN'])"),'Only administrators may register or assign professors.');
member_ok(str_contains($api,'auth_csrf_validate'),'Professor administration POSTs must validate CSRF.');
member_ok(str_contains($api,"'csrf'=>auth_csrf_token()"),'Professor administration GET must provide a session CSRF token.');
member_ok(str_contains($api,"accion:'")===false,'Professor API must not contain UI-side action literals.');
$professorPage=(string)file_get_contents($root.'/public/profesores.php');
member_ok(str_contains($professorPage,'csrf:model.csrf'),'Professor administration UI must send the current CSRF token on mutations.');
$status=(string)file_get_contents($root.'/bin/sharky-orchestrator-status.php');
member_ok(str_contains($status,"'member_ops'=>["),'Operational status must expose a member-ops readiness block.');
member_ok(str_contains($status,"'routing_ready'=>"),'Operational status must distinguish schema presence from live routing readiness.');
member_ok(str_contains($status,'missing_tables'),'Status must name missing schema objects without exposing PII.');

fwrite(STDOUT,"SHARKY_MEMBER_OPS_REGRESSION_OK\n");