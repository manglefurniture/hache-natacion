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
sharky_entry_expect(str_contains($routing,'function hache_sharky_member_palapas_restricted_route'),'Palapas must have an explicit restricted member route.');
sharky_entry_expect(str_contains($routing,"strtoupper((string)(\$identity['sede_clave']??''))!=='PALAPAS'"),'Restriction must be scoped only to Palapas.');
$palapasCall=strpos($routing,'hache_sharky_member_palapas_restricted_route($pdo,$event)');
$paymentCall=strpos($routing,'hache_sharky_member_payment_process_event($pdo,$event,$business)');
sharky_entry_expect($palapasCall!==false&&$paymentCall!==false&&$palapasCall<$paymentCall,'Palapas restriction must execute before member payments.');
sharky_entry_expect(str_contains($routing,"['id'=>'member:class_today','title'=>'Mi clase hoy']"),'Palapas menu must keep class-today access.');
sharky_entry_expect(str_contains($routing,'Por ahora los temas de pagos de Palapas los está revisando directamente el equipo de Hache.'),'Palapas accounting requests must be answered without balances or checkout.');

// This package must not rewrite the already-approved Brain runtime.
sharky_entry_expect(str_contains($brain,"if(\$kind==='conversation_identity_prompt')return 'ask_identity';"),'Brain shadow contract remains present and untouched by this package.');

fwrite(STDOUT,"Sharky entry/Palapas/email regression: OK\n");
