<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-runtime.php';
require_once __DIR__.'/../config/sharky-outbox.php';
require_once __DIR__.'/../config/sharky-followup.php';

const HACHE_SHARKY_BACKFILL_EXPORT_APPROVAL_SNAPSHOT_UTC = '2026-09-11T17:35:33+00:00';
const HACHE_SHARKY_BACKFILL_EXPORT_CUTOVER_LOCAL = '2026-09-10 13:52:26';

/** @return list<string> */
function hache_sharky_reengagement_backfill_export_contacts_once(?PDO $pdo=null): array
{
    $approvedAt=(new DateTimeImmutable(HACHE_SHARKY_BACKFILL_EXPORT_APPROVAL_SNAPSHOT_UTC))->getTimestamp();
    $cutover=(new DateTimeImmutable(HACHE_SHARKY_BACKFILL_EXPORT_CUTOVER_LOCAL,new DateTimeZone('America/Cancun')))->getTimestamp();
    $minAge=HACHE_SHARKY_FOLLOWUP_REENGAGEMENT_DELAY_SECONDS;
    $maxAge=HACHE_SHARKY_FOLLOWUP_REENGAGEMENT_DELAY_SECONDS+HACHE_SHARKY_FOLLOWUP_REENGAGEMENT_GRACE_SECONDS;

    $pdo ??= hache_sharky_pdo();
    if(!$pdo instanceof PDO)throw new RuntimeException('Database unavailable');
    if(!hache_sharky_orchestrator_store_ready($pdo))throw new RuntimeException('Sharky store unavailable');

    $rows=$pdo->query(
        "SELECT payload_ciphertext,payload_iv,payload_tag,status,provider_message_id "
        ."FROM sharky_outbox WHERE created_at>=DATE_SUB(NOW(),INTERVAL 5 DAY) ORDER BY id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $delivery=$pdo->prepare('SELECT status FROM sharky_delivery_status WHERE provider_message_id=:id LIMIT 1');
    $contacts=[];

    foreach($rows as $row){
        if((string)($row['status']??'')!=='SENT')continue;
        $providerId=trim((string)($row['provider_message_id']??''));
        if($providerId==='')continue;

        $payload=hache_sharky_outbox_decrypt($row);
        if(!is_array($payload))continue;
        $meta=$payload['_sharky_followup']??null;
        if(!is_array($meta)||(int)($meta['stage']??0)!==3)continue;
        if((string)($payload['template']['name']??'')!==HACHE_SHARKY_FOLLOWUP_RESUME_TEMPLATE)continue;

        $userTurnAt=(int)($meta['user_turn_at']??0);
        if($userTurnAt<=0)continue;
        $ageAtApproval=$approvedAt-$userTurnAt;
        if($userTurnAt>=$cutover||$ageAtApproval<$minAge||$ageAtApproval>$maxAge)continue;

        $delivery->execute([':id'=>$providerId]);
        $providerStatus=strtoupper(trim((string)($delivery->fetchColumn()?:'')));
        if(!in_array($providerStatus,['SENT','DELIVERED','READ'],true))continue;

        $contact=preg_replace('/\D+/','',(string)($payload['to']??''))?:'';
        if(!preg_match('/^[0-9]{10,15}$/',$contact))continue;
        $contacts[$contact]=true;
    }

    $out=array_keys($contacts);
    sort($out,SORT_STRING);
    if(count($out)!==12)throw new RuntimeException('Expected exactly 12 approved delivered contacts, got '.count($out));
    return $out;
}

if(PHP_SAPI==='cli'&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){
    if(!in_array('--export-approved-20260911',$argv,true)){
        fwrite(STDERR,"This one-shot export requires --export-approved-20260911\n");
        exit(2);
    }
    try{
        foreach(hache_sharky_reengagement_backfill_export_contacts_once() as $contact){
            fwrite(STDOUT,$contact.PHP_EOL);
        }
        exit(0);
    }catch(Throwable $e){
        fwrite(STDERR,'Sharky retroactive contact export: '.$e->getMessage().PHP_EOL);
        exit(1);
    }
}
