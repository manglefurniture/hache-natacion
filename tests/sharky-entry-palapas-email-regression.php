<?php

declare(strict_types=1);

function sharky_entry_expect(bool $condition,string $message): void
{
    if(!$condition){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}
}

$root=dirname(__DIR__);
$webhook=(string)file_get_contents($root.'/public/api/whatsapp-orchestrator-lab.php');
$routing=(string)file_get_contents($root.'/config/sharky-member-routing.php');
$brain=(string)file_get_contents($root.'/config/sharky-brain-shadow-runtime.php');

// Unknown WhatsApp numbers are prospects by default. We deliberately keep
// verified=false so a later "soy alumno" can still enter the existing identity
// verification flow instead of being treated as authenticated.
sharky_entry_expect(str_contains($webhook,'function sharky_lab_assume_unmatched_prospect'),'Live webhook must own the unmatched-contact default.');
sharky_entry_expect(str_contains($webhook,"'kind'=>'prospect'"),'Unmatched contacts must start as prospects.');
sharky_entry_expect(str_contains($webhook,"'verified'=>false"),'Automatic prospect assumption must not become authentication.');
sharky_entry_expect(str_contains($webhook,"'source'=>'whatsapp_unmatched'"),'Automatic prospect assumption must remain auditable.');
sharky_entry_expect(strpos($webhook,'sharky_lab_assume_unmatched_prospect')<strpos($webhook,'hache_sharky_lab_process_event'),'Prospect assumption must happen before the general orchestrator runs.');

// Sharky-created intensive registrations must reuse the same Resend alert only
// after the transactional action has had a chance to commit.
sharky_entry_expect(str_contains($webhook,"require_once __DIR__.'/../../config/notificaciones-email.php'"),'Webhook must load the canonical registration email sender.');
sharky_entry_expect(str_contains($webhook,'function sharky_lab_notify_registration_transition'),'Webhook must detect a new Sharky registration transition.');
sharky_entry_expect(str_contains($webhook,"Registro conversacional Sharky INTENSIVO."),'Email trigger must be scoped to Sharky-created intensive records.');
sharky_entry_expect(str_contains($webhook,'hache_notificar_nueva_inscripcion'),'Sharky registration must call the canonical notification function.');
$genericProcess=strpos($webhook,'hache_sharky_lab_process_event($pdo,$event,$business,$minAge,$escalationThreshold);');
$genericMail=strpos($webhook,'sharky_lab_notify_registration_transition($pdo,$event,$identityBefore);',$genericProcess?:0);
sharky_entry_expect($genericProcess!==false&&$genericMail!==false&&$genericProcess<$genericMail,'General registration email must run after processing/commit.');

// Palapas red light: identity stays available, but the route intercepts before
// payments and exposes only the "Mi clase hoy" self-service action.
$palapasStart=strpos($routing,'function hache_sharky_member_palapas_restricted_route');
$palapasEnd=strpos($routing,'function hache_sharky_member_deterministic_event',$palapasStart?:0);
sharky_entry_expect($palapasStart!==false&&$palapasEnd!==false,'Palapas must have an explicit restricted member route.');
$palapasBlock=substr($routing,$palapasStart,$palapasEnd-$palapasStart);
sharky_entry_expect(str_contains($palapasBlock,"strtoupper((string)(\$identity['sede_clave']??''))!=='PALAPAS'"),'Restriction must be scoped only to Palapas.');
sharky_entry_expect(str_contains($palapasBlock,"['id'=>'member:class_today','title'=>'Mi clase hoy']"),'Palapas menu must keep class-today access.');
sharky_entry_expect(str_contains($palapasBlock,'Por ahora los temas de pagos de Palapas los está revisando directamente el equipo de Hache.'),'Palapas accounting requests must be answered without balances or checkout.');

// Codex P1 follow-up: a professor who is also a Palapas student may bypass the
// gate only for teacher-owned controls or the free-text cancellation reason.
$teacherOwnerStart=strpos($routing,'function hache_sharky_member_teacher_owned_event');
$teacherOwnerEnd=strpos($routing,'function hache_sharky_member_palapas_restricted_route',$teacherOwnerStart?:0);
sharky_entry_expect($teacherOwnerStart!==false&&$teacherOwnerEnd!==false,'Teacher ownership helper must exist before the Palapas route.');
$teacherOwner=substr($routing,$teacherOwnerStart,$teacherOwnerEnd-$teacherOwnerStart);
sharky_entry_expect(str_contains($teacherOwner,"(\$flow['name']??'')!=='teacher_cancel'"),'Free text bypass must be limited to teacher_cancel.');
sharky_entry_expect(str_contains($teacherOwner,"(\$flow['step']??'')!=='reason'"),'Only the cancellation reason step may own free text.');
sharky_entry_expect(str_contains($teacherOwner,"trim((string)(\$event['interactive_id']??''))!==''"),'Interactive member buttons must never inherit teacher-flow ownership.');
sharky_entry_expect(str_contains($teacherOwner,"return \$intent!=='payments';"),'Payment-like text must remain behind the Palapas red-light gate.');
sharky_entry_expect(str_contains($palapasBlock,'hache_sharky_member_teacher_owned_event($teacher,$routingFlow,$event,$intent)'),'Palapas gate must use the narrow teacher ownership helper.');

// Codex P1: an explicit human request containing a payment word must escape
// member-ops before its payment parser can expose a balance.
$routeStart=strpos($routing,'function hache_sharky_member_route_event');
sharky_entry_expect($routeStart!==false,'Shared member route must exist.');
$routeBlock=substr($routing,$routeStart);
$routeHandoff=strpos($routeBlock,'hache_sharky_member_routing_handoff_requested((string)($event[\'text\']??\'\'))');
$routePayment=strpos($routeBlock,'hache_sharky_member_payment_process_event($pdo,$event,$business)');
sharky_entry_expect($routeHandoff!==false&&$routePayment!==false&&$routeHandoff<$routePayment,'Palapas human handoff must escape before payment processing.');

// Codex P2: a merely PENDIENTE record is identifiable but must never receive a
// positive class-today answer as though its enrollment were active.
$pendingPos=strpos($palapasBlock,'hache_sharky_member_pending_registration($student)');
$classPos=strpos($palapasBlock,"if(\$intent==='class_today')");
sharky_entry_expect($pendingPos!==false&&$classPos!==false&&$pendingPos<$classPos,'Pending-registration guard must run before Palapas class-today replies.');
sharky_entry_expect(str_contains($palapasBlock,'no puedo confirmar una clase activa'),'Pending Palapas records need a neutral non-active-class reply.');

// This package must not rewrite the already-approved Brain runtime.
sharky_entry_expect(str_contains($brain,"if(\$kind==='conversation_identity_prompt')return 'ask_identity';"),'Brain shadow contract remains present and untouched by this package.');

fwrite(STDOUT,"Sharky entry/Palapas/email regression: OK\n");
