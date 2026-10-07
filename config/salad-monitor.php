<?php

declare(strict_types=1);

/** @return array{api_key:string,api_base:string,organization:string,project:string} */
function hache_salad_monitor_config(): array
{
    $key=trim((string)getenv('SALAD_API_KEY'));
    if($key===''){
        $file='/etc/hache-salad-monitor.env';
        if(is_readable($file))foreach(file($file,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line){
            $line=trim((string)$line);
            if(str_starts_with($line,'SALAD_API_KEY=')){$key=trim(trim(substr($line,14)),"\"'");break;}
        }
    }
    return [
        'api_key'=>$key,
        'api_base'=>rtrim((string)(getenv('SALAD_API_BASE')?:'https://api.salad.com/api/public'),'/'),
        'organization'=>(string)(getenv('SALAD_ORGANIZATION')?:'hache'),
        'project'=>(string)(getenv('SALAD_PROJECT')?:'prl-tests'),
    ];
}

/** @return array<string,mixed> */
function hache_salad_monitor_http(array $config,string $method,string $path,?array $payload=null): array
{
    if($config['api_key']==='')throw new RuntimeException('SALAD_API_KEY no está configurada en el servidor.');
    if(!function_exists('curl_init'))throw new RuntimeException('La extensión cURL de PHP es necesaria para el monitor.');
    $curl=curl_init($config['api_base'].$path);
    $headers=['Accept: application/json','Salad-Api-Key: '.$config['api_key']];
    $options=[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>25];
    if($payload!==null){$json=json_encode($payload,JSON_THROW_ON_ERROR);$headers[]='Content-Type: application/json';$options[CURLOPT_HTTPHEADER]=$headers;$options[CURLOPT_POSTFIELDS]=$json;}
    curl_setopt_array($curl,$options);$raw=curl_exec($curl);$error=curl_error($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
    if(!is_string($raw)||$status<200||$status>=300)throw new RuntimeException('Salad respondió HTTP '.$status.($error!==''?' ('.$error.')':''));
    $decoded=json_decode($raw,true);if(!is_array($decoded))throw new RuntimeException('Salad devolvió una respuesta no válida.');
    return $decoded;
}

function hache_salad_monitor_clean_line(string $line): string
{
    return preg_replace('/\x1B\[[0-9;]*[A-Za-z]/','',$line)??$line;
}

/** @return array<string,int> */
function hache_salad_monitor_shares(array $lines): array
{
    $result=['accepted'=>0,'rejected'=>0,'hardware_errors'=>0];
    foreach($lines as $line){
        if(preg_match('/(?:accepted|accept(?:ed)? shares?)\D*(\d+)/i',$line,$m))$result['accepted']=max($result['accepted'],(int)$m[1]);
        if(preg_match('/(?:rejected|reject(?:ed)? shares?)\D*(\d+)/i',$line,$m))$result['rejected']=max($result['rejected'],(int)$m[1]);
        if(preg_match('/(?:hw(?:\s*errors?)?|hardware\s*errors?)\D*(\d+)/i',$line,$m))$result['hardware_errors']=max($result['hardware_errors'],(int)$m[1]);
    }
    return $result;
}

/** @return array<string,mixed> */
function hache_salad_monitor_parse(array $logItems): array
{
    $lines=[];$latest=null;
    foreach($logItems as $item){$line=hache_salad_monitor_clean_line((string)($item['text_log']??$item['message']??''));if($line!=='')$lines[]=$line;$time=(string)($item['time']??$item['timestamp']??'');if($latest===null&&$time!=='')$latest=$time;}
    $metrics=['gpu'=>null,'hashrate_ths'=>null,'hashrate_15m_ths'=>null,'watts'=>null,'temperature_c'=>null,'fan_percent'=>null,'efficiency_th_per_w'=>null,'shares'=>hache_salad_monitor_shares($lines),'last_log_at'=>$latest];
    foreach($lines as $line){
        if(preg_match('/#\d+\s+(?<gpu>.+?)\s+(?<hash>\d+(?:\.\d+)?)\s+TH\/s\s+(?<power>\d+(?:\.\d+)?)W\s+(?<eff>\d+(?:\.\d+)?)\s+(?<fan>\d+)%\s+(?<temp>\d+)C/i',$line,$m)){
            $metrics['gpu']=trim($m['gpu']);$metrics['hashrate_ths']=(float)$m['hash'];$metrics['watts']=(float)$m['power'];$metrics['efficiency_th_per_w']=(float)$m['eff'];$metrics['fan_percent']=(int)$m['fan'];$metrics['temperature_c']=(int)$m['temp'];break;
        }
    }
    foreach($lines as $line)if(preg_match('/15\s*min\s+(?<hash>\d+(?:\.\d+)?)\s+TH\/s/i',$line,$m)){$metrics['hashrate_15m_ths']=(float)$m['hash'];break;}
    return $metrics;
}

function hache_salad_monitor_status(string $groupState,array $metrics): string
{
    $state=strtolower($groupState);if(!in_array($state,['running','started'],true))return 'red';
    if(($metrics['hashrate_ths']??0)<=0||($metrics['gpu']??null)===null)return 'red';
    if(hache_salad_monitor_yellow_reasons($metrics)!==[])return 'yellow';
    return 'green';
}

/** @return list<string> */
function hache_salad_monitor_yellow_reasons(array $metrics): array
{
    $reasons=[];
    if(($metrics['temperature_c']??0)>=82)$reasons[]='temperatura alta ('.(int)$metrics['temperature_c'].'°C)';
    if(($metrics['fan_percent']??0)>=95)$reasons[]='ventilador alto ('.(int)$metrics['fan_percent'].'%)';
    if(($metrics['shares']['rejected']??0)>0)$reasons[]='shares rechazadas ('.(int)$metrics['shares']['rejected'].')';
    if(($metrics['shares']['hardware_errors']??0)>0)$reasons[]='errores de hardware ('.(int)$metrics['shares']['hardware_errors'].')';
    return $reasons;
}

function hache_salad_monitor_ntfy_topic(): string
{
    $topic=trim((string)getenv('NTFY_TOPIC'));if($topic!=='')return $topic;
    $file='/etc/hache-salad-ntfy.env';if(!is_readable($file))return '';
    foreach(file($file,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line){$line=trim((string)$line);if(str_starts_with($line,'NTFY_TOPIC='))return trim(trim(substr($line,11)),"\"'");}
    return '';
}

function hache_salad_monitor_send_yellow_notification(array $group): bool
{
    $topic=hache_salad_monitor_ntfy_topic();if($topic==='')throw new RuntimeException('NTFY_TOPIC no está configurado en el servidor.');
    $metrics=$group['metrics']??[];$reasons=hache_salad_monitor_yellow_reasons($metrics);$now=(new DateTimeImmutable('now',new DateTimeZone('America/Cancun')))->format('Y-m-d H:i T');
    $body="⚠️ Salad Monitor\n".($group['display_name']??$group['group'])."\n".($metrics['gpu']??'GPU sin datos')."\n".($metrics['temperature_c']??'—')."°C | Fan ".($metrics['fan_percent']??'—')."% | ".($metrics['hashrate_ths']??'—')." TH/s\n15 min: ".($metrics['hashrate_15m_ths']??'—')." TH/s | Potencia: ".($metrics['watts']??'—')." W\nMotivo: ".implode('; ',$reasons)."\nHora: $now";
    $curl=curl_init('https://ntfy.sh/'.rawurlencode($topic));curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>['Content-Type: text/plain; charset=utf-8','Title: Salad Monitor - ALERTA AMARILLA','Priority: urgent','Tags: warning,computer'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15]);$response=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
    return is_string($response)&&$status>=200&&$status<300;
}

/** @return list<array<string,mixed>> */
function hache_salad_monitor_collect(): array
{
    $config=hache_salad_monitor_config();$base='/organizations/'.rawurlencode($config['organization']).'/projects/'.rawurlencode($config['project']).'/containers';
    $groups=hache_salad_monitor_http($config,'GET',$base);$items=$groups['items']??$groups['container_groups']??[];if(!is_array($items))throw new RuntimeException('La lista de Container Groups no tiene el formato esperado.');
    $end=new DateTimeImmutable('now',new DateTimeZone('UTC'));$start=$end->sub(new DateInterval('PT10M'));$result=[];
    foreach($items as $group){if(!is_array($group))continue;$name=trim((string)($group['name']??''));if($name==='')continue;$instances=hache_salad_monitor_http($config,'GET',$base.'/'.rawurlencode($name).'/instances');
        $logs=hache_salad_monitor_http($config,'POST','/organizations/'.rawurlencode($config['organization']).'/log-entries',['sort_order'=>'desc','start_time'=>$start->format('Y-m-d\\TH:i:s\\Z'),'end_time'=>$end->format('Y-m-d\\TH:i:s\\Z'),'page_size'=>100,'query'=>'resource.type = "container" and resource.labels.project_name = "'.$config['project'].'" and resource.labels.container_group_name = "'.$name.'"']);
        $metrics=hache_salad_monitor_parse(is_array($logs['items']??null)?$logs['items']:[]);$state=(string)($group['current_state']['status']??$group['status']??'unknown');$safeInstances=[];
        foreach(is_array($instances['instances']??null)?$instances['instances']:[] as $instance)if(is_array($instance))$safeInstances[]=['id'=>(string)($instance['id']??''),'machine_id'=>(string)($instance['machine_id']??''),'state'=>(string)($instance['state']??''),'ready'=>(bool)($instance['ready']??false),'started'=>(bool)($instance['started']??false),'update_time'=>(string)($instance['update_time']??''),'cpu_percent'=>isset($instance['cpu_percent'])?(float)$instance['cpu_percent']:null,'memory_usage_mb'=>isset($instance['memory_usage_mb'])?(float)$instance['memory_usage_mb']:null];
        $result[]=['group'=>$name,'display_name'=>(string)($group['display_name']??$name),'state'=>$state,'instances'=>$safeInstances,'metrics'=>$metrics,'health'=>hache_salad_monitor_status($state,$metrics),'observed_at'=>$end->format(DATE_ATOM)];
    }
    return $result;
}
