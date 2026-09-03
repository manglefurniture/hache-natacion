<?php

declare(strict_types=1);

require_once __DIR__.'/sharky-orchestrator-store.php';

const HACHE_SHARKY_ACTION_LEASE_SECONDS=180;

function hache_sharky_action_recovery_status(PDO $pdo,string $idempotencyKey): ?array
{
    if(!hache_sharky_orchestrator_store_ready($pdo))return null;
    try{
        $st=$pdo->prepare('SELECT status,result_code,action_type,lease_until,attempt_count,result_json,result_message,completed_at FROM sharky_action_audit WHERE idempotency_key=:k LIMIT 1');
        $st->execute([':k'=>hash('sha256',$idempotencyKey)]);$row=$st->fetch(PDO::FETCH_ASSOC);if(!$row)return null;
        $decoded=json_decode((string)($row['result_json']??''),true);$row['result']=is_array($decoded)?$decoded:null;return $row;
    }catch(Throwable $e){return null;}
}

function hache_sharky_action_recovery_claim(PDO $pdo,string $idempotencyKey,string $actionType,string $contactHash,?string $studentId,array $payload): bool
{
    if(!hache_sharky_orchestrator_store_ready($pdo))return false;
    $key=hash('sha256',$idempotencyKey);$payloadHash=hash('sha256',json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:'');
    try{
        $st=$pdo->prepare("INSERT IGNORE INTO sharky_action_audit(idempotency_key,action_type,contact_hash,alumno_id,status,payload_hash,lease_until,attempt_count) VALUES(:k,:t,:c,:a,'PENDING',:p,DATE_ADD(NOW(),INTERVAL ".HACHE_SHARKY_ACTION_LEASE_SECONDS." SECOND),1)");
        $st->execute([':k'=>$key,':t'=>mb_substr($actionType,0,60),':c'=>$contactHash,':a'=>$studentId,':p'=>$payloadHash]);if($st->rowCount()===1)return true;
        $st=$pdo->prepare("UPDATE sharky_action_audit SET lease_until=DATE_ADD(NOW(),INTERVAL ".HACHE_SHARKY_ACTION_LEASE_SECONDS." SECOND),attempt_count=attempt_count+1 WHERE idempotency_key=:k AND status='PENDING' AND payload_hash=:p AND (lease_until IS NULL OR lease_until<NOW())");
        $st->execute([':k'=>$key,':p'=>$payloadHash]);return $st->rowCount()===1;
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
