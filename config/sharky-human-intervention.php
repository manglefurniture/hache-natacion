<?php

declare(strict_types=1);

require_once __DIR__.'/sharky-inbox.php';
require_once __DIR__.'/sharky-safe-side-question.php';

const HACHE_SHARKY_HUMAN_GRACE_SECONDS=30;
const HACHE_SHARKY_HUMAN_GRACE_MAX_WAIT_SECONDS=75;

function hache_sharky_human_operator_normalize(string $text): string
{
    $text=mb_strtolower(trim($text),'UTF-8');
    $text=preg_replace('/\s+/u',' ',$text)??$text;
    $text=preg_replace('/^[¿?¡!.,;:\s]+|[¿?¡!.,;:\s]+$/u','',$text)??$text;
    return trim($text);
}

function hache_sharky_human_operator_command(array $echo): ?string
{
    $declared=strtolower(trim((string)($echo['operator_command']??'')));
    if(in_array($declared,['sleep','wake'],true))return $declared;
    $type=trim((string)($echo['type']??''));
    if($type!==''&&$type!=='text')return null;
    $text=hache_sharky_human_operator_normalize((string)($echo['text']??''));
    if($text==='sharky duerme')return 'sleep';
    if($text==='sharky despierta')return 'wake';
    return null;
}

function hache_sharky_human_command_text(string $text): bool
{
    $normalized=hache_sharky_human_operator_normalize($text);
    return in_array($normalized,['sharky duerme','sharky despierta'],true);
}

function hache_sharky_human_local_date(?int $now=null): string
{
    $tz=new DateTimeZone('America/Cancun');
    $date=new DateTimeImmutable('@'.($now??time()));
    return $date->setTimezone($tz)->format('Y-m-d');
}

function hache_sharky_human_grace_path(string $contact): string
{
    $contact=preg_replace('/\D+/','',$contact)?:'';if($contact==='')return '';
    $dir=hache_sharky_orchestrator_runtime_dir('human-grace');
    return $dir===''?'':$dir.'/'.hache_sharky_orchestrator_contact_hash($contact).'.json';
}

function hache_sharky_human_grace_lock(string $contact)
{
    $contact=preg_replace('/\D+/','',$contact)?:'';if($contact==='')return null;
    $dir=hache_sharky_orchestrator_runtime_dir('human-grace-locks');if($dir==='')return null;
    $path=$dir.'/'.hache_sharky_orchestrator_contact_hash($contact).'.lock';$fh=@fopen($path,'c');if($fh===false)return null;
    @chmod($path,0600);if(!flock($fh,LOCK_EX)){fclose($fh);return null;}return $fh;
}

function hache_sharky_human_grace_unlock($lock): void
{
    if(!is_resource($lock))return;flock($lock,LOCK_UN);fclose($lock);
}

function hache_sharky_human_grace_read_unlocked(string $contact,?int $now=null): ?array
{
    $path=hache_sharky_human_grace_path($contact);if($path===''||!is_file($path))return null;
    $data=json_decode((string)@file_get_contents($path),true);if(!is_array($data)){@unlink($path);return null;}
    $today=hache_sharky_human_local_date($now);
    if((string)($data['local_date']??'')!==$today){@unlink($path);return null;}
    $humanId=trim((string)($data['human_event_id']??''));if($humanId===''){@unlink($path);return null;}
    return $data;
}

function hache_sharky_human_grace_read(string $contact,?int $now=null): ?array
{
    $lock=hache_sharky_human_grace_lock($contact);if(!is_resource($lock))return hache_sharky_human_grace_read_unlocked($contact,$now);
    try{return hache_sharky_human_grace_read_unlocked($contact,$now);}finally{hache_sharky_human_grace_unlock($lock);}
}

function hache_sharky_human_grace_write_unlocked(string $contact,array $data): bool
{
    $path=hache_sharky_human_grace_path($contact);if($path==='')return false;
    $json=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($json===false)return false;
    $tmp=$path.'.'.bin2hex(random_bytes(4)).'.tmp';if(@file_put_contents($tmp,$json,LOCK_EX)===false)return false;
    @chmod($tmp,0600);if(!@rename($tmp,$path)){@unlink($tmp);return false;}@chmod($path,0600);return true;
}

function hache_sharky_human_grace_mark(string $contact,string $humanEventId,?int $now=null): bool
{
    $contact=preg_replace('/\D+/','',$contact)?:'';$humanEventId=mb_substr(trim($humanEventId),0,191);if($contact===''||$humanEventId==='')return false;
    $now??=time();$lock=hache_sharky_human_grace_lock($contact);if(!is_resource($lock))return false;
    try{
        return hache_sharky_human_grace_write_unlocked($contact,[
            'contact_hash'=>hache_sharky_orchestrator_contact_hash($contact),
            'human_event_id'=>$humanEventId,
            'local_date'=>hache_sharky_human_local_date($now),
            'started_at'=>$now,
            'due_at'=>0,
            'latest_customer_event_id'=>'',
            'latest_customer_timestamp_ms'=>0,
            'seen_customer_event_ids'=>[],
            'updated_at'=>$now,
        ]);
    }finally{hache_sharky_human_grace_unlock($lock);}
}

function hache_sharky_human_grace_clear(string $contact): bool
{
    $path=hache_sharky_human_grace_path($contact);if($path==='')return false;
    $lock=hache_sharky_human_grace_lock($contact);if(!is_resource($lock))return !is_file($path)||@unlink($path);
    try{return !is_file($path)||@unlink($path);}finally{hache_sharky_human_grace_unlock($lock);}
}

/**
 * Register one customer turn after a human reply. Replays of a durable receipt
 * never extend the window or move the latest pointer backwards. A genuinely
 * newer customer message moves the deadline from its original WhatsApp time.
 *
 * @return array{active:bool,due_at:int,latest:bool,human_event_id:string}
 */
function hache_sharky_human_grace_customer_turn(string $contact,string $eventId,?int $now=null,int $eventTimestampMs=0): array
{
    $eventId=mb_substr(trim($eventId),0,191);$now??=time();$eventTimestampMs=max(0,$eventTimestampMs);
    if($eventId==='')return ['active'=>false,'due_at'=>0,'latest'=>false,'human_event_id'=>''];
    $lock=hache_sharky_human_grace_lock($contact);if(!is_resource($lock))return ['active'=>false,'due_at'=>0,'latest'=>false,'human_event_id'=>''];
    try{
        $data=hache_sharky_human_grace_read_unlocked($contact,$now);if(!is_array($data))return ['active'=>false,'due_at'=>0,'latest'=>false,'human_event_id'=>''];
        $seen=[];foreach((array)($data['seen_customer_event_ids']??[]) as $seenId){$seenId=mb_substr(trim((string)$seenId),0,191);if($seenId!==''&&!in_array($seenId,$seen,true))$seen[]=$seenId;}
        $alreadySeen=in_array($eventId,$seen,true);
        if(!$alreadySeen){
            $seen[]=$eventId;if(count($seen)>24)$seen=array_slice($seen,-24);$data['seen_customer_event_ids']=$seen;
            $latest=(string)($data['latest_customer_event_id']??'');$latestTimestampMs=max(0,(int)($data['latest_customer_timestamp_ms']??0));
            $isNewer=$latest===''||$latestTimestampMs<=0||$eventTimestampMs<=0||$eventTimestampMs>$latestTimestampMs||($eventTimestampMs===$latestTimestampMs&&$latest!==$eventId);
            if($isNewer){
                $eventAt=$eventTimestampMs>0?intdiv($eventTimestampMs,1000):$now;
                if($eventAt<=0||$eventAt>$now+300)$eventAt=$now;
                $data['latest_customer_event_id']=$eventId;
                $data['latest_customer_timestamp_ms']=$eventTimestampMs;
                $data['due_at']=$eventAt+HACHE_SHARKY_HUMAN_GRACE_SECONDS;
                $data['updated_at']=$now;
            }
            if(!hache_sharky_human_grace_write_unlocked($contact,$data))return ['active'=>false,'due_at'=>0,'latest'=>false,'human_event_id'=>''];
        }
        return ['active'=>true,'due_at'=>(int)($data['due_at']??0),'latest'=>(string)($data['latest_customer_event_id']??'')===$eventId,'human_event_id'=>(string)$data['human_event_id']];
    }finally{hache_sharky_human_grace_unlock($lock);}
}

function hache_sharky_human_grace_clear_if_current(string $contact,string $humanEventId,string $customerEventId): bool
{
    $lock=hache_sharky_human_grace_lock($contact);if(!is_resource($lock))return false;
    try{
        $data=hache_sharky_human_grace_read_unlocked($contact);if(!is_array($data))return true;
        if(!hash_equals((string)($data['human_event_id']??''),$humanEventId))return false;
        if(!hash_equals((string)($data['latest_customer_event_id']??''),$customerEventId))return false;
        $path=hache_sharky_human_grace_path($contact);return $path!==''&&(!is_file($path)||@unlink($path));
    }finally{hache_sharky_human_grace_unlock($lock);}
}

function hache_sharky_human_inbox_defer_until(PDO $pdo,string $messageId,int $dueAt): bool
{
    $messageId=mb_substr(trim($messageId),0,191);if($messageId===''||$dueAt<=0)return false;
    try{
        $st=$pdo->prepare('UPDATE sharky_message_receipts SET lease_until=FROM_UNIXTIME(:d) WHERE message_id=:m AND processed_at IS NULL');
        $st->execute([':d'=>$dueAt,':m'=>$messageId]);if($st->rowCount()===1)return true;
        $check=$pdo->prepare('SELECT 1 FROM sharky_message_receipts WHERE message_id=:m AND processed_at IS NULL LIMIT 1');
        $check->execute([':m'=>$messageId]);return(bool)$check->fetchColumn();
    }catch(Throwable $e){error_log('[sharky-human] unable to defer grace receipt');return false;}
}

function hache_sharky_human_inbox_release_lease(PDO $pdo,string $messageId): bool
{
    $messageId=mb_substr(trim($messageId),0,191);if($messageId==='')return false;
    try{
        $st=$pdo->prepare('UPDATE sharky_message_receipts SET lease_until=NULL WHERE message_id=:m AND processed_at IS NULL');
        $st->execute([':m'=>$messageId]);if($st->rowCount()===1)return true;
        $check=$pdo->prepare('SELECT 1 FROM sharky_message_receipts WHERE message_id=:m AND processed_at IS NULL AND lease_until IS NULL LIMIT 1');
        $check->execute([':m'=>$messageId]);return(bool)$check->fetchColumn();
    }catch(Throwable $e){error_log('[sharky-human] unable to release grace receipt');return false;}
}

function hache_sharky_human_inbox_processed(PDO $pdo,string $messageId): bool
{
    try{$st=$pdo->prepare('SELECT processed_at IS NOT NULL FROM sharky_message_receipts WHERE message_id=:m LIMIT 1');$st->execute([':m'=>mb_substr(trim($messageId),0,191)]);return (int)$st->fetchColumn()===1;}catch(Throwable $e){return false;}
}

function hache_sharky_human_absorb_pending_inbound(PDO $pdo,string $contact): int
{
    try{
        $st=$pdo->prepare("UPDATE sharky_message_receipts SET processed_at=COALESCE(processed_at,NOW()),lease_until=NULL,last_error=COALESCE(last_error,'HANDLED_BY_HUMAN') WHERE contact_hash=:c AND processed_at IS NULL AND message_type<>'echo'");
        $st->execute([':c'=>hache_sharky_orchestrator_contact_hash($contact)]);return $st->rowCount();
    }catch(Throwable $e){error_log('[sharky-human] unable to absorb pending customer receipts');return 0;}
}

/** @return list<array{message_id:string,event:array}> */
function hache_sharky_human_pending_customer_events(PDO $pdo,string $contact): array
{
    try{
        $st=$pdo->prepare("SELECT message_id,payload_ciphertext,payload_iv,payload_tag FROM sharky_message_receipts WHERE contact_hash=:c AND processed_at IS NULL AND message_type<>'echo' AND payload_ciphertext IS NOT NULL ORDER BY received_at,message_id LIMIT 12");
        $st->execute([':c'=>hache_sharky_orchestrator_contact_hash($contact)]);$out=[];
        foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){$event=hache_sharky_inbox_decrypt($row);if(!is_array($event))continue;$out[]=['message_id'=>(string)$row['message_id'],'event'=>$event];}
        return $out;
    }catch(Throwable $e){return [];}
}

function hache_sharky_human_echo_text(PDO $pdo,string $messageId): string
{
    $messageId=mb_substr(trim($messageId),0,191);if($messageId==='')return '';
    try{
        $st=$pdo->prepare('SELECT payload_ciphertext,payload_iv,payload_tag FROM sharky_message_receipts WHERE message_id=:m AND message_type=\'echo\' LIMIT 1');$st->execute([':m'=>$messageId]);$row=$st->fetch(PDO::FETCH_ASSOC);if(!$row)return '';
        $event=hache_sharky_inbox_decrypt($row);$text=is_array($event)?trim((string)($event['text']??'')):'';
        return hache_sharky_human_command_text($text)?'':mb_substr($text,0,700);
    }catch(Throwable $e){return '';}
}

function hache_sharky_human_affirmative(string $text): bool
{
    $t=hache_sharky_safe_side_normalize($text);$t=preg_replace('/^[¿?¡!.,;:\s]+|[¿?¡!.,;:\s]+$/u','',$t)??$t;
    return preg_match('/^(?:si|claro|ok|okay|dale|va|por favor|correcto|correcta|perfecto|perfecta)$/u',trim($t))===1;
}

function hache_sharky_human_safe_context_question(string $text): bool
{
    $t=hache_sharky_safe_side_normalize($text);
    return preg_match('/\b(?:precio|precios|costo|costos|cuesta|mensualidad|duracion|semanas|ubicacion|ubicaciones|direccion|direcciones|donde|maps|mapa|horario|horarios|hora|turno|requisito|requisitos|gorro|goggles|traje|diferencia|diferencias)\b/u',$t)===1;
}

function hache_sharky_human_all_locations_answer(PDO $pdo,string $question): ?string
{
    $t=hache_sharky_safe_side_normalize($question);
    $location=preg_match('/\b(?:ubicacion|ubicaciones|direccion|direcciones|maps|mapa|donde)\b/u',$t)===1;
    $both=preg_match('/\b(?:ubicaciones|direcciones|ambas|las dos|los dos|todas)\b/u',$t)===1;
    if(!$location||!$both)return null;
    $business=hache_sharky_safe_side_business_values($pdo);$mv=trim((string)($business['sharky_maps_monteverde']??''));$pal=trim((string)($business['sharky_maps_palapas']??''));
    if(filter_var($mv,FILTER_VALIDATE_URL)===false||filter_var($pal,FILTER_VALIDATE_URL)===false)return null;
    return "📍 Estas son nuestras dos ubicaciones en Cancún:\n\n• Colegio Monteverde:\n".$mv."\n\n• Palapas Protudec:\n".$pal;
}

/**
 * Resolve a short customer confirmation against the immediately preceding human
 * question without granting the human text authority to execute protected acts.
 */
function hache_sharky_human_contextual_decision(PDO $pdo,array $state,string $humanText,string $customerText): ?array
{
    $humanText=trim($humanText);if($humanText===''||!hache_sharky_human_affirmative($customerText)||!hache_sharky_human_safe_context_question($humanText))return null;
    $answer=hache_sharky_human_all_locations_answer($pdo,$humanText)??hache_sharky_safe_side_answer($pdo,$state,$humanText);if($answer===null)return null;
    if(function_exists('hache_sharky_meta_active')&&hache_sharky_meta_active($state)&&function_exists('hache_sharky_meta_resume_decision')){
        $resume=hache_sharky_meta_resume_decision($state);$prompt=trim((string)($resume['message']??''));if($prompt!=='')$answer=rtrim($answer)."\n\n".$prompt;
        return hache_sharky_orchestrator_decision('human_context_side_question',$answer,is_array($resume['ui']??null)?$resume['ui']:[]);
    }
    return hache_sharky_orchestrator_decision('human_context_side_question',$answer);
}
