<?php

declare(strict_types=1);

require_once __DIR__.'/sharky-human-intervention.php';

/**
 * Wait only for the exceptional manual-grace path. The webhook has already
 * acknowledged Meta before this runs, so the customer request is not held open.
 * A durable inbox lease lets the normal inbox worker recover if this process dies.
 *
 * @return array{active:bool,ready:bool,handled:bool,human_event_id:string,due_at:int}
 */
function hache_sharky_human_grace_wait(PDO $pdo,string $contact,string $eventId): array
{
    $turn=hache_sharky_human_grace_customer_turn($contact,$eventId);
    if(($turn['active']??false)!==true)return ['active'=>false,'ready'=>true,'handled'=>false,'human_event_id'=>'','due_at'=>0];
    $dueAt=(int)($turn['due_at']??0);$humanId=(string)($turn['human_event_id']??'');
    if($dueAt<=0||!hache_sharky_human_inbox_defer_until($pdo,$eventId,$dueAt))return ['active'=>true,'ready'=>false,'handled'=>false,'human_event_id'=>$humanId,'due_at'=>$dueAt];

    $started=microtime(true);
    while(true){
        if(hache_sharky_human_inbox_processed($pdo,$eventId))return ['active'=>true,'ready'=>false,'handled'=>true,'human_event_id'=>$humanId,'due_at'=>$dueAt];
        $state=hache_sharky_human_grace_read($contact);
        if(!is_array($state))return ['active'=>false,'ready'=>true,'handled'=>false,'human_event_id'=>'','due_at'=>0];
        if(!hash_equals($humanId,(string)($state['human_event_id']??'')))return ['active'=>true,'ready'=>false,'handled'=>false,'human_event_id'=>$humanId,'due_at'=>$dueAt];
        if((string)($state['latest_customer_event_id']??'')!==$eventId)return ['active'=>true,'ready'=>false,'handled'=>false,'human_event_id'=>$humanId,'due_at'=>(int)($state['due_at']??0)];
        $dueAt=(int)($state['due_at']??0);$remaining=$dueAt-time();
        if($remaining<=0)return ['active'=>true,'ready'=>true,'handled'=>false,'human_event_id'=>$humanId,'due_at'=>$dueAt];
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
