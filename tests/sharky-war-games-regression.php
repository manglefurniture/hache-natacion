<?php

declare(strict_types=1);

require __DIR__.'/../config/sharky-whatsapp-adapter.php';
require_once __DIR__.'/../config/sharky-draft-parity.php';
require_once __DIR__.'/../config/sharky-outbox.php';

function expect_war(bool $ok,string $message):void{if(!$ok){fwrite(STDERR,"WAR FAIL: $message\n");exit(1);}}

$now=1788382800;

// P0: a delayed button from an earlier step must never confirm a later transaction.
$registrationConfirm=hache_sharky_orchestrator_flow(hache_sharky_orchestrator_state(null,$now),'register_intensive','confirm',['name'=>'Juan Pérez','fecha_inicio'=>'2026-09-07'],$now);
expect_war(!hache_sharky_whatsapp_interactive_is_current($registrationConfirm,['interactive_id'=>'flow:yes']),'A stale offer YES must not confirm registration.');
expect_war(hache_sharky_whatsapp_interactive_is_current($registrationConfirm,['interactive_id'=>'flow:confirm']),'Only the current final confirm button is valid.');
$absenceConfirm=hache_sharky_orchestrator_flow(hache_sharky_orchestrator_state(null,$now),'absence','confirm',['date_from'=>'2026-09-03'],$now);
expect_war(!hache_sharky_whatsapp_interactive_is_current($absenceConfirm,['interactive_id'=>'flow:yes']),'A stale absence-offer YES must not execute the absence.');
expect_war(hache_sharky_whatsapp_interactive_is_current($absenceConfirm,['interactive_id'=>'flow:confirm']),'Current absence confirmation remains valid.');
$registrationSede=hache_sharky_orchestrator_flow(hache_sharky_orchestrator_state(null,$now),'register_intensive','sede',[],$now);
expect_war(!hache_sharky_whatsapp_interactive_is_current($registrationSede,['interactive_id'=>'course:old-course']),'An old course list reply cannot skip the sede step.');
expect_war(hache_sharky_whatsapp_interactive_is_current($registrationSede,['interactive_id'=>'sede:monteverde']),'The current sede button remains valid.');

// P1: an empty backend option set must fail safely instead of trapping the chat in an empty list.
$courseState=hache_sharky_orchestrator_flow(hache_sharky_orchestrator_state(null,$now),'register_intensive','course',['sede_clave'=>'MONTEVERDE'],$now);
[$guardedState,$guardedDecision]=hache_sharky_whatsapp_empty_options_guard($courseState,hache_sharky_orchestrator_decision('registration_course','Elige fecha',['type'=>'list','options'=>[]]));
expect_war(($guardedDecision['kind']??'')==='options_unavailable_handoff','Empty course options must hand off safely.');
expect_war(($guardedDecision['action']['type']??'')==='human_takeover','Empty options must never ask the model to invent a choice.');
expect_war(($guardedState['flow']??null)===null,'Empty options must clear the unusable controlled flow.');

// P0: verification from an unknown number is temporary.
expect_war(hache_sharky_verification_expired('2026-09-02 18:00:00',strtotime('2026-09-02 19:00:00')),'Past verification sessions must expire.');
expect_war(!hache_sharky_verification_expired('2026-09-02 20:00:00',strtotime('2026-09-02 19:00:00')),'A fresh verified session remains valid.');
expect_war(HACHE_SHARKY_VERIFIED_SESSION_TTL>=900&&HACHE_SHARKY_VERIFIED_SESSION_TTL<=14400,'Verified-session TTL must stay bounded.');

// P1: sender identity cannot silently become a third party's student identity.
expect_war(hache_sharky_draft_third_party_registration('No quiero inscribirme yo, quiero inscribir a mi hijo al intensivo.'),'Third-party enrollment must leave automated new-student creation.');
expect_war(hache_sharky_draft_third_party_registration('Quiero registrar a mi esposa al curso.'),'Partner enrollment must be treated as third-party.');
expect_war(!hache_sharky_draft_third_party_registration('Quiero registrarme al intensivo.'),'Self enrollment must not be mislabeled as third-party.');

// Parity: frustration from #72 is a human-handoff condition too.
if(!function_exists('hache_sharky_frustration')){function hache_sharky_frustration(string $text):bool{return str_contains($text,'NO_ME_ENTIENDES');}}
expect_war(hache_sharky_draft_requires_handoff('NO_ME_ENTIENDES'),'Frustration must not get lost when moving from v2 to the orchestrator.');

$migration=file_get_contents(__DIR__.'/../database/migrations/20260902_sharky_orchestrator.sql')?:'';
$store=file_get_contents(__DIR__.'/../config/sharky-orchestrator-store.php')?:'';
foreach(['image_url','video_url','thumbnail_url','referral_json'] as $field){expect_war(str_contains($migration,$field),'Referral migration must include '.$field.'.');expect_war(str_contains($store,$field),'Referral store must persist '.$field.'.');}
$adapter=file_get_contents(__DIR__.'/../config/sharky-whatsapp-adapter.php')?:'';
expect_war(str_contains($adapter,'existing_student_intensive_handoff'),'Known students must not fall through the new-student intensive creator.');
expect_war(str_contains($adapter,'stale_interactive'),'Stale interactive replies must have an explicit no-op response.');

// P0 inbox recovery: ACK happens only after the normalized event is durably encrypted.
$inbox=file_get_contents(__DIR__.'/../config/sharky-inbox.php')?:'';
$webhook=file_get_contents(__DIR__.'/../public/api/whatsapp-orchestrator-lab.php')?:'';
expect_war(str_contains($migration,'payload_ciphertext MEDIUMTEXT NULL'),'Message receipts must carry an encrypted durable inbox payload.');
expect_war(str_contains($inbox,'aes-256-gcm'),'Inbound payloads must be authenticated-encrypted at rest.');
$persistPos=strpos($webhook,'hache_sharky_inbox_store');$ackPos=strpos($webhook,'http_response_code(200)');
expect_war($persistPos!==false&&$ackPos!==false&&$persistPos<$ackPos,'Webhook must persist the event before returning HTTP 200.');
expect_war(str_contains($migration,'lease_until'),'Message receipts need a reclaimable processing lease.');
expect_war(str_contains($migration,'attempt_count'),'Message receipts need attempt accounting.');
expect_war(str_contains($store,'lease_until<NOW()'),'Expired message claims must be reclaimable.');
expect_war(HACHE_SHARKY_MESSAGE_LEASE_SECONDS>=120&&HACHE_SHARKY_MESSAGE_LEASE_SECONDS<=600,'Message processing lease must be bounded.');

// P0 outbound recovery: successful business action + failed WhatsApp send remains retryable.
$outbox=file_get_contents(__DIR__.'/../config/sharky-outbox.php')?:'';
expect_war(str_contains($migration,'CREATE TABLE IF NOT EXISTS sharky_outbox'),'A durable outbox table is required.');
expect_war(str_contains($migration,'payload_ciphertext MEDIUMTEXT NOT NULL'),'Outbound payload must not be stored in plaintext.');
expect_war(str_contains($outbox,'aes-256-gcm'),'Outbox payloads must be authenticated-encrypted at rest.');
expect_war(str_contains($outbox,"status='PENDING'"),'Failed outbound messages must remain retryable.');
expect_war(str_contains($outbox,'lease_until'),'Concurrent outbox dispatchers need a send lease.');
expect_war(str_contains($outbox,'hache_sharky_outbox_claim($pdo,1)'),'Each outbound row must be claimed immediately before its send.');

// P0 transactional handoff: do not close the inbox receipt after DB commit until reply is durably queued.
$actionRecovery=file_get_contents(__DIR__.'/../config/sharky-action-recovery.php')?:'';
$batching=file_get_contents(__DIR__.'/../config/sharky-whatsapp-batching.php')?:'';
$worker=file_get_contents(__DIR__.'/../config/sharky-lab-worker.php')?:'';
$executor=file_get_contents(__DIR__.'/../config/sharky-orchestrator-db.php')?:'';
foreach(['source_message_id','delivery_queued_at','result_json','lease_until'] as $field)expect_war(str_contains($migration,$field),'Action audit must include '.$field.'.');
expect_war(str_contains($actionRecovery,'hache_sharky_action_delivery_pending_for_message'),'Completed actions must expose pending-delivery state.');
expect_war(str_contains($actionRecovery,'hache_sharky_action_delivery_queued_by_message'),'Durable reply queueing must be recorded on the action.');
expect_war(str_contains($store,'hache_sharky_action_delivery_pending_for_message'),'Message receipt cannot close while a completed action lacks durable delivery.');
expect_war(str_contains($batching,'hache_sharky_action_delivery_pending_for_message'),'Batched receipts must honor transactional delivery pending state.');
expect_war(str_contains($worker,'hache_sharky_lab_queue_and_complete'),'Worker must close action delivery only inside the durable queue transaction.');
$queuePos=strpos($worker,'hache_sharky_outbox_enqueue');$receiptPos=strpos($worker,'hache_sharky_orchestrator_mark_processed',$queuePos===false?0:$queuePos);
expect_war($queuePos!==false&&$receiptPos!==false&&$queuePos<$receiptPos,'Outbox persistence must happen before receipt completion.');
expect_war(str_contains($worker,"require_once __DIR__.'/sharky-inbox.php'"),'Worker must load durable inbox helpers directly.');

// Final concurrency pass: keep one contact serialized through state + outbox + receipts.
expect_war(str_contains($store,'function hache_sharky_orchestrator_delivery_lock'),'A delivery lock per contact is required.');
expect_war(str_contains($batching,"'defer_delivery_unlock'"),'Batching must be able to transfer the delivery lock to the worker.');
expect_war(str_contains($worker,"'defer_delivery_unlock'=>true"),'Worker must hold the delivery lock through the durable boundary.');
expect_war(str_contains($worker,'$result[\'_delivery_lock\']??null')&&str_contains($worker,'hache_sharky_lab_release_delivery_lock($deliveryLock)'),'Worker must release the transferred lock after its durable boundary.');

// Final batching pass: text can be capped, but every claimed receipt ID must complete with the batch.
expect_war(str_contains($store,"'receipt_ids'=>[]"),'Batch storage must track all claimed receipt IDs separately from capped text events.');
expect_war(str_contains($store,'$stored[\'receipt_ids\'][]=$eventId'),'Every claimed event ID must be retained.');
expect_war(str_contains($store,'$stored[\'events\']=array_slice($stored[\'events\'],-8)')&&str_contains($store,'array_merge(is_array($stored[\'receipt_ids\']'),'Capping visible batch text must not discard receipt completion IDs.');

// Final takeover pass: persist human control before any pending automatic dispatch.
$preflightTakeover=strpos($worker,'hache_sharky_takeover_mark($contact,$reason,$summary)');
$preflightQueue=strpos($worker,'hache_sharky_lab_queue_and_complete($pdo,$contact,$payload',$preflightTakeover===false?0:$preflightTakeover);
expect_war($preflightTakeover!==false&&$preflightQueue!==false&&$preflightTakeover<$preflightQueue,'Preflight handoff must persist takeover before queue/dispatch.');
expect_war(str_contains($worker,'hache_sharky_outbox_allow_during_takeover'),'Only the handoff notice may bypass takeover cancellation.');
expect_war(str_contains($outbox,'unset($payload[\'_sharky_allow_takeover\'])'),'Internal takeover bypass flag must never be sent to Meta.');

// Second hardening pass: a persisted takeover cannot swallow its own failed handoff notice.
expect_war(str_contains($migration,'handoff_pending_at DATETIME NULL'),'Inbox must persist a recoverable handoff-pending marker.');
expect_war(str_contains($inbox,'function hache_sharky_inbox_mark_handoff_pending'),'Inbox needs a durable handoff-pending writer.');
expect_war(str_contains($inbox,'function hache_sharky_inbox_handoff_pending'),'Replay must be able to identify a pending handoff notice.');
$pendingPos=strpos($worker,'hache_sharky_lab_mark_handoff_pending($pdo,$eventId)');
$takeoverPos=strpos($worker,'hache_sharky_takeover_mark($contact,$reason,$summary)');
expect_war($pendingPos!==false&&$takeoverPos!==false&&$pendingPos<$takeoverPos,'Preflight handoff must become recoverable before takeover is persisted.');
expect_war(str_contains($worker,'hache_sharky_takeover_active($contact)&&!$handoffPending'),'A pending handoff replay must bypass ordinary takeover swallowing.');

// Second hardening pass: manual echoes beat opportunistic outbound recovery in the entrypoint.
$processingPos=strpos($webhook,'$processing=array_merge($echoes,$events)');
$finalDispatchPos=strrpos($webhook,"hache_sharky_outbox_dispatch(\$pdo,'hache_sharky_lab_send',20)");
expect_war($processingPos!==false&&$finalDispatchPos!==false&&$processingPos<$finalDispatchPos,'Webhook must process echoes before its opportunistic outbox dispatch.');

// Second hardening pass: takeover revalidation and Meta send share the delivery lock.
expect_war(str_contains($outbox,'string $lockedContact=\'\''),'Outbox dispatcher must accept proof of an already-held contact lock.');
expect_war(str_contains($outbox,'hache_sharky_orchestrator_delivery_lock($contact)'),'CLI/outside dispatchers must acquire the per-contact delivery lock.');
expect_war(str_contains($outbox,'$callerOwnsLock=$lockedContact!==\'\'&&hash_equals($lockedContact,$contact)'),'Worker-owned locks must avoid self-deadlock while preserving serialization.');
expect_war(str_contains($worker,"hache_sharky_outbox_dispatch(\$pdo,'hache_sharky_lab_send',10,\$contact)"),'Worker dispatch must declare the delivery lock it already owns.');
expect_war(str_contains($batching,'hache_sharky_takeover_active($contact)'),'A text leaving the debounce window must revalidate takeover under the delivery lock.');

// Second hardening pass: expired action owners cannot finalize a lease stolen by another worker.
expect_war(str_contains($migration,'owner_token CHAR(48) NULL'),'Action audit needs an ownership fence token.');
expect_war(str_contains($actionRecovery,'owner_token=:o'),'Action reclaim must install a fresh ownership token.');
expect_war(str_contains($actionRecovery,"AND owner_token=:o"),'Action finalization must require the current owner token.');
expect_war(str_contains($actionRecovery,'owner_token=NULL'),'Terminal action rows must release ownership.');
expect_war(str_contains($executor,'$ownerToken=null')&&str_contains($executor,'$ownerToken))return hache_sharky_action_audit_pending_result()'),'Executor must carry the claim token into finalization.');

// Final timezone pass: conversational dates use the same Cancun operational day as business rules.
expect_war(str_contains($adapter,"new DateTimeZone('America/Cancun')"),'Adapter must calculate today explicitly in Cancun.');
expect_war(str_contains($worker,'hache_sharky_lab_today()'),'Worker must inject Cancun operational today.');
expect_war(str_contains($adapter,'hache_sharky_start_authority_intensive_date_allowed'),'Displayed intensive options must respect Sharky Monday-only authority.');

// Final audit pass: business commit is not deliverable until audit is terminal.
expect_war(str_contains($actionRecovery,'function hache_sharky_action_recovery_finish')&&str_contains($actionRecovery,'): bool'),'Action audit finish must be observable.');
expect_war(str_contains($actionRecovery,"status='PENDING' OR (status='COMPLETED' AND delivery_queued_at IS NULL)"),'PENDING audit rows must block receipt completion.');
expect_war(str_contains($executor,'ACTION_AUDIT_PENDING'),'Executor must fail closed when audit finalization is not durable.');
expect_war(str_contains($executor,'if(!hache_sharky_action_recovery_finish'),'Executor must verify audit finalization before reporting success.');

// P1 privacy: contact identifiers use keyed HMAC rather than enumerable plain SHA-256.
putenv('SHARKY_CONTACT_HASH_KEY=0123456789abcdef0123456789abcdef');
$hash1=hache_sharky_orchestrator_contact_hash('+52 998 111 2233');
$hash2=hache_sharky_orchestrator_contact_hash('529981112233');
expect_war($hash1===$hash2,'Phone formatting must normalize to the same HMAC.');
expect_war($hash1===hash_hmac('sha256','hache-sharky-contact-v3|529981112233','0123456789abcdef0123456789abcdef'),'Contact hash must be keyed HMAC with domain separation.');

fwrite(STDOUT,"SHARKY_WAR_GAMES_OK\n");
