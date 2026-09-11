<?php

declare(strict_types=1);

function backfill_send_once_ok(bool $condition,string $message): void
{
    if(!$condition){fwrite(STDERR,"BACKFILL_SEND_ONCE_FAIL: $message\n");exit(1);}
}

$sender=file_get_contents(__DIR__.'/../bin/sharky-reengagement-backfill-send-once.php')?:'';
$wrapper=file_get_contents(__DIR__.'/../ops/production-readiness/deploy-hache-natacion-wrapper')?:'';
$workflow=file_get_contents(__DIR__.'/../.github/workflows/sharky-reengagement-backfill-send-once.yml')?:'';

backfill_send_once_ok(str_contains($sender,"HACHE_SHARKY_BACKFILL_APPROVAL_SNAPSHOT_UTC = '2026-09-11T17:35:33+00:00'"),'Approved cohort must be pinned to the successful dry-run snapshot.');
backfill_send_once_ok(str_contains($sender,"'not_in_approved_snapshot' => 0")&&str_contains($sender,"'outside_current_window' => 0"),'Sender must reject newly aged or now-expired contacts.');
backfill_send_once_ok(str_contains($sender,'$hasStage3[$contactHash] = true')&&str_contains($sender,"'covered_by_current_rule'"),'Existing stage 3 must suppress retroactive scheduling.');
backfill_send_once_ok(str_contains($sender,'hache_sharky_followup_registered_contact')&&str_contains($sender,'hache_sharky_followup_newer_inbound_pending'),'Sender must revalidate registration and pending inbound immediately before mutation.');
backfill_send_once_ok(str_contains($sender,"'state_token_mismatch' => 0")&&str_contains($sender,"'state_updated_mismatch' => 0")&&str_contains($sender,"(int)(\$state['updated_at'] ?? 0) === \$userTurnAt")&&str_contains($sender,'hache_sharky_followup_commercial_ready')&&str_contains($sender,'hache_sharky_followup_context_matches'),'Sender must fail closed on token/time staleness or changed commercial context.');
backfill_send_once_ok(str_contains($sender,'hache_sharky_followup_after_sent($pdo, $contact, $meta, $now)'),'Sender must enter the existing stage-3 scheduler instead of bypassing follow-up safety.');
backfill_send_once_ok(!str_contains($sender,'hache_sharky_delivery_meta_send')&&!str_contains($sender,'hache_sharky_outbox_dispatch(')&&!str_contains($sender,'WHATSAPP_ACCESS_TOKEN'),'One-shot sender must not call Meta or force dispatch directly.');
backfill_send_once_ok(str_contains($sender,"'privacy' => 'aggregate_counts_only'")&&str_contains($sender,"'mode' => 'approved-send-once'"),'Output must remain aggregate-only.');
backfill_send_once_ok(str_contains($sender,'--execute-approved-20260911'),'Direct CLI mutation must require the explicit approval gate.');
backfill_send_once_ok(str_contains($sender,"'diagnostic' => [")&&str_contains($sender,"'token_matches_stage2' => 0")&&str_contains($sender,"'state_updated_matches_turn' => 0"),'Diagnostics must remain aggregate and separate token mismatch from timestamp mismatch.');

backfill_send_once_ok(str_contains($wrapper,'sharky-backfill-send-once)')&&str_contains($wrapper,'--execute-approved-20260911'),'Root helper must expose only the bounded approved sender command.');
backfill_send_once_ok(str_contains($workflow,"contains(github.event.workflow_run.head_commit.message, 'Ops: enviar reconquista retroactiva aprobada')"),'Workflow must only fire for the specifically approved merge.');
backfill_send_once_ok(str_contains($workflow,'deploy-hache-natacion sharky-backfill-send-once'),'Workflow must cross the existing privileged helper boundary.');
backfill_send_once_ok(!str_contains($workflow,'workflow_dispatch'),'The approved send must not become a reusable manual campaign trigger.');

fwrite(STDOUT,"SHARKY_BACKFILL_SEND_ONCE_OK\n");
