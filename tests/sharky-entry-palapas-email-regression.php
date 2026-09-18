<?php

declare(strict_types=1);

function sharky_entry_expect(bool $condition,string $message): void
{
    if(!$condition){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}
}

$root=dirname(__DIR__);
$webhook=(string)file_get_contents($root.'/public/api/whatsapp-orchestrator-lab.php');
$worker=(string)file_get_contents($root.'/bin/sharky-inbox-dispatch.php');
$opportunities=(string)file_get_contents($root.'/config/sharky-prospect-opportunities.php');
$metaFlow=(string)file_get_contents($root.'/config/sharky-meta-ad-flow.php');
$inbox=(string)file_get_contents($root.'/config/sharky-inbox.php');
$routing=(string)file_get_contents($root.'/config/sharky-member-routing.php');
$brain=(string)file_get_contents($root.'/config/sharky-brain-shadow-runtime.php');

// Unknown WhatsApp numbers are prospects by default. We deliberately keep
// verified=false so a later "soy alumno" can still enter the existing identity
// verification flow instead of being treated as authenticated.
sharky_entry_expect(str_contains($webhook,'function sharky_lab_assume_unmatched_prospect'),'Live webhook must own the unmatched-contact default.');
sharky_entry_expect(str_contains($opportunities,"'kind'=>'prospect'"),'Shared unmatched-contact boundary must start unknown contacts as prospects.');
sharky_entry_expect(str_contains($opportunities,"'verified'=>false"),'Automatic prospect assumption must not become authentication.');
sharky_entry_expect(str_contains($opportunities,"'source'=>'whatsapp_unmatched'"),'Automatic prospect assumption must remain auditable.');
sharky_entry_expect(strpos($webhook,'sharky_lab_assume_unmatched_prospect')<strpos($webhook,'hache_sharky_human_process_event'),'Prospect assumption must happen before the supervised general orchestrator runs.');
sharky_entry_expect(str_contains($webhook,"require_once __DIR__.'/../../config/sharky-prospect-opportunities.php'"),'Live prospect entry must load the F6 opportunity producer.');
$assumeStart=strpos($webhook,'function sharky_lab_assume_unmatched_prospect');
$assumeEnd=strpos($webhook,'function sharky_lab_notify_registration_transition',$assumeStart?:0);
sharky_entry_expect($assumeStart!==false&&$assumeEnd!==false,'Unmatched prospect entry wrapper must remain bounded.');
$assumeBlock=substr($webhook,$assumeStart,$assumeEnd-$assumeStart);
sharky_entry_expect(str_contains($assumeBlock,'hache_sharky_prospect_opportunity_prepare_unmatched'),'Live webhook must delegate to the shared durable first-turn boundary.');
sharky_entry_expect(str_contains($webhook,'if(!sharky_lab_assume_unmatched_prospect($pdo,$event,$identityBefore))continue;'),'Live producer failure must leave the durable inbox receipt pending for recovery.');

$guidedPos=strpos($opportunities,'hache_sharky_entry_guided_first_prospect');
$opportunityPos=strpos($opportunities,'hache_sharky_prospect_opportunity_open(',$guidedPos===false?0:$guidedPos);
$stateSavePos=strpos($opportunities,'hache_sharky_db_state_save',$opportunityPos===false?0:$opportunityPos);
sharky_entry_expect($guidedPos!==false&&$opportunityPos!==false&&$stateSavePos!==false&&$guidedPos<$opportunityPos&&$opportunityPos<$stateSavePos,'Shared producer must resolve the first-entry source, persist the opportunity, then save prospect state.');
sharky_entry_expect(str_contains($opportunities,"(string)(\$event['id']??'')"),'Opportunity idempotency must derive from the durable inbound message id.');
sharky_entry_expect(str_contains($opportunities,'hache_sharky_orchestrator_contact_hash($contact)'),'Opportunity producer must receive only the contact hash, never the raw WhatsApp number.');
sharky_entry_expect(str_contains($opportunities,'if($opportunityId===null)')&&str_contains($opportunities,'return false;'),'Opportunity persistence failure must fail closed so the receipt is retried.');
sharky_entry_expect(str_contains($worker,"require_once __DIR__.'/../config/sharky-prospect-opportunities.php'"),'Inbox recovery must load the shared opportunity producer.');
$recoveryReconcile=strpos($worker,'hache_sharky_prospect_opportunity_reconcile_durable_student($pdo,$event,null)');
$recoveryMember=strpos($worker,'hache_sharky_member_route_event($pdo,$event,$business)',$recoveryReconcile===false?0:$recoveryReconcile);
$recoveryProducer=strpos($worker,'hache_sharky_prospect_opportunity_prepare_unmatched($pdo,$event,null)',$recoveryMember===false?0:$recoveryMember);
$recoveryHuman=strpos($worker,'hache_sharky_human_process_event($pdo,$event',$recoveryProducer===false?0:$recoveryProducer);
sharky_entry_expect($recoveryReconcile!==false&&$recoveryMember!==false&&$recoveryReconcile<$recoveryMember,'Inbox recovery must reconcile durable student identity before member routing can return early.');
sharky_entry_expect($recoveryProducer!==false&&$recoveryHuman!==false&&$recoveryProducer<$recoveryHuman,'Inbox recovery must cross the prospect-creation boundary before completing generic Sharky processing.');
sharky_entry_expect(str_contains($worker,'if(!hache_sharky_prospect_opportunity_reconcile_durable_student($pdo,$event,null))return false;'),'Recovery exclusion failure must defer the inbox receipt.');
sharky_entry_expect(str_contains($worker,'if(!hache_sharky_prospect_opportunity_prepare_unmatched($pdo,$event,null))return false;'),'Recovery producer failure must defer the inbox receipt instead of losing the opportunity.');

$liveReconcile=strpos($webhook,'hache_sharky_prospect_opportunity_reconcile_durable_student($pdo,$event,$identityBefore)');
$liveMember=strpos($webhook,'hache_sharky_member_route_event($pdo,$event,$business)',$liveReconcile===false?0:$liveReconcile);
sharky_entry_expect($liveReconcile!==false&&$liveMember!==false&&$liveReconcile<$liveMember,'Live webhook must reconcile durable student identity before member routing can continue early.');

$reconcileStart=strpos($opportunities,'function hache_sharky_prospect_opportunity_reconcile_durable_student');
$reconcileEnd=strpos($opportunities,'function hache_sharky_prospect_opportunity_resolve_open_id',$reconcileStart===false?0:$reconcileStart);
sharky_entry_expect($reconcileStart!==false&&$reconcileEnd!==false,'Durable student reconciliation helper must remain bounded.');
$reconcileBlock=substr($opportunities,$reconcileStart,$reconcileEnd-$reconcileStart);
sharky_entry_expect(str_contains($reconcileBlock,'hache_sharky_prospect_opportunity_exclude_durable_student'),'Pre-routing reconciliation must delegate to the exact-UUID exclusion helper.');
sharky_entry_expect(str_contains($reconcileBlock,'return false;'),'Pre-routing identity/exclusion failures must remain retryable.');

$knownStudentBranch=strpos($opportunities,"if((\$identityBefore['found']??false)===true){");
$deliveryLockPos=strpos($opportunities,'$deliveryLock=hache_sharky_orchestrator_delivery_lock($contact);',$knownStudentBranch===false?0:$knownStudentBranch);
sharky_entry_expect($knownStudentBranch!==false&&$deliveryLockPos!==false&&$knownStudentBranch<$deliveryLockPos,'Durable known-student identity must be handled before prospect creation.');
$knownStudentBlock=substr($opportunities,$knownStudentBranch,$deliveryLockPos-$knownStudentBranch);
sharky_entry_expect(str_contains($knownStudentBlock,'hache_sharky_prospect_opportunity_reconcile_durable_student'),'Known students reaching prospect preparation must reuse pre-routing reconciliation.');

sharky_entry_expect(str_contains($opportunities,"\$state['commercial_context']['f6_opportunity_id']=\$opportunityId;"),'First-turn producer must keep the exact opportunity id only inside encrypted Sharky state.');
sharky_entry_expect(str_contains($opportunities,'function hache_sharky_prospect_opportunity_enrich_sede'),'F6 must expose a narrow structured-venue enrichment helper.');
sharky_entry_expect(str_contains($opportunities,"throw new RuntimeException('F6 opportunity storage unavailable for structured venue enrichment')"),'Unavailable venue storage must fail closed so the durable receipt can retry.');
sharky_entry_expect(str_contains($opportunities,"throw new RuntimeException('Unable to persist structured opportunity venue',0,\$e)"),'Transient venue persistence failures must propagate instead of being reduced to a logged false.');
$metaEnrichStart=strpos($metaFlow,'function hache_sharky_meta_enrich_opportunity_venue');
$metaEnrichEnd=strpos($metaFlow,'function hache_sharky_meta_handle',$metaEnrichStart===false?0:$metaEnrichStart);
sharky_entry_expect($metaEnrichStart!==false&&$metaEnrichEnd!==false,'Meta venue enrichment wrapper must remain bounded.');
$metaEnrichBlock=substr($metaFlow,$metaEnrichStart,$metaEnrichEnd-$metaEnrichStart);
sharky_entry_expect(!str_contains($metaEnrichBlock,'catch('),'Meta venue enrichment wrapper must not swallow retryable persistence exceptions.');
sharky_entry_expect(str_contains($metaFlow,"require_once __DIR__.'/sharky-prospect-opportunities.php';"),'The closed Meta/web/direct funnel must load the venue-enrichment sidecar explicitly.');
sharky_entry_expect(substr_count($metaFlow,'hache_sharky_meta_enrich_opportunity_venue($pdo,$state,$event);')===2,'Only canonical venue selection and Ver otra sede may trigger opportunity venue enrichment.');
$venueStepPos=strpos($metaFlow,"if(\$step==='venue'){");
$venueSetPos=strpos($metaFlow,"\$state['commercial_context']['sede_clave']=\$sede;",$venueStepPos===false?0:$venueStepPos);
$venueEnrichPos=strpos($metaFlow,'hache_sharky_meta_enrich_opportunity_venue($pdo,$state,$event);',$venueSetPos===false?0:$venueSetPos);
sharky_entry_expect($venueStepPos!==false&&$venueSetPos!==false&&$venueEnrichPos!==false&&$venueStepPos<$venueSetPos&&$venueSetPos<$venueEnrichPos,'Canonical venue state must be written before analytics enrichment.');
$otherPos=strpos($metaFlow,"if(\$id==='meta:venue:other')");
$otherSetPos=strpos($metaFlow,"\$state['commercial_context']['sede_clave']=\$other;",$otherPos===false?0:$otherPos);
$otherEnrichPos=strpos($metaFlow,'hache_sharky_meta_enrich_opportunity_venue($pdo,$state,$event);',$otherSetPos===false?0:$otherSetPos);
sharky_entry_expect($otherPos!==false&&$otherSetPos!==false&&$otherEnrichPos!==false&&$otherPos<$otherSetPos&&$otherSetPos<$otherEnrichPos,'Ver otra sede must update the same structured state before enriching the active opportunity.');


// Sharky-created intensive registrations must reuse the same Resend alert only
// after the transactional action has had a chance to commit.
sharky_entry_expect(str_contains($webhook,"require_once __DIR__.'/../../config/notificaciones-email.php'"),'Webhook must load the canonical registration email sender.');
sharky_entry_expect(str_contains($webhook,'function sharky_lab_notify_registration_transition'),'Webhook must detect a new Sharky registration transition.');
sharky_entry_expect(str_contains($webhook,"Registro conversacional Sharky INTENSIVO."),'Email trigger must be scoped to Sharky-created intensive records.');
sharky_entry_expect(str_contains($webhook,'hache_notificar_nueva_inscripcion'),'Sharky registration must call the canonical notification function.');
$genericProcess=strpos($webhook,'hache_sharky_human_process_event($pdo,$event,$business,$minAge,$escalationThreshold);');
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
sharky_entry_expect(!str_contains($palapasBlock,"['id'=>'member:portal'"),'Palapas red light must not expose portal access while the portal still permits absence mutations.');
sharky_entry_expect(!str_contains($palapasBlock,'hache_sharky_member_portal_requested'),'Portal requests must remain inside the Palapas restriction instead of bypassing it.');
sharky_entry_expect(str_contains($palapasBlock,'Por ahora los temas de pagos de Palapas los está revisando directamente el equipo de Hache.'),'Palapas accounting requests must be answered without balances or checkout.');

// A professor who is also a Palapas student may bypass the gate only for
// teacher-owned controls or the free-text cancellation reason.
$teacherOwnerStart=strpos($routing,'function hache_sharky_member_teacher_owned_event');
$teacherOwnerEnd=strpos($routing,'function hache_sharky_member_palapas_restricted_route',$teacherOwnerStart?:0);
sharky_entry_expect($teacherOwnerStart!==false&&$teacherOwnerEnd!==false,'Teacher ownership helper must exist before the Palapas route.');
$teacherOwner=substr($routing,$teacherOwnerStart,$teacherOwnerEnd-$teacherOwnerStart);
sharky_entry_expect(!str_contains($teacherOwner,'hache_sharky_member_coteaching_ready'),'Teacher event ownership must remain independent from co-teaching readiness.');
sharky_entry_expect(str_contains($teacherOwner,"(\$flow['name']??'')!=='teacher_cancel'"),'Free text bypass must be limited to teacher_cancel.');
sharky_entry_expect(str_contains($teacherOwner,"(\$flow['step']??'')!=='reason'"),'Only the cancellation reason step may own free text.');
sharky_entry_expect(str_contains($teacherOwner,"trim((string)(\$event['interactive_id']??''))!==''"),'Interactive member buttons must never inherit teacher-flow ownership.');
sharky_entry_expect(str_contains($teacherOwner,"return \$intent!=='payments';"),'Payment-like text must remain behind the Palapas red-light gate.');
sharky_entry_expect(str_contains($palapasBlock,'hache_sharky_member_teacher_owned_event($pdo,$teacher,$routingFlow,$event,$intent)'),'Palapas gate must bypass on teacher ownership before any student restriction is allowed to claim the event.');

// An explicit human request containing a payment word must escape member-ops
// before its payment parser can expose a balance.
$routeStart=strpos($routing,'function hache_sharky_member_route_event');
sharky_entry_expect($routeStart!==false,'Shared member route must exist.');
$routeBlock=substr($routing,$routeStart);
$routeHandoff=strpos($routeBlock,'hache_sharky_member_routing_handoff_requested((string)($event[\'text\']??\'\'))');
$routePayment=strpos($routeBlock,'hache_sharky_member_payment_process_event($pdo,$event,$business)');
sharky_entry_expect($routeHandoff!==false&&$routePayment!==false&&$routeHandoff<$routePayment,'Palapas human handoff must escape before payment processing.');

// Teacher ownership and readiness are separate concerns. An owned teacher turn
// with incomplete co-teaching must return a non-null false result before Palapas,
// payments or either student fallback so neither caller can enter generic Sharky.
$teacherPreflight=strpos($routeBlock,'$teacherPreState=hache_sharky_db_state_load($pdo,$contact)');
$teacherDeferred=strpos($routeBlock,'hache_sharky_member_teacher_owned_event($pdo,$teacherPre,$teacherPreFlow,$event,$teacherPreIntent)&&!hache_sharky_member_coteaching_ready($pdo))return false;');
$palapasRoute=strpos($routeBlock,'hache_sharky_member_palapas_restricted_route($pdo,$event)');
$routePortal=strpos($routeBlock,'if(hache_sharky_member_portal_requested');
$routeDeterministic=strpos($routeBlock,'if(hache_sharky_member_deterministic_event($pdo,$event,$state))');
$pendingStudentFallback=strpos($routeBlock,'$student=hache_sharky_member_student_context($pdo,$contact);');
$activeStudentFallback=strrpos($routeBlock,'hache_sharky_member_student_fallback($pdo,$event);');
sharky_entry_expect($teacherPreflight!==false&&$teacherDeferred!==false&&$teacherPreflight<$teacherDeferred,'Shared router must preflight teacher ownership independently of readiness.');
sharky_entry_expect($palapasRoute!==false&&$teacherDeferred<$palapasRoute&&$teacherDeferred<$routePayment,'Unready teacher events must defer before Palapas and payment routing.');
sharky_entry_expect($routePortal!==false&&$palapasRoute<$routePortal&&$routePortal<$routePayment,'Portal requests must be handled after the Palapas red light but before payment-flow ownership.');
sharky_entry_expect($routeDeterministic!==false&&$routePortal<$routeDeterministic,'Portal requests must preempt an active absence-flow deterministic dispatch.');
sharky_entry_expect($pendingStudentFallback!==false&&$teacherDeferred<$pendingStudentFallback,'Unready teacher events must defer before pending-student fallback.');
sharky_entry_expect($activeStudentFallback!==false&&$teacherDeferred<$activeStudentFallback,'Unready teacher events must defer before active-student fallback.');
sharky_entry_expect(str_contains($routeBlock,'if($teacherOwned&&!hache_sharky_member_coteaching_ready($pdo))return false;'),'Late teacher readiness guard must also return a non-null deferred result.');
sharky_entry_expect(str_contains($webhook,'if($member!==null)continue;'),'Realtime webhook must treat false member results as terminal for this pass instead of falling into generic Sharky.');
sharky_entry_expect(str_contains($worker,'if($member!==null)return $member;'),'Inbox recovery must propagate false member results instead of falling into generic Sharky.');
sharky_entry_expect(str_contains($inbox,'$done=$processor($event)===true')&&str_contains($inbox,"else\$stats['deferred']++"),'Inbox dispatcher must keep false processor results pending for retry.');

// A merely PENDIENTE record is identifiable but must never receive a positive
// class-today answer as though its enrollment were active.
$pendingPos=strpos($palapasBlock,'hache_sharky_member_pending_registration($student)');
$classPos=strpos($palapasBlock,"if(\$intent==='class_today')");
sharky_entry_expect($pendingPos!==false&&$classPos!==false&&$pendingPos<$classPos,'Pending-registration guard must run before Palapas class-today replies.');
sharky_entry_expect(str_contains($palapasBlock,'no puedo confirmar una clase activa'),'Pending Palapas records need a neutral non-active-class reply.');

// This package must not rewrite the already-approved Brain runtime.
sharky_entry_expect(str_contains($brain,"if(\$kind==='conversation_identity_prompt')return 'ask_identity';"),'Brain shadow contract remains present and untouched by this package.');

fwrite(STDOUT,"Sharky entry/Palapas/email regression: OK\n");
