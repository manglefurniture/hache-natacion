<?php

declare(strict_types=1);

require_once __DIR__.'/sharky-orchestrator-store.php';

const HACHE_SHARKY_ACTION_LEASE_SECONDS=180;

function hache_sharky_action_source_message_id(string $idempotencyKey): string
{
    $parts=explode('|',$idempotencyKey,2);
    return mb_substr(trim((string)($parts[0]??'')),0,191);
}

function hache_sharky_action_recovery_status(PDO $pdo,string $idempotencyKey): ?array
{
    if(!hache_sharky_orchestrator_store_ready($pdo))return null;
    try{
        $st=$pdo->prepare('SELECT status,result_code,action_type,source_message_id,delivery_queued_at,lease_until,attempt_count,result_json,result_message,completed_at FROM sharky_action_audit WHERE idempotency_key=:k LIMIT 1');
        $st->execute([':k'=>hash('sha256',$idempotencyKey)]);$row=$st->fetch(PDO::FETCH_ASSOC);if(!$row)return null;
        $decoded=json_decode((string)($row['result_json']??''),true);$row['result']=is_array($decoded)?$decoded:null;return $row;
    }catch(Throwable $e){return null;}
}

function hache_sharky_action_recovery_claim(PDO $pdo,string $idempotencyKey,string $actionType,string $contactHash,?string $studentId,array $payload): bool
{
    if(!hache_sharky_orchestrator_store_ready($pdo))return false;
    $key=hash('sha256',$idempotencyKey);$payloadHash=hash('sha256',json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:'');$sourceMessageId=hache_sharky_action_source_message_id($idempotencyKey);
    try{
        $st=$pdo->prepare("INSERT IGNORE INTO sharky_action_audit(idempotency_key,action_type,contact_hash,alumno_id,status,payload_hash,source_message_id,lease_until,attempt_count) VALUES(:k,:t,:c,:a,'PENDING',:p,:m,DATE_ADD(NOW(),INTERVAL ".HACHE_SHARKY_ACTION_LEASE_SECONDS." SECOND),1)");
        $st->execute([':k'=>$key,':t'=>mb_substr($actionType,0,60),':c'=>$contactHash,':a'=>$studentId,':p'=>$payloadHash,':m'=>$sourceMessageId!==''?$sourceMessageId:null]);if($st->rowCount()===1)return true;
        $st=$pdo->prepare("UPDATE sharky_action_audit SET source_message_id=COALESCE(source_message_id,:m),lease_until=DATE_ADD(NOW(),INTERVAL ".HACHE_SHARKY_ACTION_LEASE_SECONDS." SECOND),attempt_count=attempt_count+1 WHERE idempotency_key=:k AND status='PENDING' AND payload_hash=:p AND (lease_until IS NULL OR lease_until<NOW())");
        $st->execute([':k'=>$key,':p'=>$payloadHash,':m'=>$sourceMessageId!==''?$sourceMessageId:null]);return $st->rowCount()===1;
    }catch(Throwable $e){error_log('[sharky-action] claim failed');return false;}
}

function hache_sharky_action_recovery_finish(PDO $pdo,string $idempotencyKey,bool $ok,string $resultCode,?array $result=null,string $message=''): void
{
    if(!hache_sharky_orchestrator_store_ready($pdo))return;
    $json=$result===null?null:json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($json===false)$json=null;
    try{
        $st=$pdo->prepare("UPDATE sharky_action_audit SET status=:s,result_code=:r,result_json=:j,result_message=:m,completed_at=NOW(),lease_until=NULL WHERE idempotency_key=:k AND status='PENDING'");
        $st->execute([':s'=>$ok?'COMPLETED':'FAILED',':r'=>mb_substr($resultCode,0,80),':j'=>$json,':m'=>$message!==''?mb_substr($message,0,500):null,':k'=>hash('sha256',$idempotencyKey)]);
    }catch(Throwable $e){error_log('[sharky-action] finish failed');}
}

function hache_sharky_action_lease_active(?array $status): bool
{
    if(!is_array($status)||(string)($status['status']??'')!=='PENDING')return false;
    $lease=strtotime((string)($status['lease_until']??''));return $lease!==false&&$lease>time();
}

function hache_sharky_action_delivery_pending_for_message(PDO $pdo,string $messageId): bool
{
    $messageId=mb_substr(trim($messageId),0,191);if($messageId===''||!hache_sharky_orchestrator_store_ready($pdo))return false;
    try{$st=$pdo->prepare("SELECT 1 FROM sharky_action_audit WHERE source_message_id=:m AND status='COMPLETED' AND delivery_queued_at IS NULL LIMIT 1");$st->execute([':m'=>$messageId]);return(bool)$st->fetchColumn();}catch(Throwable $e){return false;}
}

function hache_sharky_action_delivery_queued_by_message(PDO $pdo,string $messageId): void
{
    $messageId=mb_substr(trim($messageId),0,191);if($messageId===''||!hache_sharky_orchestrator_store_ready($pdo))return;
    try{$st=$pdo->prepare("UPDATE sharky_action_audit SET delivery_queued_at=COALESCE(delivery_queued_at,NOW()) WHERE source_message_id=:m AND status='COMPLETED'");$st->execute([':m'=>$messageId]);}catch(Throwable $e){error_log('[sharky-action] delivery queue mark failed');}
}
