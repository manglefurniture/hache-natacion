<?php

declare(strict_types=1);

require_once __DIR__.'/sharky-orchestrator-store.php';

function hache_sharky_inbox_key(): string
{
    return hash_hmac('sha256','hache-sharky-inbox-v1',hache_sharky_orchestrator_contact_key(),true);
}

function hache_sharky_inbox_contact(array $event): string
{
    return preg_replace('/\D+/','',(string)($event['from']??$event['to']??''))?:'';
}

function hache_sharky_inbox_encrypt(array $event): array
{
    $json=json_encode($event,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($json===false)throw new RuntimeException('Invalid inbound event');
    $iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($json,'aes-256-gcm',hache_sharky_inbox_key(),OPENSSL_RAW_DATA,$iv,$tag,'sharky-inbox-v1');
    if(!is_string($cipher)||strlen($tag)!==16)throw new RuntimeException('Unable to encrypt inbound event');
    return ['ciphertext'=>base64_encode($cipher),'iv'=>base64_encode($iv),'tag'=>base64_encode($tag)];
}

function hache_sharky_inbox_store(PDO $pdo,array $event): bool
{
    if(!hache_sharky_orchestrator_store_ready($pdo))return false;
    $id=mb_substr(trim((string)($event['id']??'')),0,191);$contact=hache_sharky_inbox_contact($event);if($id===''||$contact==='')return false;
    try{
        $sealed=hache_sharky_inbox_encrypt($event);$type=mb_substr((string)($event['kind']??$event['type']??'message'),0,30);$hash=hache_sharky_orchestrator_contact_hash($contact);
        $st=$pdo->prepare('INSERT IGNORE INTO sharky_message_receipts(message_id,contact_hash,message_type,payload_ciphertext,payload_iv,payload_tag,attempt_count) VALUES(:m,:c,:t,:p,:iv,:tag,0)');
        $st->execute([':m'=>$id,':c'=>$hash,':t'=>$type,':p'=>$sealed['ciphertext'],':iv'=>$sealed['iv'],':tag'=>$sealed['tag']]);
        if($st->rowCount()===1)return true;
        $st=$pdo->prepare('UPDATE sharky_message_receipts SET payload_ciphertext=COALESCE(payload_ciphertext,:p),payload_iv=COALESCE(payload_iv,:iv),payload_tag=COALESCE(payload_tag,:tag) WHERE message_id=:m');
        $st->execute([':p'=>$sealed['ciphertext'],':iv'=>$sealed['iv'],':tag'=>$sealed['tag'],':m'=>$id]);
        return true;
    }catch(Throwable $e){error_log('[sharky-inbox] persist failed');return false;}
}

function hache_sharky_inbox_mark_handoff_pending(PDO $pdo,string $messageId): bool
{
    $messageId=mb_substr(trim($messageId),0,191);if($messageId===''||!hache_sharky_orchestrator_store_ready($pdo))return false;
    try{
        $st=$pdo->prepare('UPDATE sharky_message_receipts SET handoff_pending_at=COALESCE(handoff_pending_at,NOW()) WHERE message_id=:m AND processed_at IS NULL');
        $st->execute([':m'=>$messageId]);
        if($st->rowCount()===1)return true;
        $check=$pdo->prepare('SELECT 1 FROM sharky_message_receipts WHERE message_id=:m AND processed_at IS NULL AND handoff_pending_at IS NOT NULL LIMIT 1');
        $check->execute([':m'=>$messageId]);return(bool)$check->fetchColumn();
    }catch(Throwable $e){error_log('[sharky-inbox] handoff pending mark failed');return false;}
}

function hache_sharky_inbox_handoff_pending(PDO $pdo,string $messageId): bool
{
    $messageId=mb_substr(trim($messageId),0,191);if($messageId===''||!hache_sharky_orchestrator_store_ready($pdo))return false;
    try{
        $st=$pdo->prepare('SELECT 1 FROM sharky_message_receipts WHERE message_id=:m AND processed_at IS NULL AND handoff_pending_at IS NOT NULL LIMIT 1');
        $st->execute([':m'=>$messageId]);return(bool)$st->fetchColumn();
    }catch(Throwable $e){return false;}
}

function hache_sharky_inbox_decrypt(array $row): ?array
{
    $cipher=base64_decode((string)($row['payload_ciphertext']??''),true);$iv=base64_decode((string)($row['payload_iv']??''),true);$tag=base64_decode((string)($row['payload_tag']??''),true);
    if(!is_string($cipher)||!is_string($iv)||!is_string($tag)||strlen($iv)!==12||strlen($tag)!==16)return null;
    $json=openssl_decrypt($cipher,'aes-256-gcm',hache_sharky_inbox_key(),OPENSSL_RAW_DATA,$iv,$tag,'sharky-inbox-v1');if(!is_string($json))return null;
    $event=json_decode($json,true);return is_array($event)?$event:null;
}

function hache_sharky_inbox_pending(PDO $pdo,int $limit=20): array
{
    $limit=max(1,min(100,$limit));
    try{return $pdo->query("SELECT message_id,payload_ciphertext,payload_iv,payload_tag FROM sharky_message_receipts WHERE processed_at IS NULL AND payload_ciphertext IS NOT NULL AND (lease_until IS NULL OR lease_until<NOW()) ORDER BY received_at,message_id LIMIT ".$limit)->fetchAll(PDO::FETCH_ASSOC);}catch(Throwable $e){return [];}
}

function hache_sharky_inbox_mark_dead(PDO $pdo,string $messageId,string $error): void
{
    try{$st=$pdo->prepare('UPDATE sharky_message_receipts SET processed_at=NOW(),lease_until=NULL,last_error=:e WHERE message_id=:m');$st->execute([':e'=>mb_substr($error,0,255),':m'=>mb_substr($messageId,0,191)]);}catch(Throwable $e){}
}

/** @return array{processed:int,deferred:int,dead:int} */
function hache_sharky_inbox_dispatch(PDO $pdo,callable $processor,int $limit=20): array
{
    $stats=['processed'=>0,'deferred'=>0,'dead'=>0];
    foreach(hache_sharky_inbox_pending($pdo,$limit) as $row){
        $event=hache_sharky_inbox_decrypt($row);$id=(string)($row['message_id']??'');
        if($event===null){hache_sharky_inbox_mark_dead($pdo,$id,'DECRYPT_FAILED');$stats['dead']++;continue;}
        $done=false;try{$done=$processor($event)===true;}catch(Throwable $e){error_log('[sharky-inbox] worker exception');$done=false;}
        if($done)$stats['processed']++;else$stats['deferred']++;
    }
    return $stats;
}
