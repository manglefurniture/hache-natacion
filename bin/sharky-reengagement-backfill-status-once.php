<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-runtime.php';
require_once __DIR__.'/../config/sharky-outbox.php';
require_once __DIR__.'/../config/sharky-followup.php';

const HACHE_SHARKY_BACKFILL_STATUS_APPROVAL_SNAPSHOT_UTC = '2026-09-11T17:35:33+00:00';
const HACHE_SHARKY_BACKFILL_STATUS_CUTOVER_LOCAL = '2026-09-10 13:52:26';

/**
 * Read-only production diagnostic for the explicitly approved retroactive cohort.
 * It never dispatches, reschedules, updates or inserts outbox rows.
 * Output is aggregate-only and contains no contacts, payloads or provider IDs.
 *
 * @return array<string,mixed>
 */
function hache_sharky_reengagement_backfill_status_once(?PDO $pdo=null, ?int $now=null): array
{
    $now ??= time();
    $approvedAt=(new DateTimeImmutable(HACHE_SHARKY_BACKFILL_STATUS_APPROVAL_SNAPSHOT_UTC))->getTimestamp();
    $cutover=(new DateTimeImmutable(HACHE_SHARKY_BACKFILL_STATUS_CUTOVER_LOCAL,new DateTimeZone('America/Cancun')))->getTimestamp();
    $minAge=HACHE_SHARKY_FOLLOWUP_REENGAGEMENT_DELAY_SECONDS;
    $maxAge=HACHE_SHARKY_FOLLOWUP_REENGAGEMENT_DELAY_SECONDS+HACHE_SHARKY_FOLLOWUP_REENGAGEMENT_GRACE_SECONDS;

    $pdo ??= hache_sharky_pdo();
    if(!$pdo instanceof PDO)throw new RuntimeException('Database unavailable');
    if(!hache_sharky_orchestrator_store_ready($pdo))throw new RuntimeException('Sharky store unavailable');

    $stats=[
        'mode'=>'approved-backfill-status-once',
        'approval_snapshot_utc'=>HACHE_SHARKY_BACKFILL_STATUS_APPROVAL_SNAPSHOT_UTC,
        'cohort'=>[
            'stage3_rows'=>0,
            'status'=>['PENDING'=>0,'SENT'=>0,'DEAD'=>0,'CANCELLED'=>0,'OTHER'=>0],
            'due'=>['past_due'=>0,'future'=>0],
            'attempts'=>['zero'=>0,'one'=>0,'multiple'=>0],
            'provider_message_id_present'=>0,
            'sent_at_present'=>0,
        ],
        'errors'=>[],
        'template'=>['hache_retomar_inscripcion'=>0,'other'=>0],
        'privacy'=>'aggregate_counts_only',
        'read_only'=>true,
        'generated_at_utc'=>gmdate('c',$now),
    ];

    $rows=$pdo->query(
        "SELECT payload_ciphertext,payload_iv,payload_tag,status,attempt_count,last_error,available_at,sent_at,provider_message_id "
        ."FROM sharky_outbox WHERE created_at>=DATE_SUB(NOW(),INTERVAL 5 DAY) ORDER BY id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach($rows as $row){
        $payload=hache_sharky_outbox_decrypt($row);if(!is_array($payload))continue;
        $meta=$payload['_sharky_followup']??null;if(!is_array($meta)||(int)($meta['stage']??0)!==3)continue;
        $userTurnAt=(int)($meta['user_turn_at']??0);if($userTurnAt<=0)continue;
        $ageAtApproval=$approvedAt-$userTurnAt;
        if($userTurnAt>=$cutover||$ageAtApproval<$minAge||$ageAtApproval>$maxAge)continue;

        $stats['cohort']['stage3_rows']++;
        $status=(string)($row['status']??'');
        if(array_key_exists($status,$stats['cohort']['status']))$stats['cohort']['status'][$status]++;
        else $stats['cohort']['status']['OTHER']++;

        $availableAt=strtotime((string)($row['available_at']??''))?:0;
        if($availableAt>0&&$availableAt>$now)$stats['cohort']['due']['future']++;
        else $stats['cohort']['due']['past_due']++;

        $attempts=(int)($row['attempt_count']??0);
        if($attempts<=0)$stats['cohort']['attempts']['zero']++;
        elseif($attempts===1)$stats['cohort']['attempts']['one']++;
        else $stats['cohort']['attempts']['multiple']++;

        if(trim((string)($row['provider_message_id']??''))!=='')$stats['cohort']['provider_message_id_present']++;
        if(trim((string)($row['sent_at']??''))!=='')$stats['cohort']['sent_at_present']++;

        $error=trim((string)($row['last_error']??''));
        $error=$error===''?'NONE':mb_substr($error,0,120);
        $stats['errors'][$error]=($stats['errors'][$error]??0)+1;

        $template=(string)($payload['template']['name']??'');
        if($template===HACHE_SHARKY_FOLLOWUP_RESUME_TEMPLATE)$stats['template']['hache_retomar_inscripcion']++;
        else $stats['template']['other']++;
    }

    ksort($stats['errors']);
    return $stats;
}

if(PHP_SAPI==='cli'&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){
    if(!in_array('--status-approved-20260911',$argv,true)){
        fwrite(STDERR,"This read-only diagnostic requires --status-approved-20260911\n");
        exit(2);
    }
    try{
        fwrite(STDOUT,json_encode(hache_sharky_reengagement_backfill_status_once(),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL);
        exit(0);
    }catch(Throwable $e){
        fwrite(STDERR,'Sharky retroactive re-engagement status: '.$e->getMessage().PHP_EOL);
        exit(1);
    }
}
