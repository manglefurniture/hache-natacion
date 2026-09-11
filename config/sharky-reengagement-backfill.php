<?php

declare(strict_types=1);

require_once __DIR__.'/sharky-contact-book.php';

const HACHE_SHARKY_REENGAGEMENT_BACKFILL_APPROVAL_UTC='2026-09-11T17:35:33+00:00';
const HACHE_SHARKY_REENGAGEMENT_BACKFILL_CUTOVER_LOCAL='2026-09-10 13:52:26';
const HACHE_SHARKY_REENGAGEMENT_BACKFILL_MARKER='approved-20260911';

/**
 * Verifies that the special one-shot reminder still points to the exact stage-2
 * message that existed in the user-approved production snapshot.
 */
function hache_sharky_reengagement_backfill_stage2_proof(PDO $pdo,string $contact,array $meta): bool
{
    $token=trim((string)($meta['stage2_token']??''));
    $userTurnAt=(int)($meta['user_turn_at']??0);
    $program=(string)($meta['program']??'');
    $sede=(string)($meta['sede_clave']??'');
    if($token===''||$userTurnAt<=0||!in_array($program,['intensive','regular'],true)||!in_array($sede,['MONTEVERDE','PALAPAS'],true))return false;

    try{
        $hash=hache_sharky_orchestrator_contact_hash($contact);
        $st=$pdo->prepare(
            "SELECT contact_hash,payload_ciphertext,payload_iv,payload_tag,status FROM sharky_outbox "
            ."WHERE contact_hash=:c AND status='SENT' AND created_at>=DATE_SUB(NOW(),INTERVAL 5 DAY) ORDER BY id DESC"
        );
        $st->execute([':c'=>$hash]);
        foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){
            $payload=hache_sharky_outbox_decrypt($row);if(!is_array($payload))continue;
            $proof=$payload['_sharky_followup']??null;if(!is_array($proof)||(int)($proof['stage']??0)!==2)continue;
            if(!hash_equals($token,(string)($proof['token']??'')))continue;
            if((int)($proof['user_turn_at']??0)!==$userTurnAt)continue;
            if((string)($proof['program']??'')!==$program||(string)($proof['sede_clave']??'')!==$sede)continue;
            $to=preg_replace('/\D+/','',(string)($payload['to']??''))?:'';
            if($to===''||!hash_equals($hash,hache_sharky_orchestrator_contact_hash($to)))continue;
            return true;
        }
    }catch(Throwable $e){
        error_log('[sharky-backfill] stage2 proof lookup failed');
    }
    return false;
}

function hache_sharky_reengagement_backfill_existing_stage3(PDO $pdo,string $contact,array $meta): bool
{
    $userTurnAt=(int)($meta['user_turn_at']??0);if($userTurnAt<=0)return true;
    try{
        $hash=hache_sharky_orchestrator_contact_hash($contact);
        $st=$pdo->prepare(
            "SELECT payload_ciphertext,payload_iv,payload_tag,status FROM sharky_outbox "
            ."WHERE contact_hash=:c AND status IN ('PENDING','SENT') AND created_at>=DATE_SUB(NOW(),INTERVAL 5 DAY) ORDER BY id DESC"
        );
        $st->execute([':c'=>$hash]);
        foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){
            $payload=hache_sharky_outbox_decrypt($row);if(!is_array($payload))continue;
            $follow=$payload['_sharky_followup']??null;
            if(is_array($follow)&&(int)($follow['stage']??0)===3&&(int)($follow['user_turn_at']??0)===$userTurnAt)return true;
        }
    }catch(Throwable $e){
        return true;
    }
    return false;
}

/** @return array{ok:bool,reason?:string,reschedule_at?:int} */
function hache_sharky_reengagement_backfill_validate_before_send(PDO $pdo,string $contact,array $meta,?int $now=null): array
{
    $now??=time();
    if(($meta['marker']??'')!==HACHE_SHARKY_REENGAGEMENT_BACKFILL_MARKER)return ['ok'=>false,'reason'=>'BACKFILL_INVALID_MARKER'];
    if(($meta['approval_snapshot_utc']??'')!==HACHE_SHARKY_REENGAGEMENT_BACKFILL_APPROVAL_UTC)return ['ok'=>false,'reason'=>'BACKFILL_INVALID_APPROVAL'];

    $userTurnAt=(int)($meta['user_turn_at']??0);
    $program=(string)($meta['program']??'');
    $sede=(string)($meta['sede_clave']??'');
    if($userTurnAt<=0||!in_array($program,['intensive','regular'],true)||!in_array($sede,['MONTEVERDE','PALAPAS'],true))return ['ok'=>false,'reason'=>'BACKFILL_INVALID_META'];

    $approvedAt=(new DateTimeImmutable(HACHE_SHARKY_REENGAGEMENT_BACKFILL_APPROVAL_UTC))->getTimestamp();
    $cutover=(new DateTimeImmutable(HACHE_SHARKY_REENGAGEMENT_BACKFILL_CUTOVER_LOCAL,new DateTimeZone('America/Cancun')))->getTimestamp();
    $minAge=HACHE_SHARKY_FOLLOWUP_REENGAGEMENT_DELAY_SECONDS;
    $maxAge=HACHE_SHARKY_FOLLOWUP_REENGAGEMENT_DELAY_SECONDS+HACHE_SHARKY_FOLLOWUP_REENGAGEMENT_GRACE_SECONDS;
    $ageAtApproval=$approvedAt-$userTurnAt;
    $ageNow=$now-$userTurnAt;
    if($userTurnAt>=$cutover||$ageAtApproval<$minAge||$ageAtApproval>$maxAge)return ['ok'=>false,'reason'=>'BACKFILL_NOT_APPROVED_COHORT'];
    if($ageNow<$minAge||$ageNow>$maxAge)return ['ok'=>false,'reason'=>'SESSION_EXPIRED'];

    if(!hache_sharky_reengagement_backfill_stage2_proof($pdo,$contact,$meta))return ['ok'=>false,'reason'=>'BACKFILL_STAGE2_PROOF_MISSING'];
    if(hache_sharky_reengagement_backfill_existing_stage3($pdo,$contact,$meta))return ['ok'=>false,'reason'=>'BACKFILL_STAGE3_EXISTS'];

    $normalized=hache_sharky_contact_book_normalize_phone($contact);
    if($normalized===null)return ['ok'=>false,'reason'=>'INVALID_CONTACT'];
    $identity=hache_sharky_contact_book_identity($pdo,$normalized['e164']);
    if(($identity['role']??'')!=='PROSPECT')return ['ok'=>false,'reason'=>'NOT_CURRENT_PROSPECT'];

    $registered=hache_sharky_followup_registered_contact($pdo,$contact);
    if($registered===true)return ['ok'=>false,'reason'=>'REGISTRATION_EXISTS'];
    if($registered===null)return ['ok'=>false,'reason'=>'REGISTRATION_CHECK_UNAVAILABLE'];

    try{
        $hash=hache_sharky_orchestrator_contact_hash($contact);
        $st=$pdo->prepare('SELECT MAX(UNIX_TIMESTAMP(received_at)) FROM sharky_message_receipts WHERE contact_hash=:c');
        $st->execute([':c'=>$hash]);$lastInboundAt=(int)($st->fetchColumn()?:0);
    }catch(Throwable $e){
        return ['ok'=>false,'reason'=>'INBOUND_HISTORY_UNAVAILABLE'];
    }
    if($lastInboundAt<=0)return ['ok'=>false,'reason'=>'INBOUND_HISTORY_UNAVAILABLE'];
    if($lastInboundAt>$userTurnAt+30)return ['ok'=>false,'reason'=>'USER_REPLIED'];
    if(hache_sharky_followup_newer_inbound_pending($pdo,$contact,$userTurnAt))return ['ok'=>false,'reason'=>'PENDING_INBOUND'];

    // Conversation state may legitimately have expired because the original
    // flow predates stage 3. If a non-empty current state exists, it may only
    // narrow eligibility; it can never broaden the historical proof above.
    try{
        $state=hache_sharky_db_state_load($pdo,$contact);
        if(is_array($state['flow']??null))return ['ok'=>false,'reason'=>'ACTIVE_FLOW'];
        if(hache_sharky_followup_user_opted_out((string)($state['last_user_text']??'')))return ['ok'=>false,'reason'=>'OPTED_OUT'];
        $commercial=is_array($state['commercial_context']??null)?$state['commercial_context']:[];
        $currentProgram=$commercial['program']??null;$currentSede=$commercial['sede_clave']??null;
        if($currentProgram!==null&&$currentProgram!==$program)return ['ok'=>false,'reason'=>'CONTEXT_CHANGED'];
        if($currentSede!==null&&$currentSede!==$sede)return ['ok'=>false,'reason'=>'CONTEXT_CHANGED'];
        $kind=(string)($state['identity']['kind']??'unknown');
        if(!in_array($kind,['unknown','prospect'],true))return ['ok'=>false,'reason'=>'IDENTITY_CHANGED'];
    }catch(Throwable $e){
        return ['ok'=>false,'reason'=>'STATE_UNAVAILABLE'];
    }

    if(!hache_sharky_followup_send_allowed_now($now))return ['ok'=>false,'reason'=>'QUIET_HOURS','reschedule_at'=>hache_sharky_followup_next_allowed_at($now)];
    return ['ok'=>true];
}
