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

// P0 transactional handoff: do not close the inbox receipt after DB commit until reply is durably queued.
$actionRecovery=file_get_contents(__DIR__.'/../config/sharky-action-recovery.php')?:'';
$batching=file_get_contents(__DIR__.'/../config/sharky-whatsapp-batching.php')?:'';
$worker=file_get_contents(__DIR__.'/../config/sharky-lab-worker.php')?:'';
foreach(['source_message_id','delivery_queued_at','result_json','lease_until'] as $field)expect_war(str_contains($migration,$field),'Action audit must include '.$field.'.');
expect_war(str_contains($actionRecovery,'hache_sharky_action_delivery_pending_for_message'),'Completed actions must expose pending-delivery state.');
expect_war(str_contains($actionRecovery,'hache_sharky_action_delivery_queued_by_message'),'Durable reply queueing must be recorded on the action.');
expect_war(str_contains($store,'hache_sharky_action_delivery_pending_for_message'),'Message receipt cannot close while a completed action lacks durable delivery.');
expect_war(str_contains($batching,'hache_sharky_action_delivery_pending_for_message'),'Batched receipts must honor transactional delivery pending state.');
expect_war(str_contains($worker,'hache_sharky_lab_finish_delivery'),'Worker must close action delivery only after durable queueing.');
expect_war(str_contains($worker,'hache_sharky_inbox.php'),'Worker must load durable inbox helpers directly.');

// P1 privacy: contact identifiers use keyed HMAC rather than enumerable plain SHA-256.
putenv('SHARKY_CONTACT_HASH_KEY=0123456789abcdef0123456789abcdef');
$hash1=hache_sharky_orchestrator_contact_hash('+52 998 111 2233');
$hash2=hache_sharky_orchestrator_contact_hash('529981112233');
expect_war($hash1===$hash2,'Phone formatting must normalize to the same HMAC.');
expect_war($hash1===hash_hmac('sha256','hache-sharky-contact-v3|529981112233','0123456789abcdef0123456789abcdef'),'Contact hash must be keyed HMAC with domain separation.');

fwrite(STDOUT,"SHARKY_WAR_GAMES_OK\n");
