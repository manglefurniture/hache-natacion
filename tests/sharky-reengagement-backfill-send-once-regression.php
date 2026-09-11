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

backfill_send_once_ok(str_contains($sender,'function hache_sharky_reengagement_backfill_expired_default_state'),'Legacy recovery must be isolated behind an explicit empty-state predicate.');
foreach([
    "(\$identity['kind']??'unknown')==='unknown'",
    "(\$state['flow']??null)===null",
    "trim((string)(\$state['last_user_text']??''))===''",
    "(\$commercial['program']??null)===null",
    "(\$commercial['sede_clave']??null)===null",
    "(\$followup['status']??'idle')==='idle'",
    "(\$followup['token']??null)===null",
    "(\$followup['next_stage']??null)===null",
] as $guard){
    backfill_send_once_ok(str_contains($sender,$guard),'Expired-state recovery must fail closed unless the durable state is genuinely empty.');
}
backfill_send_once_ok(str_contains($sender,"'state_not_recoverable' => 0"),'Any non-empty stale state must be excluded rather than overwritten.');
backfill_send_once_ok(str_contains($sender,"hache_sharky_orchestrator_state(null,\$userTurnAt)")&&str_contains($sender,"\$state['identity']['kind']='prospect'")&&str_contains($sender,"\$state['commercial_context']['program']=\$program")&&str_contains($sender,"\$state['commercial_context']['sede_clave']=\$sede"),'Recovered state must be rebuilt only from the exact historical stage-2 context.');
backfill_send_once_ok(str_contains($sender,"'status'=>'second_sent'")&&str_contains($sender,"'next_stage'=>3")&&str_contains($sender,'hache_sharky_followup_payload($contact,$state,3,$token,$userTurnAt)'),'Recovered state must enter the existing stage-3 contract, not invent a parallel send path.');
backfill_send_once_ok(str_contains($sender,'$pdo->beginTransaction()')&&str_contains($sender,'$pdo->rollBack()')&&str_contains($sender,'$pdo->commit()'),'Recovered state and outbox enqueue must be transactional.');
backfill_send_once_ok(str_contains($sender,"hache_sharky_outbox_enqueue_raw(\$pdo,\$contact,\$payload,'idle-followup|'.\$token.'|3',\$due)"),'Recovered reminder must use the normal stage-3 outbox dedupe key.');
backfill_send_once_ok(str_contains($sender,'hache_sharky_followup_after_sent($pdo, $contact, $meta, $now)'),'Still-valid legacy follow-up state must continue through the existing stage-3 scheduler.');
backfill_send_once_ok(!str_contains($sender,'hache_sharky_delivery_meta_send')&&!str_contains($sender,'hache_sharky_outbox_dispatch(')&&!str_contains($sender,'WHATSAPP_ACCESS_TOKEN'),'One-shot sender must not call Meta or force dispatch directly.');
backfill_send_once_ok(str_contains($sender,"'privacy' => 'aggregate_counts_only'")&&str_contains($sender,"'mode' => 'approved-send-once'"),'Output must remain aggregate-only.');
backfill_send_once_ok(str_contains($sender,'--execute-approved-20260911'),'Direct CLI mutation must require the explicit approval gate.');
backfill_send_once_ok(str_contains($sender,"'expired_default_state_recovered' => 0")&&str_contains($sender,"'existing_followup_reused' => 0"),'Diagnostics must remain aggregate and distinguish recovery from reuse.');

backfill_send_once_ok(str_contains($wrapper,'sharky-backfill-send-once)')&&str_contains($wrapper,'--execute-approved-20260911'),'Root helper must expose only the bounded approved sender command.');
backfill_send_once_ok(str_contains($workflow,"contains(github.event.workflow_run.head_commit.message, 'Ops: enviar reconquista retroactiva aprobada')"),'Workflow must only fire for the specifically approved merge.');
backfill_send_once_ok(str_contains($workflow,'deploy-hache-natacion sharky-backfill-send-once'),'Workflow must cross the existing privileged helper boundary.');
backfill_send_once_ok(!str_contains($workflow,'workflow_dispatch'),'The approved send must not become a reusable manual campaign trigger.');

fwrite(STDOUT,"SHARKY_BACKFILL_SEND_ONCE_OK\n");
