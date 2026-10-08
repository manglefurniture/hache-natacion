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

function hache_salad_monitor_snapshot_file(): string
{
    return (string)(getenv('SALAD_MONITOR_SNAPSHOT_FILE')?:'/var/lib/hache-natacion/salad-monitor-snapshot.json');
}

/** @param list<array<string,mixed>> $groups */
function hache_salad_monitor_write_snapshot(array $groups): void
{
    $file=hache_salad_monitor_snapshot_file();$dir=dirname($file);
    if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('No se pudo crear el directorio del snapshot.');
    $payload=['observed_at'=>(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format(DATE_ATOM),'groups'=>$groups];
    $tmp=tempnam($dir,'salad-snapshot-');if($tmp===false)throw new RuntimeException('No se pudo crear el snapshot temporal.');
    try{
        if(file_put_contents($tmp,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),LOCK_EX)===false)throw new RuntimeException('No se pudo escribir el snapshot.');
        chmod($tmp,0600);
        if(!rename($tmp,$file))throw new RuntimeException('No se pudo publicar el snapshot.');
    }finally{if(is_file($tmp))@unlink($tmp);}
}

/** @return array{observed_at:string,groups:list<array<string,mixed>>}|null */
function hache_salad_monitor_read_snapshot(): ?array
{
    $file=hache_salad_monitor_snapshot_file();if(!is_readable($file))return null;
    $raw=file_get_contents($file);if(!is_string($raw)||$raw==='')return null;
    $decoded=json_decode($raw,true);if(!is_array($decoded)||!is_array($decoded['groups']??null))return null;
    return ['observed_at'=>(string)($decoded['observed_at']??''),'groups'=>array_values(array_filter($decoded['groups'],'is_array'))];
}

function hache_salad_monitor_snapshot_age_seconds(string $observedAt): ?int
{
    if($observedAt==='')return null;
    try{$at=new DateTimeImmutable($observedAt);return max(0,time()-$at->getTimestamp());}catch(Throwable){return null;}
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

function hache_salad_monitor_status(string $groupState,array $metrics,?array $instances=null): string
{
    $state=strtolower($groupState);if(!in_array($state,['running','started'],true))return 'red';
    if($instances!==null){
        $healthy=false;
        foreach($instances as $instance){
            if(!is_array($instance))continue;
            $instanceState=strtolower((string)($instance['state']??''));
            if(($instance['ready']??false)===true&&(($instance['started']??false)===true||$instanceState==='running')){$healthy=true;break;}
        }
        if(!$healthy)return 'red';
    }
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

/** @return list<string> */
function hache_salad_monitor_red_reasons(array $group): array
{
    $reasons=[];$state=strtolower((string)($group['state']??'unknown'));
    if(!in_array($state,['running','started'],true))$reasons[]='grupo fuera de servicio ('.$state.')';
    $instances=is_array($group['instances']??null)?$group['instances']:[];
    $healthy=false;$states=[];
    foreach($instances as $instance){
        if(!is_array($instance))continue;
        $instanceState=strtolower((string)($instance['state']??'unknown'));$ready=(bool)($instance['ready']??false);$started=(bool)($instance['started']??false);
        $states[]=$instanceState.'/ready='.($ready?'sí':'no');
        if($ready&&($started||$instanceState==='running'))$healthy=true;
    }
    if(!$healthy)$reasons[]=$instances===[]?'sin instancia activa':'instancia no disponible ('.implode(', ',$states).')';
    $metrics=is_array($group['metrics']??null)?$group['metrics']:[];
    if(($metrics['hashrate_ths']??0)<=0)$reasons[]='sin hashrate';
    if(($metrics['gpu']??null)===null)$reasons[]='sin datos de GPU';
    return array_values(array_unique($reasons));
}

function hache_salad_monitor_ntfy_topic(): string
{
    $topic=trim((string)getenv('NTFY_TOPIC'));if($topic!=='')return $topic;
    $file='/etc/hache-salad-ntfy.env';if(!is_readable($file))return '';
    foreach(file($file,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line){$line=trim((string)$line);if(str_starts_with($line,'NTFY_TOPIC='))return trim(trim(substr($line,11)),"\"'");}
    return '';
}

function hache_salad_monitor_send_notification(array $group): bool
{
    $topic=hache_salad_monitor_ntfy_topic();if($topic==='')throw new RuntimeException('NTFY_TOPIC no está configurado en el servidor.');
    $health=(string)($group['health']??'red');$metrics=is_array($group['metrics']??null)?$group['metrics']:[];
    $reasons=$health==='red'?hache_salad_monitor_red_reasons($group):hache_salad_monitor_yellow_reasons($metrics);
    $now=(new DateTimeImmutable('now',new DateTimeZone('America/Cancun')))->format('Y-m-d H:i T');
    $icon=$health==='red'?'🚨':'⚠️';$label=$health==='red'?'ALERTA ROJA':'ALERTA AMARILLA';
    $body=$icon." Salad Monitor\n".($group['display_name']??$group['group'])."\n".($metrics['gpu']??'GPU sin datos')."\nEstado: ".$health." | Grupo: ".($group['state']??'—')."\n".($metrics['temperature_c']??'—')."°C | Fan ".($metrics['fan_percent']??'—')."% | ".($metrics['hashrate_ths']??'—')." TH/s\n15 min: ".($metrics['hashrate_15m_ths']??'—')." TH/s | Potencia: ".($metrics['watts']??'—')." W\nMotivo: ".implode('; ',$reasons)."\nHora: $now";
    $headers=['Content-Type: text/plain; charset=utf-8','Title: Salad Monitor - '.$label,'Priority: urgent','Tags: '.($health==='red'?'rotating_light,computer':'warning,computer')];
    $curl=curl_init('https://ntfy.sh/'.rawurlencode($topic));curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15]);$response=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
    return is_string($response)&&$status>=200&&$status<300;
}

/**
 * Applies alert transitions for yellow/red health without performing any API request itself.
 *
 * @param list<array<string,mixed>> $groups
 * @param array<string,array{health?:string}> $previous
 * @param callable(array<string,mixed>):bool $notify
 * @return array{state:array<string,array{health:string,updated_at:string}>,sent:int}
 */
function hache_salad_monitor_apply_alert_transitions(array $groups,array $previous,callable $notify): array
{
    $next=[];$sent=0;$now=gmdate(DATE_ATOM);
    foreach($groups as $group){
        $key=(string)($group['group']??'');if($key==='')continue;$health=(string)($group['health']??'red');$before=(string)($previous[$key]['health']??'unknown');
        $stale=(bool)($group['stale']??false);$alertable=in_array($health,['yellow','red'],true);
        if(!$stale&&$alertable&&$before!==$health){if(!$notify($group))throw new RuntimeException('ntfy no confirmó la alerta para '.$key);$sent++;}
        $entry=is_array($previous[$key]??null)?$previous[$key]:[];
        $next[$key]=array_merge($entry,['health'=>$health,'updated_at'=>$now]);
    }
    return ['state'=>$next,'sent'=>$sent];
}

function hache_salad_monitor_reallocate_instance(array $group): bool
{
    $config=hache_salad_monitor_config();$name=trim((string)($group['group']??''));if($name==='')return false;
    $instances=is_array($group['instances']??null)?$group['instances']:[];
    $eligible=[];
    foreach($instances as $instance){
        if(!is_array($instance))continue;$id=trim((string)($instance['id']??''));if($id==='')continue;
        $state=strtolower((string)($instance['state']??''));$ready=(bool)($instance['ready']??false);$started=(bool)($instance['started']??false);
        if($ready&&($started||$state==='running'))$eligible[]=$id;
    }
    if(count($eligible)!==1)throw new RuntimeException('Auto-reallocate requiere exactamente una instancia activa en '.$name.'.');
    if($config['api_key']===''||!function_exists('curl_init'))throw new RuntimeException('Salad no está configurado para auto-reallocate.');
    $path='/organizations/'.rawurlencode($config['organization']).'/projects/'.rawurlencode($config['project']).'/containers/'.rawurlencode($name).'/instances/'.rawurlencode($eligible[0]).'/reallocate';
    $curl=curl_init($config['api_base'].$path);curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Accept: application/json','Salad-Api-Key: '.$config['api_key']],CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>25]);
    $raw=curl_exec($curl);$error=curl_error($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
    if($status!==202)throw new RuntimeException('Salad rechazó auto-reallocate HTTP '.$status.($error!==''?' ('.$error.')':'').(is_string($raw)&&$raw!==''?' '.$raw:''));
    return true;
}

function hache_salad_monitor_send_reallocation_notification(array $group,float $hashrate,int $lowSeconds): bool
{
    $topic=hache_salad_monitor_ntfy_topic();if($topic==='')throw new RuntimeException('NTFY_TOPIC no está configurado en el servidor.');
    $now=(new DateTimeImmutable('now',new DateTimeZone('America/Cancun')))->format('Y-m-d H:i T');$minutes=max(5,(int)floor($lowSeconds/60));
    $body="♻️ Salad Auto-Reallocate\n".($group['display_name']??$group['group'])."\nHashrate: ".number_format($hashrate,2,'.','')." TH/s\nBajo 130 TH/s durante al menos ".$minutes." min.\nAcción: reallocate solicitado para obtener un nodo nuevo.\nHora: $now";
    $curl=curl_init('https://ntfy.sh/'.rawurlencode($topic));curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>['Content-Type: text/plain; charset=utf-8','Title: Salad Monitor - AUTO REALLOCATE','Priority: high','Tags: arrows_counterclockwise,computer'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15]);$response=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
    return is_string($response)&&$status>=200&&$status<300;
}

/**
 * Reallocates a single healthy/running instance when its hashrate stays below
 * the threshold for at least the configured duration. The rule is armed only
 * after the group has demonstrated hashrate >= threshold at least once.
 *
 * @param list<array<string,mixed>> $groups
 * @param array<string,array<string,mixed>> $previous
 * @param callable(array<string,mixed>):bool $reallocate
 * @param callable(array<string,mixed>,float,int):bool $notify
 * @return array{state:array<string,array<string,mixed>>,reallocated:int}
 */
function hache_salad_monitor_apply_low_hash_reallocations(array $groups,array $previous,callable $reallocate,callable $notify,?int $nowTs=null,float $threshold=130.0,int $minimumSeconds=300): array
{
    $nowTs=$nowTs??time();$next=$previous;$count=0;
    foreach($groups as $group){
        $key=(string)($group['group']??'');if($key==='')continue;$entry=is_array($previous[$key]??null)?$previous[$key]:[];
        if((bool)($group['stale']??false)){ $next[$key]=$entry;continue; }
        $instances=is_array($group['instances']??null)?$group['instances']:[];$active=[];
        foreach($instances as $instance){
            if(!is_array($instance))continue;$id=trim((string)($instance['id']??''));if($id==='')continue;$state=strtolower((string)($instance['state']??''));$ready=(bool)($instance['ready']??false);$started=(bool)($instance['started']??false);
            if($ready&&($started||$state==='running'))$active[]=$id;
        }
        if(count($active)!==1){$entry['low_hash_since']=null;$entry['low_hash_instance_id']=null;$next[$key]=$entry;continue;}
        $instanceId=$active[0];$hash=(float)($group['metrics']['hashrate_ths']??0);
        if($hash>=$threshold){
            $entry['hash_baseline_seen']=true;$entry['low_hash_since']=null;$entry['low_hash_instance_id']=null;
            if(($entry['reallocation_requested_instance_id']??null)!==$instanceId)$entry['reallocation_requested_instance_id']=null;
            $next[$key]=$entry;continue;
        }
        if(!($entry['hash_baseline_seen']??false)){ $next[$key]=$entry;continue; }
        if(($entry['low_hash_instance_id']??null)!==$instanceId||!is_int($entry['low_hash_since']??null)){
            $entry['low_hash_instance_id']=$instanceId;$entry['low_hash_since']=$nowTs;$next[$key]=$entry;continue;
        }
        $lowSeconds=max(0,$nowTs-(int)$entry['low_hash_since']);
        if($lowSeconds<$minimumSeconds||($entry['reallocation_requested_instance_id']??null)===$instanceId){$next[$key]=$entry;continue;}
        if($reallocate($group)){
            $entry['reallocation_requested_instance_id']=$instanceId;$entry['last_reallocated_at']=gmdate(DATE_ATOM,$nowTs);$entry['low_hash_since']=null;$entry['low_hash_instance_id']=null;$count++;
            $notify($group,$hash,$lowSeconds);
        }
        $next[$key]=$entry;
    }
    return ['state'=>$next,'reallocated'=>$count];
}

/** @return list<array<string,mixed>> */
function hache_salad_monitor_collect(): array
{
    $config=hache_salad_monitor_config();$base='/organizations/'.rawurlencode($config['organization']).'/projects/'.rawurlencode($config['project']).'/containers';
    $groups=hache_salad_monitor_http($config,'GET',$base);$items=$groups['items']??$groups['container_groups']??[];if(!is_array($items))throw new RuntimeException('La lista de Container Groups no tiene el formato esperado.');
    $previous=hache_salad_monitor_read_snapshot();$previousByGroup=[];
    foreach($previous['groups']??[] as $old){$key=(string)($old['group']??'');if($key!=='')$previousByGroup[$key]=$old;}
    $end=new DateTimeImmutable('now',new DateTimeZone('UTC'));$start=$end->sub(new DateInterval('PT10M'));$result=[];
    foreach($items as $group){
        if(!is_array($group))continue;$name=trim((string)($group['name']??''));if($name==='')continue;
        $display=(string)($group['display_name']??$name);$state=(string)($group['current_state']['status']??$group['status']??'unknown');
        try{
            $instances=hache_salad_monitor_http($config,'GET',$base.'/'.rawurlencode($name).'/instances');
            $logs=hache_salad_monitor_http($config,'POST','/organizations/'.rawurlencode($config['organization']).'/log-entries',['sort_order'=>'desc','start_time'=>$start->format('Y-m-d\\TH:i:s\\Z'),'end_time'=>$end->format('Y-m-d\\TH:i:s\\Z'),'page_size'=>100,'query'=>'resource.type = "container" and resource.labels.project_name = "'.$config['project'].'" and resource.labels.container_group_name = "'.$name.'"']);
            $metrics=hache_salad_monitor_parse(is_array($logs['items']??null)?$logs['items']:[]);$safeInstances=[];
            foreach(is_array($instances['instances']??null)?$instances['instances']:[] as $instance)if(is_array($instance))$safeInstances[]=['id'=>(string)($instance['id']??''),'machine_id'=>(string)($instance['machine_id']??''),'state'=>(string)($instance['state']??''),'ready'=>(bool)($instance['ready']??false),'started'=>(bool)($instance['started']??false),'update_time'=>(string)($instance['update_time']??''),'cpu_percent'=>isset($instance['cpu_percent'])?(float)$instance['cpu_percent']:null,'memory_usage_mb'=>isset($instance['memory_usage_mb'])?(float)$instance['memory_usage_mb']:null];
            $result[]=['group'=>$name,'display_name'=>$display,'state'=>$state,'instances'=>$safeInstances,'metrics'=>$metrics,'health'=>hache_salad_monitor_status($state,$metrics,$safeInstances),'stale'=>false,'observed_at'=>$end->format(DATE_ATOM)];
        }catch(Throwable $e){
            error_log('[salad-monitor] group '.$name.': '.$e->getMessage());
            if(isset($previousByGroup[$name])){
                $fallback=$previousByGroup[$name];$fallback['display_name']=$display;$fallback['state']=$state;$fallback['stale']=true;$fallback['update_error']='No se pudo actualizar este grupo.';$result[]=$fallback;
            }else{
                $metrics=['gpu'=>null,'hashrate_ths'=>null,'hashrate_15m_ths'=>null,'watts'=>null,'temperature_c'=>null,'fan_percent'=>null,'efficiency_th_per_w'=>null,'shares'=>['accepted'=>0,'rejected'=>0,'hardware_errors'=>0],'last_log_at'=>null];
                $result[]=['group'=>$name,'display_name'=>$display,'state'=>$state,'instances'=>[],'metrics'=>$metrics,'health'=>'red','stale'=>true,'update_error'=>'No se pudo actualizar este grupo.','observed_at'=>$end->format(DATE_ATOM)];
            }
        }
    }
    return $result;
}
