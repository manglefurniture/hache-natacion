<?php

declare(strict_types=1);

require_once __DIR__.'/sharky-human-intervention.php';

/**
 * Pick the newest durable customer receipt for the current manual-grace turn.
 * Rows are expected in durable inbox order; equal/unknown timestamps therefore
 * prefer the later row instead of moving the turn backwards.
 */
function hache_sharky_human_latest_customer_row(array $rows,int $startedAtMs=0): ?array
{
    $latest=null;$latestMs=-1;
    foreach($rows as $row){
        $event=is_array($row['event']??null)?$row['event']:[];
        $id=trim((string)($row['message_id']??''));if($id==='')continue;
        $eventMs=max(0,(int)($event['timestamp_ms']??0));
        if($startedAtMs>0&&$eventMs>0&&$eventMs+5000<$startedAtMs)continue;
        if($latest===null||$eventMs>=$latestMs){$latest=['message_id'=>$id,'event'=>$event,'timestamp_ms'=>$eventMs];$latestMs=$eventMs;}
    }
    return $latest;
}

function hache_sharky_human_latest_pending_customer_event(PDO $pdo,string $contact): ?array
{
    $state=hache_sharky_human_grace_read($contact);$startedAtMs=is_array($state)?max(0,(int)($state['started_at']??0))*1000:0;
    return hache_sharky_human_latest_customer_row(hache_sharky_human_pending_customer_events($pdo,$contact),$startedAtMs);
}

function hache_sharky_human_affirmative_turn(string $text): bool
{
    $parts=preg_split('/\R+/u',trim($text))?:[];$seenAffirmative=false;
    foreach($parts as $part){
        $part=trim((string)$part);if($part==='')continue;
        if(!$seenAffirmative){if(!hache_sharky_human_affirmative($part))return false;$seenAffirmative=true;continue;}
        $t=hache_sharky_safe_side_normalize($part);$t=preg_replace('/^[¿?¡!.,;:\s]+|[¿?¡!.,;:\s]+$/u','',$t)??$t;
        if(preg_match('/^(?:gracias|muchas gracias|mil gracias|ok|okay|vale|listo|lista|perfecto|perfecta)$/u',trim($t))===1)continue;
        return false;
    }
    return $seenAffirmative;
}

/**
 * Wait only for the exceptional manual-grace path. The webhook has already
 * acknowledged Meta before this runs, so the customer request is not held open.
 * A durable inbox lease lets the normal inbox worker recover if this process dies.
 *
 * @return array{active:bool,ready:bool,handled:bool,human_event_id:string,due_at:int}
 */
function hache_sharky_human_grace_wait(PDO $pdo,string $contact,string $eventId,int $eventTimestampMs=0): array
{
    $turn=hache_sharky_human_grace_customer_turn($contact,$eventId,null,$eventTimestampMs);
    if(($turn['active']??false)!==true)return ['active'=>false,'ready'=>true,'handled'=>false,'human_event_id'=>'','due_at'=>0];
    $dueAt=(int)($turn['due_at']??0);$humanId=(string)($turn['human_event_id']??'');
    if(($turn['latest']??false)!==true)return ['active'=>true,'ready'=>false,'handled'=>false,'human_event_id'=>$humanId,'due_at'=>$dueAt];
    if($dueAt<=0||!hache_sharky_human_inbox_defer_until($pdo,$eventId,$dueAt))return ['active'=>true,'ready'=>false,'handled'=>false,'human_event_id'=>$humanId,'due_at'=>$dueAt];

    $started=microtime(true);
    while(true){
        if(hache_sharky_human_inbox_processed($pdo,$eventId))return ['active'=>true,'ready'=>false,'handled'=>true,'human_event_id'=>$humanId,'due_at'=>$dueAt];
        $state=hache_sharky_human_grace_read($contact);
        if(!is_array($state))return ['active'=>false,'ready'=>true,'handled'=>false,'human_event_id'=>'','due_at'=>0];
        if(!hash_equals($humanId,(string)($state['human_event_id']??'')))return ['active'=>true,'ready'=>false,'handled'=>false,'human_event_id'=>$humanId,'due_at'=>$dueAt];
        if((string)($state['latest_customer_event_id']??'')!==$eventId){$newDue=(int)($state['due_at']??0);if($newDue>0)hache_sharky_human_inbox_defer_until($pdo,$eventId,$newDue);return ['active'=>true,'ready'=>false,'handled'=>false,'human_event_id'=>$humanId,'due_at'=>$newDue];}
        $dueAt=(int)($state['due_at']??0);$remaining=$dueAt-time();
        if($remaining<=0){
            // Close the race where a newer customer webhook is already durable
            // but its post-ACK process has not yet advanced manual_grace state.
            $latest=hache_sharky_human_latest_pending_customer_event($pdo,$contact);
            $latestId=trim((string)($latest['message_id']??''));
            if($latestId!==''&&$latestId!==$eventId){
                $latestTurn=hache_sharky_human_grace_customer_turn($contact,$latestId,null,(int)($latest['timestamp_ms']??0));
                $newDue=(int)($latestTurn['due_at']??$dueAt);if($newDue>0)hache_sharky_human_inbox_defer_until($pdo,$eventId,$newDue);
                return ['active'=>true,'ready'=>false,'handled'=>false,'human_event_id'=>$humanId,'due_at'=>$newDue];
            }
            if(hache_sharky_human_inbox_release_lease($pdo,$eventId))return ['active'=>true,'ready'=>true,'handled'=>false,'human_event_id'=>$humanId,'due_at'=>$dueAt];
            if(hache_sharky_human_inbox_processed($pdo,$eventId))return ['active'=>true,'ready'=>false,'handled'=>true,'human_event_id'=>$humanId,'due_at'=>$dueAt];
            return ['active'=>true,'ready'=>false,'handled'=>false,'human_event_id'=>$humanId,'due_at'=>$dueAt];
        }
        if((microtime(true)-$started)>=HACHE_SHARKY_HUMAN_GRACE_MAX_WAIT_SECONDS)return ['active'=>true,'ready'=>false,'handled'=>false,'human_event_id'=>$humanId,'due_at'=>$dueAt];
        usleep((int)(min(1.0,max(0.1,(float)$remaining))*1000000));
    }
}

/**
 * Merge only ordinary text messages from the same grace turn. Interactive and
 * media receipts stay separate so existing protected routing keeps authority.
 *
 * @return array{text:string,ids:list<string>}
 */
function hache_sharky_human_grace_text_turn(PDO $pdo,string $contact,string $currentEventId,string $fallbackText): array
{
    $parts=[];$ids=[];
    foreach(hache_sharky_human_pending_customer_events($pdo,$contact) as $row){
        $event=is_array($row['event']??null)?$row['event']:[];
        if((string)($event['type']??'text')!=='text'||trim((string)($event['interactive_id']??''))!=='')continue;
        $text=trim((string)($event['text']??''));$id=trim((string)($row['message_id']??''));
        if($text===''||$id==='')continue;
        $parts[]=$text;$ids[]=$id;
    }
    if(!$parts){$parts=[trim($fallbackText)];$ids=[$currentEventId];}
    $parts=array_values(array_filter($parts,static fn(string $v):bool=>$v!==''));
    $ids=array_values(array_unique(array_filter($ids,static fn(string $v):bool=>$v!=='')));
    return ['text'=>mb_substr(implode("\n",$parts),0,1400),'ids'=>$ids?:[$currentEventId]];
}

/**
 * A manual echo may share a webhook batch with a later customer message. Absorb
 * only customer receipts that are not newer than the human echo, so echo-first
 * processing never discards a reply that arrived after the operator wrote.
 */
function hache_sharky_human_absorb_before_echo(PDO $pdo,string $contact,array $echo): int
{
    $echoMs=max(0,(int)($echo['timestamp_ms']??0));$absorbed=0;
    foreach(hache_sharky_human_pending_customer_events($pdo,$contact) as $row){
        $event=is_array($row['event']??null)?$row['event']:[];$eventMs=max(0,(int)($event['timestamp_ms']??0));
        if($echoMs>0&&$eventMs>0&&$eventMs>$echoMs)continue;
        $id=trim((string)($row['message_id']??''));if($id===''||!hache_sharky_orchestrator_mark_processed($pdo,$id))continue;
        $absorbed++;
    }
    return $absorbed;
}
