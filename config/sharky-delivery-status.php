<?php

declare(strict_types=1);

/** @return array<string,int> */
function hache_sharky_delivery_status_ranks(): array
{
    return ['FAILED'=>0,'SENT'=>10,'DELIVERED'=>20,'READ'=>30];
}

function hache_sharky_delivery_schema_ready(PDO $pdo): bool
{
    static $memo=[];
    $key=spl_object_id($pdo);
    if(array_key_exists($key,$memo))return $memo[$key];
    try{
        $st=$pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='sharky_outbox' AND column_name='provider_message_id'");
        $st->execute();
        if((int)$st->fetchColumn()!==1)return $memo[$key]=false;
        $st=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='sharky_delivery_status'");
        $st->execute();
        return $memo[$key]=((int)$st->fetchColumn()===1);
    }catch(Throwable $e){
        return $memo[$key]=false;
    }
}

/** @return list<array{provider_message_id:string,status:string,status_rank:int,provider_event_at_utc:string,phone_number_id:string}> */
function hache_sharky_delivery_extract(array $payload): array
{
    $ranks=hache_sharky_delivery_status_ranks();$events=[];
    foreach(($payload['entry']??[]) as $entry){
        if(!is_array($entry))continue;
        foreach(($entry['changes']??[]) as $change){
            if(!is_array($change))continue;
            $value=$change['value']??null;if(!is_array($value))continue;
            $phoneId=trim((string)($value['metadata']['phone_number_id']??''));
            foreach(($value['statuses']??[]) as $status){
                if(!is_array($status))continue;
                $providerId=trim((string)($status['id']??''));
                $state=strtoupper(trim((string)($status['status']??'')));
                $timestamp=trim((string)($status['timestamp']??''));
                if($providerId===''||strlen($providerId)>191||!isset($ranks[$state]))continue;
                if(!preg_match('/^[0-9]{1,12}$/',$timestamp))continue;
                $epoch=(int)$timestamp;if($epoch<=0)continue;
                $events[]=[
                    'provider_message_id'=>$providerId,
                    'status'=>$state,
                    'status_rank'=>$ranks[$state],
                    'provider_event_at_utc'=>gmdate('Y-m-d H:i:s',$epoch),
                    'phone_number_id'=>$phoneId,
                ];
            }
        }
    }
    return $events;
}

function hache_sharky_delivery_store_event(PDO $pdo,array $event): bool
{
    if(!hache_sharky_delivery_schema_ready($pdo))return false;
    try{
        $sql="INSERT INTO sharky_delivery_status(provider_message_id,status,status_rank,provider_event_at_utc)
              VALUES(:id,:s,:r,:at)
              ON DUPLICATE KEY UPDATE
                status=IF(VALUES(provider_event_at_utc)>provider_event_at_utc OR (VALUES(provider_event_at_utc)=provider_event_at_utc AND VALUES(status_rank)>status_rank),VALUES(status),status),
                status_rank=IF(VALUES(provider_event_at_utc)>provider_event_at_utc OR (VALUES(provider_event_at_utc)=provider_event_at_utc AND VALUES(status_rank)>status_rank),VALUES(status_rank),status_rank),
                provider_event_at_utc=IF(VALUES(provider_event_at_utc)>provider_event_at_utc,VALUES(provider_event_at_utc),provider_event_at_utc)";
        $st=$pdo->prepare($sql);
        $st->execute([':id'=>(string)$event['provider_message_id'],':s'=>(string)$event['status'],':r'=>(int)$event['status_rank'],':at'=>(string)$event['provider_event_at_utc']]);
        return true;
    }catch(Throwable $e){error_log('[sharky-delivery] status persistence failed');return false;}
}

/** @return array{seen:int,eligible:int,stored:int,schema_ready:bool} */
function hache_sharky_delivery_store_payload(PDO $pdo,array $payload,string $configuredPhoneId=''): array
{
    $events=hache_sharky_delivery_extract($payload);$seen=count($events);$eligible=[];
    foreach($events as $event){
        $phoneId=(string)$event['phone_number_id'];
        if($configuredPhoneId!==''&&$phoneId!==''&&!hash_equals($configuredPhoneId,$phoneId))continue;
        $eligible[]=$event;
    }
    $ready=hache_sharky_delivery_schema_ready($pdo);
    if(!$ready)return ['seen'=>$seen,'eligible'=>count($eligible),'stored'=>0,'schema_ready'=>false];
    $stored=0;foreach($eligible as $event)if(hache_sharky_delivery_store_event($pdo,$event))$stored++;
    return ['seen'=>$seen,'eligible'=>count($eligible),'stored'=>$stored,'schema_ready'=>true];
}

function hache_sharky_delivery_provider_message_id(string $response): string
{
    $data=json_decode($response,true);if(!is_array($data))return '';
    $messages=$data['messages']??null;if(!is_array($messages)||!is_array($messages[0]??null))return '';
    $id=trim((string)($messages[0]['id']??''));return $id!==''&&strlen($id)<=191?$id:'';
}

/** @return array{ok:bool,provider_message_id:string} */
function hache_sharky_delivery_meta_send(array $payload): array
{
    if(function_exists('hache_sharky_groups_finalize_outbound'))$payload=hache_sharky_groups_finalize_outbound($payload);
    if(!function_exists('hache_sharky_orchestrator_secret'))return ['ok'=>false,'provider_message_id'=>''];
    if(hache_sharky_orchestrator_secret('SHARKY_ORCHESTRATOR_LAB_ENABLED')!=='1')return ['ok'=>false,'provider_message_id'=>''];
    $token=hache_sharky_orchestrator_secret('WHATSAPP_ACCESS_TOKEN');$phoneId=hache_sharky_orchestrator_secret('WHATSAPP_PHONE_NUMBER_ID');$version=hache_sharky_orchestrator_secret('WHATSAPP_GRAPH_VERSION');
    if(!preg_match('/^v\d+\.\d+$/',$version))$version='v26.0';
    if($token===''||$phoneId==='')return ['ok'=>false,'provider_message_id'=>''];
    $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($json===false)return ['ok'=>false,'provider_message_id'=>''];
    $ch=curl_init('https://graph.facebook.com/'.rawurlencode($version).'/'.rawurlencode($phoneId).'/messages');
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$token],CURLOPT_POSTFIELDS=>$json]);
    $response=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);
    $ok=is_string($response)&&$error===''&&$status>=200&&$status<300;
    return ['ok'=>$ok,'provider_message_id'=>$ok?hache_sharky_delivery_provider_message_id($response):''];
}

/** @return array{correlated_total:int,status_counts:array<string,int>,latest_provider_event_at_utc:?string} */
function hache_sharky_delivery_correlated_summary(PDO $pdo): array
{
    $counts=['SENT'=>0,'DELIVERED'=>0,'READ'=>0,'FAILED'=>0];
    if(!hache_sharky_delivery_schema_ready($pdo))return ['correlated_total'=>0,'status_counts'=>$counts,'latest_provider_event_at_utc'=>null];
    try{
        $st=$pdo->query("SELECT d.status,COUNT(*) total FROM sharky_delivery_status d INNER JOIN sharky_outbox o ON o.provider_message_id=d.provider_message_id GROUP BY d.status");
        foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){$status=strtoupper((string)($row['status']??''));if(isset($counts[$status]))$counts[$status]=max(0,(int)($row['total']??0));}
        $latest=$pdo->query("SELECT MAX(d.provider_event_at_utc) FROM sharky_delivery_status d INNER JOIN sharky_outbox o ON o.provider_message_id=d.provider_message_id")->fetchColumn();
        return ['correlated_total'=>array_sum($counts),'status_counts'=>$counts,'latest_provider_event_at_utc'=>$latest===false||$latest===null||$latest===''?null:(string)$latest];
    }catch(Throwable $e){return ['correlated_total'=>0,'status_counts'=>$counts,'latest_provider_event_at_utc'=>null];}
}
