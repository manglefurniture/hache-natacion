<?php

declare(strict_types=1);

const HACHE_SHARKY_BIRTHDATE_FLOW_NAME='Hache_Sharky_Birthdate_v1';
const HACHE_SHARKY_BIRTHDATE_FLOW_SCREEN='BIRTHDATE';

function hache_sharky_whatsapp_flows_message_waba_map(array $payload): array
{
    $map=[];
    foreach(($payload['entry']??[]) as $entry){
        if(!is_array($entry))continue;
        $waba=preg_replace('/\D+/','',(string)($entry['id']??''))?:'';
        if($waba==='')continue;
        foreach(($entry['changes']??[]) as $change){
            if(!is_array($change))continue;
            $value=$change['value']??null;if(!is_array($value))continue;
            foreach(($value['messages']??[]) as $message){
                if(!is_array($message))continue;
                $id=trim((string)($message['id']??''));
                if($id!=='')$map[$id]=$waba;
            }
        }
    }
    return $map;
}

function hache_sharky_whatsapp_flows_decorate_events(array $events,array $payload): array
{
    $map=hache_sharky_whatsapp_flows_message_waba_map($payload);
    foreach($events as &$event){
        if(!is_array($event))continue;
        $id=trim((string)($event['id']??''));
        if($id!==''&&isset($map[$id]))$event['waba_id']=$map[$id];
    }
    unset($event);
    return $events;
}

function hache_sharky_whatsapp_birthdate_flow_response_date(string $responseJson,?string $today=null): ?string
{
    $data=json_decode($responseJson,true);if(!is_array($data))return null;
    // Multi-field enrollment/payment Flows carry their own typed contract and
    // must never be duplicated as the legacy one-field birthdate event.
    if(trim((string)($data['flow_kind']??''))!=='')return null;
    $birthdate=trim((string)($data['birthdate']??''));if($birthdate==='')return null;
    if(function_exists('hache_sharky_orchestrator_parse_birthdate')){
        return hache_sharky_orchestrator_parse_birthdate($birthdate,$today);
    }
    if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$birthdate)!==1)return null;
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$birthdate);
    return $date&&$date->format('Y-m-d')===$birthdate?$birthdate:null;
}

/**
 * Normalizes terminal WhatsApp Flow replies into the same event contract used by
 * typed birthdates. Unknown/malformed nfm_reply payloads fail closed and remain
 * invisible to the conversational pipeline.
 *
 * The selected date intentionally carries an empty interactive_id: once Meta has
 * validated and returned the terminal Flow payload, the registration pipeline
 * should consume it exactly like a typed ISO birthdate. This also keeps stale
 * button protection strict for every actual reply-button/list action.
 */
function hache_sharky_whatsapp_birthdate_flow_extract_events(array $payload,?string $today=null): array
{
    $out=[];
    foreach(($payload['entry']??[]) as $entry){
        if(!is_array($entry))continue;
        $waba=preg_replace('/\D+/','',(string)($entry['id']??''))?:'';
        foreach(($entry['changes']??[]) as $change){
            if(!is_array($change))continue;
            $value=$change['value']??null;if(!is_array($value))continue;
            $phoneId=trim((string)($value['metadata']['phone_number_id']??''));
            foreach(($value['messages']??[]) as $message){
                if(!is_array($message)||($message['type']??'')!=='interactive')continue;
                $interactive=$message['interactive']??null;if(!is_array($interactive)||($interactive['type']??'')!=='nfm_reply')continue;
                $response=(string)($interactive['nfm_reply']['response_json']??'');
                $birthdate=hache_sharky_whatsapp_birthdate_flow_response_date($response,$today);if($birthdate===null)continue;
                $id=trim((string)($message['id']??''));
                $from=preg_replace('/\D+/','',(string)($message['from']??''))?:'';
                if($id===''||$from==='')continue;
                $event=[
                    'id'=>$id,'from'=>$from,'type'=>'interactive','text'=>$birthdate,
                    'interactive_id'=>'','phone_number_id'=>$phoneId,
                    'timestamp_ms'=>((int)($message['timestamp']??time()))*1000,
                ];
                if($waba!=='')$event['waba_id']=$waba;
                if(is_array($message['referral']??null))$event['referral']=$message['referral'];
                $out[]=$event;
            }
        }
    }
    return $out;
}

function hache_sharky_whatsapp_birthdate_flow_payload(string $to,string $body,string $flowId,?string $flowToken=null): array
{
    $flowId=preg_replace('/\D+/','',$flowId)?:'';
    if($flowToken===null||trim($flowToken)===''){
        try{$flowToken='bd_'.bin2hex(random_bytes(12));}
        catch(Throwable $e){$flowToken='bd_'.hash('sha256',microtime(true).'|'.$to);}
    }
    $body=trim($body);
    $fallback='También puedes escribir la fecha directamente en el chat si prefieres.';
    if($body==='')$body='Selecciona la fecha de nacimiento.';
    if(!str_contains($body,$fallback))$body.="\n\n".$fallback;
    if(mb_strlen($body)>1024)$body=mb_substr($body,0,1024);
    return [
        'messaging_product'=>'whatsapp',
        'recipient_type'=>'individual',
        'to'=>$to,
        'type'=>'interactive',
        'interactive'=>[
            'type'=>'flow',
            'body'=>['text'=>$body],
            'action'=>[
                'name'=>'flow',
                'parameters'=>[
                    'flow_message_version'=>'3',
                    'flow_token'=>$flowToken,
                    'flow_id'=>$flowId,
                    'flow_cta'=>'Elegir fecha',
                    'flow_action'=>'navigate',
                    'flow_action_payload'=>['screen'=>HACHE_SHARKY_BIRTHDATE_FLOW_SCREEN],
                ],
            ],
        ],
    ];
}

function hache_sharky_whatsapp_flow_graph_json(string $method,string $url,string $token,?array $form=null): ?array
{
    $ch=curl_init($url);if($ch===false)return null;
    $headers=['Authorization: Bearer '.$token,'Accept: application/json'];
    $options=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>$headers];
    if(strtoupper($method)==='POST'){
        $options[CURLOPT_POST]=true;
        if(is_array($form)){
            $options[CURLOPT_HTTPHEADER][]='Content-Type: application/x-www-form-urlencoded';
            $options[CURLOPT_POSTFIELDS]=http_build_query($form,'','&',PHP_QUERY_RFC3986);
        }
    }
    curl_setopt_array($ch,$options);
    $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);
    if(!is_string($raw)||$error!==''||$status<200||$status>=300)return null;
    $data=json_decode($raw,true);return is_array($data)?$data:null;
}

function hache_sharky_whatsapp_birthdate_flow_upload(string $base,string $flowId,string $token): bool
{
    $asset=__DIR__.'/whatsapp-flows/birthdate-v1.json';if(!is_file($asset))return false;
    $ch=curl_init($base.'/'.rawurlencode($flowId).'/assets');if($ch===false)return false;
    $file=new CURLFile($asset,'application/json','flow.json');
    curl_setopt_array($ch,[
        CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>30,
        CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Accept: application/json'],
        CURLOPT_POSTFIELDS=>['name'=>'flow.json','asset_type'=>'FLOW_JSON','file'=>$file],
    ]);
    $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);
    return is_string($raw)&&$error===''&&$status>=200&&$status<300;
}

function hache_sharky_whatsapp_birthdate_flow_existing(array $data): ?array
{
    foreach(($data['data']??[]) as $flow){
        if(!is_array($flow)||trim((string)($flow['name']??''))!==HACHE_SHARKY_BIRTHDATE_FLOW_NAME)continue;
        $id=preg_replace('/\D+/','',(string)($flow['id']??''))?:'';if($id==='')continue;
        return ['id'=>$id,'status'=>strtoupper(trim((string)($flow['status']??'')))];
    }
    return null;
}

/**
 * Idempotently finds or provisions the single static birthdate Flow. The caller
 * is already behind a signed WhatsApp webhook; WABA id is taken from entry.id.
 * Any Graph/permission/schema failure returns null so registration falls back to
 * the permissive typed-date parser instead of blocking the customer.
 */
function hache_sharky_whatsapp_birthdate_flow_ensure(string $wabaId,callable $secretResolver): ?string
{
    $configured=preg_replace('/\D+/','',(string)$secretResolver('WHATSAPP_BIRTHDATE_FLOW_ID'))?:'';
    if($configured!=='')return $configured;
    $wabaId=preg_replace('/\D+/','',$wabaId)?:'';$token=trim((string)$secretResolver('WHATSAPP_ACCESS_TOKEN'));
    if($wabaId===''||$token==='')return null;
    $version=trim((string)$secretResolver('WHATSAPP_GRAPH_VERSION'));if(preg_match('/^v\d+\.\d+$/',$version)!==1)$version='v26.0';
    $base='https://graph.facebook.com/'.rawurlencode($version);

    $lockPath=sys_get_temp_dir().'/hache-sharky-flow-'.hash('sha256',$wabaId.'|'.HACHE_SHARKY_BIRTHDATE_FLOW_NAME).'.lock';
    $lock=@fopen($lockPath,'c');if(is_resource($lock))@flock($lock,LOCK_EX);
    try{
        $list=hache_sharky_whatsapp_flow_graph_json('GET',$base.'/'.rawurlencode($wabaId).'/flows?fields=id%2Cname%2Cstatus&limit=100',$token);
        $existing=is_array($list)?hache_sharky_whatsapp_birthdate_flow_existing($list):null;
        if(is_array($existing)&&$existing['status']==='PUBLISHED')return (string)$existing['id'];

        $flowId=is_array($existing)?(string)$existing['id']:'';
        if($flowId===''){
            $created=hache_sharky_whatsapp_flow_graph_json('POST',$base.'/'.rawurlencode($wabaId).'/flows',$token,[
                'name'=>HACHE_SHARKY_BIRTHDATE_FLOW_NAME,
                'categories'=>'["SIGN_UP"]',
            ]);
            $flowId=preg_replace('/\D+/','',(string)($created['id']??''))?:'';
            if($flowId==='')return null;
        }

        $uploaded=hache_sharky_whatsapp_birthdate_flow_upload($base,$flowId,$token);
        $published=hache_sharky_whatsapp_flow_graph_json('POST',$base.'/'.rawurlencode($flowId).'/publish',$token,[]);
        if(!is_array($published)){
            // A previous retry may already have uploaded a valid asset; a failed
            // upload is therefore not used as the final authority. Re-list status.
            $check=hache_sharky_whatsapp_flow_graph_json('GET',$base.'/'.rawurlencode($wabaId).'/flows?fields=id%2Cname%2Cstatus&limit=100',$token);
            $after=is_array($check)?hache_sharky_whatsapp_birthdate_flow_existing($check):null;
            if(!is_array($after)||$after['status']!=='PUBLISHED'){
                error_log('[sharky-flow] birthdate Flow provisioning unavailable');
                return null;
            }
        }elseif(!$uploaded){
            // Publication succeeded, so Meta already had a valid asset from an
            // earlier idempotent attempt. This is safe to use.
        }
        return $flowId;
    }finally{
        if(is_resource($lock)){@flock($lock,LOCK_UN);@fclose($lock);}
    }
}

function hache_sharky_whatsapp_birthdate_flow_upgrade(array $payload,array $event,string $decisionKind,callable $secretResolver): array
{
    if(!in_array($decisionKind,['registration_birthdate','registration_birthdate_invalid'],true))return $payload;
    if(trim((string)($event['group_id']??''))!=='')return $payload;
    $contact=preg_replace('/\D+/','',(string)($event['from']??''))?:'';$waba=(string)($event['waba_id']??'');
    if($contact===''||$waba==='')return $payload;
    $flowId=hache_sharky_whatsapp_birthdate_flow_ensure($waba,$secretResolver);if($flowId===null)return $payload;
    $body='';
    if(($payload['type']??'')==='text')$body=(string)($payload['text']['body']??'');
    elseif(($payload['type']??'')==='interactive')$body=(string)($payload['interactive']['body']['text']??'');
    return hache_sharky_whatsapp_birthdate_flow_payload($contact,$body,$flowId);
}
