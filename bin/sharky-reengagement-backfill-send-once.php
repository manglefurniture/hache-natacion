<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-runtime.php';
require_once __DIR__.'/../config/sharky-outbox.php';
require_once __DIR__.'/../config/sharky-inbox.php';
require_once __DIR__.'/../config/sharky-contact-book.php';
require_once __DIR__.'/../config/sharky-followup.php';

const HACHE_SHARKY_BACKFILL_APPROVAL_SNAPSHOT_UTC = '2026-09-11T17:35:33+00:00';
const HACHE_SHARKY_BACKFILL_CUTOVER_LOCAL = '2026-09-10 13:52:26';

function hache_sharky_reengagement_backfill_expired_default_state(array $state): bool
{
    $identity=is_array($state['identity']??null)?$state['identity']:[];
    $commercial=is_array($state['commercial_context']??null)?$state['commercial_context']:[];
    $followup=hache_sharky_followup_state($state);
    return ($identity['kind']??'unknown')==='unknown'
        &&($identity['student_id']??null)===null
        &&($state['flow']??null)===null
        &&trim((string)($state['last_user_text']??''))===''
        &&($commercial['program']??null)===null
        &&($commercial['sede_clave']??null)===null
        &&($followup['status']??'idle')==='idle'
        &&($followup['token']??null)===null
        &&($followup['next_stage']??null)===null;
}

function hache_sharky_reengagement_backfill_recover_and_schedule(PDO $pdo,string $contact,array $meta,int $now): bool
{
    $token=trim((string)($meta['token']??''));
    $userTurnAt=(int)($meta['user_turn_at']??0);
    $program=(string)($meta['program']??'');
    $sede=(string)($meta['sede_clave']??'');
    if($token===''||$userTurnAt<=0||!in_array($program,['intensive','regular'],true)||!in_array($sede,['MONTEVERDE','PALAPAS'],true))return false;

    $state=hache_sharky_orchestrator_state(null,$userTurnAt);
    $state['identity']['kind']='prospect';
    $state['identity']['source']='approved_reengagement_backfill';
    $state['commercial_context']['program']=$program;
    $state['commercial_context']['sede_clave']=$sede;
    $due=hache_sharky_followup_next_allowed_at($now+60);
    $followup=[
        'status'=>'second_sent','token'=>$token,'user_turn_at'=>$userTurnAt,'sent_count'=>2,'next_stage'=>3,
        'first_due_at'=>null,'first_sent_at'=>null,'second_due_at'=>null,'third_due_at'=>$due,'completed_at'=>null,
    ];
    $state=hache_sharky_followup_set_state($state,$followup);
    $payload=hache_sharky_followup_payload($contact,$state,3,$token,$userTurnAt);

    $started=false;
    try{
        if(!$pdo->inTransaction()){$pdo->beginTransaction();$started=true;}
        if(!hache_sharky_db_state_save_now($pdo,$contact,$state,HACHE_SHARKY_FOLLOWUP_REENGAGEMENT_RETENTION_SECONDS)){
            if($started&&$pdo->inTransaction())$pdo->rollBack();
            return false;
        }
        if(!hache_sharky_outbox_enqueue_raw($pdo,$contact,$payload,'idle-followup|'.$token.'|3',$due)){
            if($started&&$pdo->inTransaction())$pdo->rollBack();
            return false;
        }
        if($started&&$pdo->inTransaction())$pdo->commit();
        return true;
    }catch(Throwable $e){
        if($started&&$pdo->inTransaction())$pdo->rollBack();
        error_log('[sharky-backfill] expired-state recovery schedule failed');
        return false;
    }
}

/**
 * One-shot, aggregate-only execution for the cohort explicitly approved after
 * the production dry-run. It schedules stage 3 only through the existing
 * follow-up/outbox pipeline, whose normal send gate revalidates again before Meta.
 *
 * Legacy prospects may no longer have durable conversation state because the
 * original state TTL was shorter than the new 48–72 h re-engagement window. An
 * empty/expired state can be reconstructed only from the exact SENT stage-2
 * metadata after current role, registration and inbound checks all pass.
 *
 * @return array<string,mixed>
 */
function hache_sharky_reengagement_backfill_send_once(?PDO $pdo=null, ?int $now=null): array
{
    $now ??= time();
    $approvedAt = (new DateTimeImmutable(HACHE_SHARKY_BACKFILL_APPROVAL_SNAPSHOT_UTC))->getTimestamp();
    $cutover = (new DateTimeImmutable(HACHE_SHARKY_BACKFILL_CUTOVER_LOCAL, new DateTimeZone('America/Cancun')))->getTimestamp();
    $minAge = HACHE_SHARKY_FOLLOWUP_REENGAGEMENT_DELAY_SECONDS;
    $maxAge = HACHE_SHARKY_FOLLOWUP_REENGAGEMENT_DELAY_SECONDS + HACHE_SHARKY_FOLLOWUP_REENGAGEMENT_GRACE_SECONDS;

    $stats = [
        'mode' => 'approved-send-once',
        'approval_snapshot_utc' => HACHE_SHARKY_BACKFILL_APPROVAL_SNAPSHOT_UTC,
        'window_hours' => ['min' => 48, 'max' => 72],
        'stage2_sent_contacts' => 0,
        'scheduled_for_delivery' => 0,
        'diagnostic' => [
            'state_checked' => 0,
            'existing_followup_reused' => 0,
            'expired_default_state_recovered' => 0,
        ],
        'excluded' => [
            'decrypt_failed' => 0,
            'invalid_stage2_meta' => 0,
            'not_in_approved_snapshot' => 0,
            'outside_current_window' => 0,
            'covered_by_current_rule' => 0,
            'invalid_contact' => 0,
            'not_current_prospect' => 0,
            'registered' => 0,
            'inbound_history_unavailable' => 0,
            'replied_after_original_turn' => 0,
            'pending_inbound' => 0,
            'state_unavailable' => 0,
            'state_not_recoverable' => 0,
            'context_not_eligible' => 0,
            'context_changed' => 0,
            'queue_failed' => 0,
        ],
        'privacy' => 'aggregate_counts_only',
        'generated_at_utc' => gmdate('c', $now),
    ];

    if (hache_sharky_orchestrator_secret('SHARKY_ORCHESTRATOR_LAB_ENABLED') !== '1') {
        throw new RuntimeException('Sharky orchestrator is not enabled');
    }
    if (strlen(hache_sharky_orchestrator_secret('SHARKY_CONTACT_HASH_KEY')) < 32) {
        throw new RuntimeException('SHARKY_CONTACT_HASH_KEY missing');
    }

    $pdo ??= hache_sharky_pdo();
    if (!$pdo instanceof PDO) throw new RuntimeException('Database unavailable');
    if (!hache_sharky_orchestrator_store_ready($pdo)) throw new RuntimeException('Sharky store unavailable');

    $rows = $pdo->query(
        "SELECT contact_hash,payload_ciphertext,payload_iv,payload_tag,status,created_at,sent_at\n"
        ."FROM sharky_outbox\n"
        ."WHERE created_at >= DATE_SUB(NOW(), INTERVAL 5 DAY)\n"
        ."ORDER BY created_at ASC,id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $stage2ByContact = [];
    $hasStage3 = [];

    foreach ($rows as $row) {
        $payload = hache_sharky_outbox_decrypt($row);
        if (!is_array($payload)) {
            $stats['excluded']['decrypt_failed']++;
            continue;
        }
        $meta = $payload['_sharky_followup'] ?? null;
        if (!is_array($meta)) continue;

        $stage = (int)($meta['stage'] ?? 0);
        $contactHash = strtolower(trim((string)($row['contact_hash'] ?? '')));
        if (!preg_match('/^[a-f0-9]{64}$/', $contactHash)) continue;

        if ($stage === 3) {
            $hasStage3[$contactHash] = true;
            continue;
        }
        if ($stage !== 2 || (string)($row['status'] ?? '') !== 'SENT') continue;

        $userTurnAt = (int)($meta['user_turn_at'] ?? 0);
        $token = trim((string)($meta['token'] ?? ''));
        $contact = preg_replace('/\D+/', '', (string)($payload['to'] ?? '')) ?: '';
        $program = (string)($meta['program'] ?? '');
        $sede = (string)($meta['sede_clave'] ?? '');
        if ($userTurnAt <= 0 || $token === '' || $contact === '' || !in_array($program, ['intensive','regular'], true) || !in_array($sede, ['MONTEVERDE','PALAPAS'], true)) {
            $stats['excluded']['invalid_stage2_meta']++;
            continue;
        }
        if (!hash_equals($contactHash, hache_sharky_orchestrator_contact_hash($contact))) continue;

        $current = $stage2ByContact[$contactHash] ?? null;
        if (!is_array($current) || $userTurnAt > (int)$current['user_turn_at']) {
            $stage2ByContact[$contactHash] = [
                'user_turn_at' => $userTurnAt,
                'contact' => $contact,
                'meta' => [
                    'token' => $token,
                    'stage' => 2,
                    'user_turn_at' => $userTurnAt,
                    'program' => $program,
                    'sede_clave' => $sede,
                ],
            ];
        }
    }

    $stats['stage2_sent_contacts'] = count($stage2ByContact);

    $latestInbound = $pdo->prepare(
        'SELECT MAX(UNIX_TIMESTAMP(received_at)) FROM sharky_message_receipts WHERE contact_hash=:c'
    );

    foreach ($stage2ByContact as $contactHash => $candidate) {
        $userTurnAt = (int)$candidate['user_turn_at'];
        $contact = (string)$candidate['contact'];
        $meta = is_array($candidate['meta'] ?? null) ? $candidate['meta'] : [];

        $ageAtApproval = $approvedAt - $userTurnAt;
        if ($ageAtApproval < $minAge || $ageAtApproval > $maxAge || $userTurnAt >= $cutover) {
            $stats['excluded']['not_in_approved_snapshot']++;
            continue;
        }

        $currentAge = $now - $userTurnAt;
        if ($currentAge < $minAge || $currentAge > $maxAge) {
            $stats['excluded']['outside_current_window']++;
            continue;
        }

        if (($hasStage3[$contactHash] ?? false) === true) {
            $stats['excluded']['covered_by_current_rule']++;
            continue;
        }

        $normalized = hache_sharky_contact_book_normalize_phone($contact);
        if ($normalized === null) {
            $stats['excluded']['invalid_contact']++;
            continue;
        }

        $identity = hache_sharky_contact_book_identity($pdo, $normalized['e164']);
        if (($identity['role'] ?? '') !== 'PROSPECT') {
            $stats['excluded']['not_current_prospect']++;
            continue;
        }

        $registered = hache_sharky_followup_registered_contact($pdo, $contact);
        if ($registered !== false) {
            $stats['excluded']['registered']++;
            continue;
        }

        $latestInbound->execute([':c' => $contactHash]);
        $lastInboundAt = (int)($latestInbound->fetchColumn() ?: 0);
        if ($lastInboundAt <= 0) {
            $stats['excluded']['inbound_history_unavailable']++;
            continue;
        }
        if ($lastInboundAt > $userTurnAt + 30) {
            $stats['excluded']['replied_after_original_turn']++;
            continue;
        }
        if (hache_sharky_followup_newer_inbound_pending($pdo, $contact, $userTurnAt)) {
            $stats['excluded']['pending_inbound']++;
            continue;
        }

        try {
            $state = hache_sharky_db_state_load($pdo, $contact);
        } catch (Throwable $e) {
            $stats['excluded']['state_unavailable']++;
            continue;
        }

        $stats['diagnostic']['state_checked']++;
        $followup = hache_sharky_followup_state($state);
        $token = (string)($meta['token'] ?? '');

        $existingStateMatches = $token!==''
            &&hash_equals((string)($followup['token']??''),$token)
            &&(int)($state['updated_at']??0)===$userTurnAt;

        if ($existingStateMatches) {
            if (!hache_sharky_followup_commercial_ready($state)) {
                $stats['excluded']['context_not_eligible']++;
                continue;
            }
            if (!hache_sharky_followup_context_matches($state, $meta)) {
                $stats['excluded']['context_changed']++;
                continue;
            }
            hache_sharky_followup_after_sent($pdo, $contact, $meta, $now);
            $stats['diagnostic']['existing_followup_reused']++;
        } elseif (hache_sharky_reengagement_backfill_expired_default_state($state)) {
            if (!hache_sharky_reengagement_backfill_recover_and_schedule($pdo, $contact, $meta, $now)) {
                $stats['excluded']['queue_failed']++;
                continue;
            }
            $stats['diagnostic']['expired_default_state_recovered']++;
        } else {
            $stats['excluded']['state_not_recoverable']++;
            continue;
        }

        try {
            $afterState = hache_sharky_db_state_load($pdo, $contact);
            $afterFollowup = hache_sharky_followup_state($afterState);
        } catch (Throwable $e) {
            $stats['excluded']['queue_failed']++;
            continue;
        }

        if ((int)($afterFollowup['next_stage'] ?? 0) === 3 && hash_equals((string)($afterFollowup['token'] ?? ''), $token)) {
            $stats['scheduled_for_delivery']++;
        } else {
            $stats['excluded']['queue_failed']++;
        }
    }

    return $stats;
}

if (PHP_SAPI === 'cli' && realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    if (!in_array('--execute-approved-20260911', $argv, true)) {
        fwrite(STDERR, "This one-shot command requires --execute-approved-20260911\n");
        exit(2);
    }
    try {
        $stats = hache_sharky_reengagement_backfill_send_once();
        fwrite(STDOUT, json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, 'Sharky approved retroactive re-engagement: '.$e->getMessage().PHP_EOL);
        exit(1);
    }
}
