<?php

declare(strict_types=1);

function sharky_review_306a_expect(bool $ok,string $message):void
{
    if(!$ok){fwrite(STDERR,"SHARKY REVIEW 306A FAIL: $message\n");exit(1);}
}

$batching=file_get_contents(__DIR__.'/../config/sharky-whatsapp-batching.php')?:'';
$recovery=file_get_contents(__DIR__.'/../config/sharky-action-recovery.php')?:'';
$executor=file_get_contents(__DIR__.'/../config/sharky-orchestrator-db.php')?:'';
$entry=file_get_contents(__DIR__.'/../api/whatsapp-webhook.php')?:'';

// A pending handoff must survive the inner takeover revalidation used after
// debounce/delivery-lock acquisition, otherwise replay silently consumes it.
sharky_review_306a_expect(str_contains($batching,'hache_sharky_inbox_handoff_pending($pdo,$messageId)'),'Inner delivery guard must consult durable handoff_pending state.');
sharky_review_306a_expect(str_contains($batching,'hache_sharky_takeover_active($contact)&&!$handoffPending'),'Active takeover may silence only receipts that are not recovering a pending handoff.');

// Encrypted action results are credentials-bearing recovery material. A GCM/key
// mismatch must be explicit and must drive locked registration reconciliation.
sharky_review_306a_expect(str_contains($recovery,'result_decrypt_failed')&&str_contains($recovery,'!is_array($decoded)'),'Encrypted result decrypt failure must be explicit.');
sharky_review_306a_expect(str_contains($recovery,'function hache_sharky_action_recovery_reseal_completed'),'Recovered credentials must be resealed before delivery closes.');
sharky_review_306a_expect(str_contains($recovery,"status='COMPLETED' AND delivery_queued_at IS NULL"),'Reseal must only mutate completed actions whose delivery is still pending.');
sharky_review_306a_expect(str_contains($executor,'result_decrypt_failed')&&str_contains($executor,'===true'),'Executor must fail closed on unreadable completed results.');
sharky_review_306a_expect(str_contains($executor,'hache_sharky_registration_recover_locked($pdo,$contact,$action)'),'Unreadable completed registration must reconcile under the identity lock.');
sharky_review_306a_expect(str_contains($executor,'hache_sharky_action_recovery_reseal_completed'),'Reconciled registration must reseal the fresh temporary credential.');
sharky_review_306a_expect(str_contains($executor,"throw new RuntimeException('Completed Sharky registration result is unreadable"),'Unmatched unreadable results must leave the receipt recoverable instead of degrading to empty success.');

// The documented .env flag must be read through the same resolver used by the
// rest of Sharky, not only through the process environment.
sharky_review_306a_expect(str_contains($entry,"require_once __DIR__ . '/../config/sharky-orchestrator-store.php'"),'Webhook entrypoint must load the shared Sharky environment resolver.');
sharky_review_306a_expect(str_contains($entry,"hache_sharky_orchestrator_secret('SHARKY_ORCHESTRATOR_LAB_ENABLED') === '1'"),'Webhook feature flag must resolve from process env or .env consistently.');
sharky_review_306a_expect(!str_contains($entry,"getenv('SHARKY_ORCHESTRATOR_LAB_ENABLED')"),'Webhook must not bypass the shared .env resolver.');

fwrite(STDOUT,"SHARKY_REVIEW_306A_OK\n");
