<?php

declare(strict_types=1);

require_once __DIR__.'/sharky-orchestrator.php';

function hache_sharky_orchestrator_contact_hash(string $contact): string
{
    $digits = preg_replace('/\D+/', '', $contact) ?: '';
    return hash('sha256', 'hache-sharky-contact-v2|'.$digits);
}

function hache_sharky_orchestrator_store_ready(PDO $pdo): bool
{
    static $memo = [];
    $key = spl_object_id($pdo);
    if (array_key_exists($key, $memo)) return $memo[$key];
    try {
        foreach (['sharky_message_receipts','sharky_referrals','sharky_action_audit'] as $table) {
            $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:t');
            $st->execute([':t'=>$table]);
            if ((int)$st->fetchColumn() !== 1) return $memo[$key] = false;
        }
        return $memo[$key] = true;
    } catch (Throwable $e) {
        return $memo[$key] = false;
    }
}

function hache_sharky_orchestrator_claim_message(PDO $pdo, string $messageId, string $contactHash, string $type): bool
{
    $messageId = trim($messageId);
    if ($messageId === '' || !hache_sharky_orchestrator_store_ready($pdo)) return false;
    try {
        $st = $pdo->prepare('INSERT IGNORE INTO sharky_message_receipts(message_id,contact_hash,message_type) VALUES(:m,:c,:t)');
        $st->execute([':m'=>mb_substr($messageId,0,191),':c'=>$contactHash,':t'=>mb_substr($type,0,30)]);
        return $st->rowCount() === 1;
    } catch (Throwable $e) {
        error_log('[sharky-orchestrator] durable message claim failed');
        return false;
    }
}

function hache_sharky_orchestrator_mark_processed(PDO $pdo, string $messageId): void
{
    if (!hache_sharky_orchestrator_store_ready($pdo)) return;
    try {
        $st=$pdo->prepare('UPDATE sharky_message_receipts SET processed_at=COALESCE(processed_at,NOW()) WHERE message_id=:m');
        $st->execute([':m'=>mb_substr(trim($messageId),0,191)]);
    } catch (Throwable $e) {
        error_log('[sharky-orchestrator] message processed mark failed');
    }
}

function hache_sharky_orchestrator_store_referral(PDO $pdo, string $messageId, string $contactHash, array $referral, ?string $studentId = null): bool
{
    if (!hache_sharky_orchestrator_store_ready($pdo)) return false;
    $messageId=trim($messageId);
    if($messageId==='') return false;
    try {
        $referralJson=json_encode($referral,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $sql='INSERT IGNORE INTO sharky_referrals('
            .'message_id,contact_hash,alumno_id,source_type,source_id,ctwa_clid,source_url,headline,body,media_type,image_url,video_url,thumbnail_url,referral_json,captured_at'
            .') VALUES(:m,:c,:a,:st,:si,:clid,:url,:h,:b,:mt,:img,:vid,:thumb,:json,NOW())';
        $st=$pdo->prepare($sql);
        $st->execute([
            ':m'=>mb_substr($messageId,0,191),':c'=>$contactHash,':a'=>$studentId,
            ':st'=>isset($referral['source_type'])?mb_substr((string)$referral['source_type'],0,30):null,
            ':si'=>isset($referral['source_id'])?mb_substr((string)$referral['source_id'],0,191):null,
            ':clid'=>isset($referral['ctwa_clid'])?mb_substr((string)$referral['ctwa_clid'],0,255):null,
            ':url'=>isset($referral['source_url'])?mb_substr((string)$referral['source_url'],0,1000):null,
            ':h'=>isset($referral['headline'])?mb_substr((string)$referral['headline'],0,500):null,
            ':b'=>isset($referral['body'])?mb_substr((string)$referral['body'],0,1000):null,
            ':mt'=>isset($referral['media_type'])?mb_substr((string)$referral['media_type'],0,30):null,
            ':img'=>isset($referral['image_url'])?mb_substr((string)$referral['image_url'],0,1000):null,
            ':vid'=>isset($referral['video_url'])?mb_substr((string)$referral['video_url'],0,1000):null,
            ':thumb'=>isset($referral['thumbnail_url'])?mb_substr((string)$referral['thumbnail_url'],0,1000):null,
            ':json'=>$referralJson===false?null:$referralJson,
        ]);
        return $st->rowCount() === 1;
    } catch (Throwable $e) {
        error_log('[sharky-orchestrator] referral persistence failed');
        return false;
    }
}

function hache_sharky_orchestrator_action_begin(PDO $pdo, string $idempotencyKey, string $actionType, string $contactHash, ?string $studentId, array $payload): bool
{
    if (!hache_sharky_orchestrator_store_ready($pdo)) return false;
    $key=hash('sha256',$idempotencyKey);
    $payloadHash=hash('sha256',json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:'');
    try {
        $st=$pdo->prepare("INSERT IGNORE INTO sharky_action_audit(idempotency_key,action_type,contact_hash,alumno_id,status,payload_hash) VALUES(:k,:t,:c,:a,'PENDING',:p)");
        $st->execute([':k'=>$key,':t'=>mb_substr($actionType,0,60),':c'=>$contactHash,':a'=>$studentId,':p'=>$payloadHash]);
        return $st->rowCount()===1;
    } catch(Throwable $e){
        error_log('[sharky-orchestrator] action claim failed');
        return false;
    }
}

function hache_sharky_orchestrator_action_finish(PDO $pdo, string $idempotencyKey, bool $ok, string $resultCode): void
{
    if (!hache_sharky_orchestrator_store_ready($pdo)) return;
    try {
        $st=$pdo->prepare("UPDATE sharky_action_audit SET status=:s,result_code=:r,completed_at=NOW() WHERE idempotency_key=:k AND status='PENDING'");
        $st->execute([':s'=>$ok?'COMPLETED':'FAILED',':r'=>mb_substr($resultCode,0,80),':k'=>hash('sha256',$idempotencyKey)]);
    } catch(Throwable $e){
        error_log('[sharky-orchestrator] action finish failed');
    }
}

function hache_sharky_orchestrator_runtime_dir(string $kind): string
{
    $root = is_dir('/var/tmp') && is_writable('/var/tmp') ? '/var/tmp' : sys_get_temp_dir();
    $dir = rtrim($root,'/').'/hache-sharky-'.$kind;
    if(!is_dir($dir) && !@mkdir($dir,0700,true) && !is_dir($dir)) return '';
    @chmod($dir,0700);
    return $dir;
}

function hache_sharky_orchestrator_state_path(string $contact): string
{
    $dir=hache_sharky_orchestrator_runtime_dir('state');
    return $dir===''?'':$dir.'/'.hache_sharky_orchestrator_contact_hash($contact).'.json';
}

function hache_sharky_orchestrator_state_load(string $contact): array
{
    $path=hache_sharky_orchestrator_state_path($contact);
    if($path===''||!is_file($path)) return hache_sharky_orchestrator_state();
    $raw=@file_get_contents($path);
    $state=is_string($raw)?json_decode($raw,true):null;
    if(!is_array($state)||(int)($state['updated_at']??0)<time()-86400){
        @unlink($path);
        return hache_sharky_orchestrator_state();
    }
    return hache_sharky_orchestrator_state($state);
}

function hache_sharky_orchestrator_state_save(string $contact,array $state): bool
{
    $path=hache_sharky_orchestrator_state_path($contact);
    if($path==='') return false;
    $json=json_encode($state,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if($json===false) return false;
    $tmp=$path.'.'.bin2hex(random_bytes(4)).'.tmp';
    if(@file_put_contents($tmp,$json,LOCK_EX)===false) return false;
    @chmod($tmp,0600);
    if(!@rename($tmp,$path)){@unlink($tmp);return false;}
    @chmod($path,0600);
    return true;
}

function hache_sharky_orchestrator_lock(string $contact)
{
    $dir=hache_sharky_orchestrator_runtime_dir('locks');
    if($dir==='') return null;
    $path=$dir.'/'.hache_sharky_orchestrator_contact_hash($contact).'.lock';
    $fh=@fopen($path,'c');
    if($fh===false) return null;
    @chmod($path,0600);
    if(!flock($fh,LOCK_EX)){fclose($fh);return null;}
    return $fh;
}

function hache_sharky_orchestrator_unlock($lock): void
{
    if(!is_resource($lock)) return;
    flock($lock,LOCK_UN);
    fclose($lock);
}

function hache_sharky_orchestrator_batch_enqueue_and_wait(string $contact,array $event,int $windowMs=HACHE_SHARKY_BATCH_WINDOW_MS): ?array
{
    $windowMs=max(200,min(5000,$windowMs));
    $dir=hache_sharky_orchestrator_runtime_dir('batch');
    if($dir==='') return hache_sharky_orchestrator_batch([$event]);
    $hash=hache_sharky_orchestrator_contact_hash($contact);
    $queue=$dir.'/'.$hash.'.json';
    $lockPath=$dir.'/'.$hash.'.lock';
    $lock=@fopen($lockPath,'c');
    if($lock===false) return hache_sharky_orchestrator_batch([$event]);
    flock($lock,LOCK_EX);
    $stored=is_file($queue)?json_decode((string)@file_get_contents($queue),true):null;
    $nowMs=(int)floor(microtime(true)*1000);
    if(!is_array($stored))$stored=['first_at_ms'=>$nowMs,'flush_at_ms'=>$nowMs+$windowMs,'events'=>[]];
    $stored['events'][]=$event;
    $stored['events']=array_slice($stored['events'],-8);
    $hard=(int)$stored['first_at_ms']+8000;
    $stored['flush_at_ms']=min($hard,$nowMs+$windowMs);
    @file_put_contents($queue,json_encode($stored,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),LOCK_EX);
    @chmod($queue,0600);
    flock($lock,LOCK_UN);fclose($lock);

    usleep($windowMs*1000);

    $lock=@fopen($lockPath,'c');
    if($lock===false) return null;
    flock($lock,LOCK_EX);
    $stored=is_file($queue)?json_decode((string)@file_get_contents($queue),true):null;
    $nowMs=(int)floor(microtime(true)*1000);
    if(!is_array($stored)||$nowMs<(int)($stored['flush_at_ms']??PHP_INT_MAX)){
        flock($lock,LOCK_UN);fclose($lock);return null;
    }
    @unlink($queue);
    flock($lock,LOCK_UN);fclose($lock);
    return hache_sharky_orchestrator_batch(is_array($stored['events']??null)?$stored['events']:[]);
}
