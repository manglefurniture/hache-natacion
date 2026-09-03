<?php

declare(strict_types=1);

require_once __DIR__.'/sharky-orchestrator-store.php';

const HACHE_SHARKY_OUTBOX_LEASE_SECONDS=90;

function hache_sharky_outbox_key(): string
{
    return hash_hmac('sha256','hache-sharky-outbox-v1',hache_sharky_orchestrator_contact_key(),true);
}

function hache_sharky_outbox_encrypt(array $payload): array
{
    $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($json===false)throw new RuntimeException('Invalid outbound payload');
    $iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($json,'aes-256-gcm',hache_sharky_outbox_key(),OPENSSL_RAW_DATA,$iv,$tag,'sharky-outbox-v1');
    if(!is_string($cipher)||strlen($tag)!==16)throw new RuntimeException('Unable to encrypt outbound payload');
    return ['ciphertext'=>base64_encode($cipher),'iv'=>base64_encode($iv),'tag'=>base64_encode($tag)];
}

function hache_sharky_outbox_decrypt(array $row): ?array
{
    $cipher=base64_decode((string)($row['payload_ciphertext']??''),true);$iv=base64_decode((string)($row['payload_iv']??''),true);$tag=base64_decode((string)($row['payload_tag']??''),true);
    if(!is_string($cipher)||!is_string($iv)||!is_string($tag)||strlen($iv)!==12||strlen($tag)!==16)return null;
    $json=openssl_decrypt($cipher,'aes-256-gcm',hache_sharky_outbox_key(),OPENSSL_RAW_DATA,$iv,$tag,'sharky-outbox-v1');if(!is_string($json))return null;
    $payload=json_decode($json,true);return is_array($payload)?$payload:null;
}

function hache_sharky_outbox_allow_during_takeover(array $payload): array
{
    $payload['_sharky_allow_takeover']=true;
    return $payload;
}

function hache_sharky_outbox_enqueue(PDO $pdo,string $contact,array $payload,string $dedupeSeed): bool
{
    if(!hache_sharky_orchestrator_store_ready($pdo))return false;
    try{
        $sealed=hache_sharky_outbox_encrypt($payload);$dedupe=hash('sha256','outbox|'.$dedupeSeed);
        $st=$pdo->prepare("INSERT IGNORE INTO sharky_outbox(dedupe_key,contact_hash,payload_ciphertext,payload_iv,payload_tag,status,available_at) VALUES(:d,:c,:p,:iv,:tag,'PENDING',NOW())");
        $st->execute([':d'=>$dedupe,':c'=>hache_sharky_orchestrator_contact_hash($contact),':p'=>$sealed['ciphertext'],':iv'=>$sealed['iv'],':tag'=>$sealed['tag']]);
        return $st->rowCount()===1||hache_sharky_outbox_exists($pdo,$dedupeSeed);
    }catch(Throwable $e){error_log('[sharky-outbox] enqueue failed');return false;}
}

function hache_sharky_outbox_exists(PDO $pdo,string $dedupeSeed): bool
{
    try{$st=$pdo->prepare('SELECT 1 FROM sharky_outbox WHERE dedupe_key=:d LIMIT 1');$st->execute([':d'=>hash('sha256','outbox|'.$dedupeSeed)]);return(bool)$st->fetchColumn();}catch(Throwable $e){return false;}
}

function hache_sharky_outbox_owner_token(): string
{
    return bin2hex(random_bytes(24));
}

/**
 * Claims rows with a fenced ownership token. When a caller already holds a
 * contact delivery lock, contactHash restricts the claim to that same contact;
 * this avoids lock-order inversions between concurrent contact workers.
 */
function hache_sharky_outbox_claim(PDO $pdo,int $limit=10,string $contactHash=''): array
{
    $limit=max(1,min(50,$limit));$rows=[];$contactHash=strtolower(trim($contactHash));
    if($contactHash!==''&&!preg_match('/^[a-f0-9]{64}$/',$contactHash))return [];
    try{
        if($contactHash!==''){
            $select=$pdo->prepare("SELECT id FROM sharky_outbox WHERE contact_hash=:c AND status='PENDING' AND available_at<=NOW() AND (lease_until IS NULL OR lease_until<NOW()) ORDER BY created_at,id LIMIT ".$limit);
            $select->execute([':c'=>$contactHash]);$ids=$select->fetchAll(PDO::FETCH_COLUMN);
        }else{
            $ids=$pdo->query("SELECT id FROM sharky_outbox WHERE status='PENDING' AND available_at<=NOW() AND (lease_until IS NULL OR lease_until<NOW()) ORDER BY created_at,id LIMIT ".$limit)->fetchAll(PDO::FETCH_COLUMN);
        }
        $claim=$pdo->prepare('UPDATE sharky_outbox SET lease_until=DATE_ADD(NOW(),INTERVAL '.HACHE_SHARKY_OUTBOX_LEASE_SECONDS.' SECOND),owner_token=:o WHERE id=:id AND status=\'PENDING\' AND available_at<=NOW() AND (lease_until IS NULL OR lease_until<NOW())');
        $read=$pdo->prepare("SELECT id,contact_hash,payload_ciphertext,payload_iv,payload_tag,attempt_count,owner_token FROM sharky_outbox WHERE id=:id AND status='PENDING' AND owner_token=:o LIMIT 1");
        foreach($ids as $id){
            $owner=hache_sharky_outbox_owner_token();$claim->execute([':id'=>$id,':o'=>$owner]);if($claim->rowCount()!==1)continue;
            $read->execute([':id'=>$id,':o'=>$owner]);$row=$read->fetch(PDO::FETCH_ASSOC);if($row)$rows[]=$row;
        }
    }catch(Throwable $e){error_log('[sharky-outbox] claim failed');}
    return $rows;
}

function hache_sharky_outbox_renew_owner(PDO $pdo,string $id,string $ownerToken): bool
{
    if($id===''||$ownerToken==='')return false;
    try{
        $st=$pdo->prepare('UPDATE sharky_outbox SET lease_until=DATE_ADD(NOW(),INTERVAL '.HACHE_SHARKY_OUTBOX_LEASE_SECONDS.' SECOND) WHERE id=:id AND status=\'PENDING\' AND owner_token=:o');
        $st->execute([':id'=>$id,':o'=>$ownerToken]);return $st->rowCount()===1;
    }catch(Throwable $e){return false;}
}

function hache_sharky_outbox_mark_sent(PDO $pdo,string $id,string $ownerToken): bool
{
    try{
        $st=$pdo->prepare("UPDATE sharky_outbox SET status='SENT',sent_at=NOW(),lease_until=NULL,owner_token=NULL,last_error=NULL WHERE id=:id AND status='PENDING' AND owner_token=:o");
        $st->execute([':id'=>$id,':o'=>$ownerToken]);return $st->rowCount()===1;
    }catch(Throwable $e){return false;}
}

function hache_sharky_outbox_mark_cancelled(PDO $pdo,string $id,string $ownerToken,string $reason='HUMAN_TAKEOVER'): bool
{
    try{
        $st=$pdo->prepare("UPDATE sharky_outbox SET status='CANCELLED',lease_until=NULL,owner_token=NULL,last_error=:e WHERE id=:id AND status='PENDING' AND owner_token=:o");
        $st->execute([':e'=>mb_substr($reason,0,255),':id'=>$id,':o'=>$ownerToken]);return $st->rowCount()===1;
    }catch(Throwable $e){return false;}
}

function hache_sharky_outbox_mark_failed(PDO $pdo,string $id,string $ownerToken,int $attempts,string $error='SEND_FAILED'): bool
{
    $next=$attempts+1;$status=$next>=8?'DEAD':'PENDING';$delay=min(3600,30*(2**min(6,$next-1)));
    try{
        $st=$pdo->prepare('UPDATE sharky_outbox SET status=:s,attempt_count=:a,last_error=:e,lease_until=NULL,owner_token=NULL,available_at=DATE_ADD(NOW(),INTERVAL '.$delay.' SECOND) WHERE id=:id AND status=\'PENDING\' AND owner_token=:o');
        $st->execute([':s'=>$status,':a'=>$next,':e'=>mb_substr($error,0,255),':id'=>$id,':o'=>$ownerToken]);return $st->rowCount()===1;
    }catch(Throwable $e){return false;}
}

/** @return array{sent:int,failed:int,dead:int,cancelled:int} */
function hache_sharky_outbox_dispatch(PDO $pdo,callable $sender,int $limit=10,string $lockedContact=''): array
{
    $stats=['sent'=>0,'failed'=>0,'dead'=>0,'cancelled'=>0];$limit=max(1,min(50,$limit));$lockedContact=preg_replace('/\D+/','',$lockedContact)?:'';
    $lockedHash=$lockedContact!==''?hache_sharky_orchestrator_contact_hash($lockedContact):'';
    // A row is claimed immediately before send. If this worker already owns a
    // contact lock, it may only claim that contact's rows, preventing A→B/B→A
    // lock inversion. Ownership is renewed after acquiring the delivery lock,
    // so a lease stolen while waiting can never send from the stale worker.
    for($i=0;$i<$limit;$i++){
        $claimed=$lockedHash!==''?hache_sharky_outbox_claim($pdo,1,$lockedHash):hache_sharky_outbox_claim($pdo,1);if(!$claimed)break;$row=$claimed[0];
        $owner=trim((string)($row['owner_token']??''));$id=(string)($row['id']??'');
        $payload=hache_sharky_outbox_decrypt($row);
        if($payload===null){if(hache_sharky_outbox_mark_failed($pdo,$id,$owner,7,'DECRYPT_FAILED'))$stats['dead']++;continue;}
        $allowTakeover=($payload['_sharky_allow_takeover']??false)===true;unset($payload['_sharky_allow_takeover']);
        $contact=preg_replace('/\D+/','',(string)($payload['to']??''))?:'';
        if($contact===''||!hash_equals((string)($row['contact_hash']??''),hache_sharky_orchestrator_contact_hash($contact))){
            if(hache_sharky_outbox_mark_failed($pdo,$id,$owner,7,'INVALID_CONTACT'))$stats['dead']++;
            continue;
        }

        $deliveryLock=null;$callerOwnsLock=$lockedContact!==''&&hash_equals($lockedContact,$contact);
        if(!$callerOwnsLock){
            $deliveryLock=hache_sharky_orchestrator_delivery_lock($contact);
            if(!is_resource($deliveryLock)){
                if(hache_sharky_outbox_mark_failed($pdo,$id,$owner,(int)$row['attempt_count'],'DELIVERY_LOCK_UNAVAILABLE'))$stats['failed']++;
                continue;
            }
        }
        try{
            // Lease may have expired while waiting for the contact lock. Renewing
            // with the fenced token proves this worker still owns the row.
            if(!hache_sharky_outbox_renew_owner($pdo,$id,$owner))continue;
            if(!$allowTakeover&&function_exists('hache_sharky_takeover_active')&&hache_sharky_takeover_active($contact)){
                if(hache_sharky_outbox_mark_cancelled($pdo,$id,$owner))$stats['cancelled']++;
                continue;
            }
            $ok=false;try{$ok=$sender($payload)===true;}catch(Throwable $e){$ok=false;}
            if($ok){
                if(hache_sharky_outbox_mark_sent($pdo,$id,$owner))$stats['sent']++;
                else error_log('[sharky-outbox] sender succeeded but sent marker failed');
            }else{
                if(hache_sharky_outbox_mark_failed($pdo,$id,$owner,(int)$row['attempt_count']))$stats['failed']++;
            }
        }finally{
            if(is_resource($deliveryLock))hache_sharky_orchestrator_unlock($deliveryLock);
        }
    }
    return $stats;
}
