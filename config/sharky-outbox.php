<?php

declare(strict_types=1);

require_once __DIR__.'/sharky-orchestrator-store.php';
require_once __DIR__.'/sharky-orchestrator-db.php';
require_once __DIR__.'/sharky-followup.php';
require_once __DIR__.'/sharky-groups.php';
require_once __DIR__.'/sharky-delivery-status.php';

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

/**
 * Adds friendly location references only at the final outbound-payload layer.
 * The interactive IDs and Sharky conversation state remain untouched, so this
 * cannot alter qualification, commercial context or controlled-flow routing.
 */
function hache_sharky_outbox_add_venue_hints(array $payload): array
{
    if(($payload['type']??'')!=='interactive')return $payload;
    $interactive=$payload['interactive']??null;
    if(!is_array($interactive)||($interactive['type']??'')!=='button')return $payload;
    $buttons=$interactive['action']['buttons']??null;
    if(!is_array($buttons))return $payload;

    $hasMonteverde=false;$hasPalapas=false;
    foreach($buttons as $button){
        if(!is_array($button))continue;
        $id=strtolower(trim((string)($button['reply']['id']??'')));
        if($id==='sede:monteverde')$hasMonteverde=true;
        elseif($id==='sede:palapas')$hasPalapas=true;
    }
    if(!$hasMonteverde||!$hasPalapas)return $payload;

    foreach($buttons as $index=>$button){
        if(!is_array($button))continue;
        $id=strtolower(trim((string)($button['reply']['id']??'')));
        if($id==='sede:monteverde')$payload['interactive']['action']['buttons'][$index]['reply']['title']='Colegio Monteverde';
        elseif($id==='sede:palapas')$payload['interactive']['action']['buttons'][$index]['reply']['title']='Palapas Protudec';
    }

    $body=trim((string)($payload['interactive']['body']['text']??''));
    $monteverdeHint='📍 Colegio Monteverde — al inicio de Av. Bonampak';
    $palapasHint='📍 Palapas Protudec — a 100 m del Parque de las Palapas';
    if(!str_contains($body,'Av. Bonampak')&&!str_contains($body,'Parque de las Palapas')){
        $decorated=trim($body)."\n\n".$monteverdeHint."\n".$palapasHint;
        if(mb_strlen($decorated)<=1024)$payload['interactive']['body']['text']=$decorated;
    }
    return $payload;
}

/**
 * Turns the final intensive-registration offer into a clearer sales close.
 * This is deliberately presentation-only: durable Sharky state is already
 * decided before this layer. The existing flow:yes / flow:no IDs are preserved
 * and action:human uses the orchestrator's established takeover path.
 */
function hache_sharky_outbox_add_sales_close(array $payload): array
{
    if(($payload['type']??'')!=='interactive')return $payload;
    $interactive=$payload['interactive']??null;
    if(!is_array($interactive)||($interactive['type']??'')!=='button')return $payload;
    $buttons=$interactive['action']['buttons']??null;
    if(!is_array($buttons))return $payload;

    $ids=[];
    foreach($buttons as $button){
        if(!is_array($button))continue;
        $id=strtolower(trim((string)($button['reply']['id']??'')));
        if($id!=='')$ids[]=$id;
    }
    if(!in_array('flow:yes',$ids,true)||!in_array('flow:no',$ids,true)||!in_array('flow:cancel',$ids,true))return $payload;

    $body=trim((string)($payload['interactive']['body']['text']??''));
    $normalized=mb_strtolower($body,'UTF-8');
    $isRegistrationOffer=str_contains($normalized,'registrarte al curso intensivo')
        ||str_contains($normalized,'registrarte al intensivo')
        ||str_contains($normalized,'continuemos con el intensivo');
    if(!$isRegistrationOffer)return $payload;

    $venue='';
    if(str_contains($normalized,'palapas'))$venue='Palapas Protudec';
    elseif(str_contains($normalized,'monteverde'))$venue='Colegio Monteverde';

    if($venue!==''){
        $message='Perfecto 😊 Ya tengo tu opción: curso intensivo en '.$venue.'.'
            ."\n\n".'Si quieres, puedo ayudarte a apartar tu lugar ahora mismo. Elegimos fecha y horario y completamos tu inscripción.'
            ."\n\n".'¿Cómo quieres continuar?';
    }else{
        $message='Perfecto 😊 Si quieres, puedo ayudarte a apartar tu lugar en el curso intensivo ahora mismo.'
            ."\n\n".'Elegimos sede, fecha y horario y completamos tu inscripción.'
            ."\n\n".'¿Cómo quieres continuar?';
    }
    if(mb_strlen($message)>1024)return $payload;

    $payload['interactive']['body']['text']=$message;
    $payload['interactive']['action']['buttons']=[
        ['type'=>'reply','reply'=>['id'=>'flow:yes','title'=>'Apartar mi lugar']],
        ['type'=>'reply','reply'=>['id'=>'action:human','title'=>'Hablar con un profe']],
        ['type'=>'reply','reply'=>['id'=>'flow:no','title'=>'Ahora no']],
    ];
    return $payload;
}

function hache_sharky_outbox_enqueue_raw(PDO $pdo,string $contact,array $payload,string $dedupeSeed,?int $availableAt=null): bool
{
    if(!hache_sharky_orchestrator_store_ready($pdo))return false;
    $availableAt??=time();
    try{
        $payload=hache_sharky_outbox_add_venue_hints($payload);
        $payload=hache_sharky_outbox_add_sales_close($payload);
        $sealed=hache_sharky_outbox_encrypt($payload);$dedupe=hash('sha256','outbox|'.$dedupeSeed);
        $st=$pdo->prepare("INSERT IGNORE INTO sharky_outbox(dedupe_key,contact_hash,payload_ciphertext,payload_iv,payload_tag,status,available_at) VALUES(:d,:c,:p,:iv,:tag,'PENDING',FROM_UNIXTIME(:a))");
        $st->execute([':d'=>$dedupe,':c'=>hache_sharky_orchestrator_contact_hash($contact),':p'=>$sealed['ciphertext'],':iv'=>$sealed['iv'],':tag'=>$sealed['tag'],':a'=>$availableAt]);
        return $st->rowCount()===1||hache_sharky_outbox_exists($pdo,$dedupeSeed);
    }catch(Throwable $e){error_log('[sharky-outbox] enqueue failed');return false;}
}

function hache_sharky_outbox_enqueue(PDO $pdo,string $contact,array $payload,string $dedupeSeed): bool
{
    if(!is_array($payload['_sharky_followup']??null))$payload=hache_sharky_followup_prepare_normal_outbound($pdo,$contact,$payload,$dedupeSeed);
    return hache_sharky_outbox_enqueue_raw($pdo,$contact,$payload,$dedupeSeed,time());
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
        $params=[':id'=>$id,':o'=>$ownerToken];
        $st=$pdo->prepare('UPDATE sharky_outbox SET lease_until=DATE_ADD(NOW(),INTERVAL '.HACHE_SHARKY_OUTBOX_LEASE_SECONDS.' SECOND) WHERE id=:id AND status=\'PENDING\' AND owner_token=:o');
        $st->execute($params);
        if($st->rowCount()===1)return true;
        $check=$pdo->prepare("SELECT 1 FROM sharky_outbox WHERE id=:id AND status='PENDING' AND owner_token=:o AND lease_until>=NOW() LIMIT 1");
        $check->execute($params);
        return (bool)$check->fetchColumn();
    }catch(Throwable $e){return false;}
}

function hache_sharky_outbox_release_owner(PDO $pdo,string $id,string $ownerToken): bool
{
    if($id===''||$ownerToken==='')return false;
    try{
        $st=$pdo->prepare("UPDATE sharky_outbox SET lease_until=NULL,owner_token=NULL WHERE id=:id AND status='PENDING' AND owner_token=:o");
        $st->execute([':id'=>$id,':o'=>$ownerToken]);return $st->rowCount()===1;
    }catch(Throwable $e){return false;}
}

function hache_sharky_outbox_reschedule_owner(PDO $pdo,string $id,string $ownerToken,int $availableAt,string $reason='DEFERRED'): bool
{
    if($id===''||$ownerToken===''||$availableAt<=0)return false;
    try{
        $st=$pdo->prepare("UPDATE sharky_outbox SET available_at=FROM_UNIXTIME(:a),lease_until=NULL,owner_token=NULL,last_error=:e WHERE id=:id AND status='PENDING' AND owner_token=:o");
        $st->execute([':a'=>$availableAt,':e'=>mb_substr($reason,0,255),':id'=>$id,':o'=>$ownerToken]);return $st->rowCount()===1;
    }catch(Throwable $e){return false;}
}

function hache_sharky_outbox_mark_sent(PDO $pdo,string $id,string $ownerToken,string $providerMessageId=''): bool
{
    try{
        $providerMessageId=trim($providerMessageId);
        if($providerMessageId!==''&&strlen($providerMessageId)<=191&&hache_sharky_delivery_schema_ready($pdo)){
            $st=$pdo->prepare("UPDATE sharky_outbox SET status='SENT',sent_at=NOW(),provider_message_id=:pm,lease_until=NULL,owner_token=NULL,last_error=NULL WHERE id=:id AND status='PENDING' AND owner_token=:o");
            $st->execute([':pm'=>$providerMessageId,':id'=>$id,':o'=>$ownerToken]);return $st->rowCount()===1;
        }
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
    if(is_string($sender)&&in_array($sender,['hache_sharky_lab_send','hache_sharky_outbox_meta_send'],true))$sender='hache_sharky_delivery_meta_send';
    $stats=['sent'=>0,'failed'=>0,'dead'=>0,'cancelled'=>0];$limit=max(1,min(50,$limit));$lockedContact=preg_replace('/\D+/','',$lockedContact)?:'';
    if(hache_sharky_orchestrator_secret('SHARKY_ORCHESTRATOR_LAB_ENABLED')!=='1')return $stats;
    $lockedHash=$lockedContact!==''?hache_sharky_orchestrator_contact_hash($lockedContact):'';
    for($i=0;$i<$limit;$i++){
        if(hache_sharky_orchestrator_secret('SHARKY_ORCHESTRATOR_LAB_ENABLED')!=='1')break;
        $claimed=$lockedHash!==''?hache_sharky_outbox_claim($pdo,1,$lockedHash):hache_sharky_outbox_claim($pdo,1);if(!$claimed)break;$row=$claimed[0];
        $owner=trim((string)($row['owner_token']??''));$id=(string)($row['id']??'');
        $payload=hache_sharky_outbox_decrypt($row);
        if($payload===null){if(hache_sharky_outbox_mark_failed($pdo,$id,$owner,7,'DECRYPT_FAILED'))$stats['dead']++;continue;}
        if(($payload['_sharky_group']??false)===true&&!hache_sharky_groups_enabled($pdo)){
            if(hache_sharky_outbox_mark_cancelled($pdo,$id,$owner,'GROUPS_DISABLED'))$stats['cancelled']++;
            continue;
        }
        $allowTakeover=($payload['_sharky_allow_takeover']??false)===true;unset($payload['_sharky_allow_takeover']);
        $followupArm=is_array($payload['_sharky_followup_arm']??null)?$payload['_sharky_followup_arm']:null;unset($payload['_sharky_followup_arm']);
        $followupMeta=is_array($payload['_sharky_followup']??null)?$payload['_sharky_followup']:null;unset($payload['_sharky_followup']);
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
            if(!hache_sharky_outbox_renew_owner($pdo,$id,$owner))continue;
            if(hache_sharky_orchestrator_secret('SHARKY_ORCHESTRATOR_LAB_ENABLED')!=='1'){
                hache_sharky_outbox_release_owner($pdo,$id,$owner);break;
            }
            if(!$allowTakeover&&function_exists('hache_sharky_takeover_active')&&hache_sharky_takeover_active($contact)){
                if(hache_sharky_outbox_mark_cancelled($pdo,$id,$owner))$stats['cancelled']++;continue;
            }
            if(is_array($followupMeta)){
                $gate=hache_sharky_followup_validate_before_send($pdo,$contact,$followupMeta,time());
                if(($gate['ok']??false)!==true){
                    $reason=(string)($gate['reason']??'FOLLOWUP_CANCELLED');$reschedule=(int)($gate['reschedule_at']??0);
                    if($reschedule>0){
                        if(hache_sharky_outbox_reschedule_owner($pdo,$id,$owner,$reschedule,$reason))continue;
                        if(hache_sharky_outbox_mark_failed($pdo,$id,$owner,(int)$row['attempt_count'],'FOLLOWUP_RESCHEDULE_FAILED'))$stats['failed']++;
                        continue;
                    }
                    hache_sharky_followup_note_cancelled($pdo,$contact,$followupMeta,$reason,time());
                    if(hache_sharky_outbox_mark_cancelled($pdo,$id,$owner,$reason))$stats['cancelled']++;
                    continue;
                }
            }
            if(hache_sharky_orchestrator_secret('SHARKY_ORCHESTRATOR_LAB_ENABLED')!=='1'){
                hache_sharky_outbox_release_owner($pdo,$id,$owner);break;
            }
            $sendResult=false;try{$sendResult=$sender($payload);}catch(Throwable $e){$sendResult=false;}
            $ok=$sendResult===true||(is_array($sendResult)&&($sendResult['ok']??false)===true);
            $providerMessageId=is_array($sendResult)?trim((string)($sendResult['provider_message_id']??'')):'';
            if($ok){
                if(hache_sharky_outbox_mark_sent($pdo,$id,$owner,$providerMessageId)){
                    $stats['sent']++;
                    if(is_array($followupMeta))hache_sharky_followup_after_sent($pdo,$contact,$followupMeta,time());
                    elseif(is_array($followupArm))hache_sharky_followup_after_normal_sent($pdo,$contact,$followupArm,time());
                }else error_log('[sharky-outbox] sender succeeded but sent marker failed');
            }else{
                if(hache_sharky_outbox_mark_failed($pdo,$id,$owner,(int)$row['attempt_count']))$stats['failed']++;
            }
        }finally{
            if(is_resource($deliveryLock))hache_sharky_orchestrator_unlock($deliveryLock);
        }
    }
    return $stats;
}
