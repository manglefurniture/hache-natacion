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
    $metrics=['gpu'=>null,'hashrate_ths'=>null,'hashrate_15m_ths'=>null,'hashrate_15m_at'=>null,'watts'=>null,'temperature_c'=>null,'fan_percent'=>null,'efficiency_th_per_w'=>null,'shares'=>hache_salad_monitor_shares($lines),'last_log_at'=>$latest];
    foreach($lines as $line){
        if(preg_match('/#\d+\s+(?<gpu>.+?)\s+(?<hash>\d+(?:\.\d+)?)\s+TH\/s\s+(?<power>\d+(?:\.\d+)?)W\s+(?<eff>\d+(?:\.\d+)?)\s+(?<fan>\d+)%\s+(?<temp>\d+)C/i',$line,$m)){
            $metrics['gpu']=trim($m['gpu']);$metrics['hashrate_ths']=(float)$m['hash'];$metrics['watts']=(float)$m['power'];$metrics['efficiency_th_per_w']=(float)$m['eff'];$metrics['fan_percent']=(int)$m['fan'];$metrics['temperature_c']=(int)$m['temp'];break;
        }
    }
    foreach($logItems as $item){
        $line=hache_salad_monitor_clean_line((string)($item['text_log']??$item['message']??''));
        if(preg_match('/15\\s*min\\s+(?<hash>\\d+(?:\\.\\d+)?)\\s+TH\\/s/i',$line,$m)){
            $metrics['hashrate_15m_ths']=(float)$m['hash'];
            $metrics['hashrate_15m_at']=(string)($item['time']??$item['timestamp']??'');
            break;
        }
    }
    return $metrics;
}

/**
 * Indicador orientativo para RTX 4070 Ti SUPER en Salad Low ($0.13/h).
 * 140/125 TH/s son referencias basadas en un precio/dificultad puntuales,
 * NO un cálculo de rentabilidad actual ni una autorización para detener GPUs.
 * Se usa la media de 15 min y se ignoran grupos con múltiples nodos o datos incompletos.
 *
 * @return array{applicable:bool,level:string,hashrate_15m_ths:?float,warning_ths:int,break_even_ths:int}
 */
function hache_salad_monitor_profitability(string $name,string $state,array $metrics,array $instances): array
{
    $result=['applicable'=>false,'level'=>'unknown','hashrate_15m_ths'=>null,'warning_ths'=>140,'break_even_ths'=>125];
    $gpu=strtoupper((string)($metrics['gpu']??''));
    if(!preg_match('/(?:^|[-_])low(?:$|[-_])/',strtolower($name))||!str_contains($gpu,'4070 TI SUPER'))return $result;
    $result['applicable']=true;
    $active=0;
    foreach($instances as $instance){
        if(!is_array($instance))continue;
        $instanceState=strtolower((string)($instance['state']??''));
        if(($instance['ready']??false)===true&&(($instance['started']??false)===true||$instanceState==='running'))$active++;
    }
    if(!in_array(strtolower($state),['running','started'],true)||$active!==1)return $result;
    $average=$metrics['hashrate_15m_ths']??null;
    if(!is_numeric($average)||!is_finite((float)$average)||(float)$average<=0)return $result;
    $result['hashrate_15m_ths']=(float)$average;
    $result['level']=$average<125?'below_break_even':($average<140?'watch':'healthy');
    return $result;
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
    $profitability=is_array($metrics['profitability']??null)?$metrics['profitability']:[];
    if(($profitability['applicable']??false)===true){
        if(($profitability['level']??'')==='below_break_even')$reasons[]='media 15 min bajo 125 TH/s (posible pérdida; referencia estimada)';
        elseif(($profitability['level']??'')==='watch')$reasons[]='media 15 min bajo 140 TH/s (margen reducido; referencia estimada)';
    }
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
    $metrics=is_array($group['metrics']??null)?$group['metrics']:[];
    $profit=hache_salad_monitor_profitability((string)($group['group']??''),(string)($group['state']??''),$metrics,is_array($group['instances']??null)?$group['instances']:[]);
    $reason=($profit['applicable']??false)===true
        ?'Promedio 15 min: '.number_format($hashrate,2,'.','').' TH/s; 2 lecturas consecutivas bajo 125 TH/s.'
        :'Hashrate actual: '.number_format($hashrate,2,'.','').' TH/s; bajo 130 TH/s durante al menos '.$minutes.' min.';
    $body="♻️ Salad Auto-Reallocate\n".($group['display_name']??$group['group'])."\n".$reason."\nAcción: reallocate aceptado por Salad; esperando nuevo nodo.\nHora: $now";
    $curl=curl_init('https://ntfy.sh/'.rawurlencode($topic));curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>['Content-Type: text/plain; charset=utf-8','Title: Salad Monitor - AUTO REALLOCATE','Priority: high','Tags: arrows_counterclockwise,computer'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15]);$response=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
    return is_string($response)&&$status>=200&&$status<300;
}

/**
 * Auditoría privada y acotada: el monitor registra decisiones sin exponer la API key.
 * Nunca bloquea el poller si el disco del registro falla.
 *
 * @param array<string,mixed> $event
 */
function hache_salad_monitor_audit_reallocation(array $event): void
{
    $path='/var/lib/hache-natacion/salad-monitor-reallocation-audit.jsonl';
    $record=['at'=>gmdate(DATE_ATOM)]+$event;
    $previousUmask=umask(0077);
    try{
        if(is_link($path)||is_link($path.'.1'))throw new RuntimeException('ruta de auditoría no segura');
        if(is_file($path)&&filesize($path)>1048576&&!rename($path,$path.'.1'))throw new RuntimeException('rotación de auditoría falló');
        $line=json_encode($record,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
        if(file_put_contents($path,$line,FILE_APPEND|LOCK_EX)===false)throw new RuntimeException('escritura de auditoría falló');
        chmod($path,0600);
    }catch(Throwable $e){error_log('[salad-auto-reallocate] audit-error: '.$e->getMessage());}
    finally{umask($previousUmask);}
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
function hache_salad_monitor_apply_low_hash_reallocations(array $groups,array $previous,callable $reallocate,callable $notify,?int $nowTs=null,float $threshold=130.0,int $minimumSeconds=300,?callable $audit=null): array
{
    $nowTs=$nowTs??time();$next=$previous;$count=0;
    $record=$audit??static function(array $event): void {};
    foreach($groups as $group){
        $key=(string)($group['group']??'');if($key==='')continue;
        $entry=is_array($previous[$key]??null)?$previous[$key]:[];
        $metrics=is_array($group['metrics']??null)?$group['metrics']:[];
        $emit=static function(string $decision,array $details=[])use($record,$key):void{
            $record(['group'=>$key,'decision'=>$decision]+$details);
        };
        $resetAverage=static function(array &$state):void{
            $state['low_avg_instance_id']=null;$state['low_avg_since']=null;$state['low_avg_last_at']=null;$state['low_avg_count']=0;
        };
        if((bool)($group['stale']??false)){
            $resetAverage($entry);$emit('skip_stale');$next[$key]=$entry;continue;
        }
        $instances=is_array($group['instances']??null)?$group['instances']:[];
        $active=[];
        foreach($instances as $instance){
            if(!is_array($instance))continue;
            $id=trim((string)($instance['id']??''));if($id==='')continue;
            $state=strtolower((string)($instance['state']??''));$ready=(bool)($instance['ready']??false);$started=(bool)($instance['started']??false);
            if($ready&&($started||$state==='running'))$active[]=$id;
        }
        if(count($active)!==1){
            $entry['low_hash_since']=null;$entry['low_hash_instance_id']=null;$resetAverage($entry);
            $emit('skip_instance_not_ready',['active_count'=>count($active)]);$next[$key]=$entry;continue;
        }
        $instanceId=$active[0];
        if(($entry['reallocation_requested_instance_id']??null)===$instanceId){
            $emit('skip_already_requested',['instance_id'=>$instanceId]);$next[$key]=$entry;continue;
        }
        if(($entry['reallocation_requested_instance_id']??null)!==null)$entry['reallocation_requested_instance_id']=null;
        $gpu=(string)($metrics['gpu']??'');
        if($gpu===''||!is_numeric($metrics['hashrate_ths']??null)||!is_finite((float)$metrics['hashrate_ths'])){
            $entry['low_hash_since']=null;$entry['low_hash_instance_id']=null;$resetAverage($entry);
            $emit('skip_metrics_missing',['instance_id'=>$instanceId]);$next[$key]=$entry;continue;
        }
        $hash=(float)$metrics['hashrate_ths'];
        $profit=hache_salad_monitor_profitability($key,(string)($group['state']??''),$metrics,$instances);
        if(($profit['applicable']??false)===true){
            // Regla económica orientativa solo para RTX 4070 Ti SUPER Low.
            // Dos snapshots nuevos y consecutivos con media 15 min <125 TH/s, separados al menos 5 minutos.
            $entry['low_hash_since']=null;$entry['low_hash_instance_id']=null;
            $average=$profit['hashrate_15m_ths']??null;
            if(($profit['level']??'unknown')!=='below_break_even'||!is_numeric($average)){
                $resetAverage($entry);
                $emit('skip_average_not_below_125',['instance_id'=>$instanceId,'average_15m_ths'=>$average,'level'=>$profit['level']??'unknown']);
                $next[$key]=$entry;continue;
            }
            $last=$entry['low_avg_last_at']??null;
            if(($entry['low_avg_instance_id']??null)!==$instanceId||!is_int($last)||$nowTs<=$last||$nowTs-$last>660){
                $entry['low_avg_instance_id']=$instanceId;$entry['low_avg_since']=$nowTs;$entry['low_avg_last_at']=$nowTs;$entry['low_avg_count']=1;
                $emit('average_low_first_reading',['instance_id'=>$instanceId,'average_15m_ths'=>(float)$average]);
                $next[$key]=$entry;continue;
            }
            $entry['low_avg_last_at']=$nowTs;$entry['low_avg_count']=min(2,(int)($entry['low_avg_count']??1)+1);
            $seconds=max(0,$nowTs-(int)($entry['low_avg_since']??$nowTs));
            if($entry['low_avg_count']<2||$seconds<$minimumSeconds){
                $emit('average_low_waiting',['instance_id'=>$instanceId,'average_15m_ths'=>(float)$average,'elapsed_seconds'=>$seconds]);
                $next[$key]=$entry;continue;
            }
            $observedHash=(float)$average;$elapsed=$seconds;$policy='average_15m_below_125_two_readings';
        }else{
            // Política previa para el resto de GPU: hashrate instantáneo <130 durante 5 min.
            $resetAverage($entry);
            if($hash>=$threshold){
                $entry['hash_baseline_seen']=true;$entry['low_hash_since']=null;$entry['low_hash_instance_id']=null;
                $emit('skip_instant_hash_healthy',['instance_id'=>$instanceId,'hashrate_ths'=>$hash]);$next[$key]=$entry;continue;
            }
            if(!($entry['hash_baseline_seen']??false)){
                $emit('skip_baseline_not_seen',['instance_id'=>$instanceId,'hashrate_ths'=>$hash]);$next[$key]=$entry;continue;
            }
            if(($entry['low_hash_instance_id']??null)!==$instanceId||!is_int($entry['low_hash_since']??null)){
                $entry['low_hash_instance_id']=$instanceId;$entry['low_hash_since']=$nowTs;
                $emit('instant_low_first_reading',['instance_id'=>$instanceId,'hashrate_ths'=>$hash]);$next[$key]=$entry;continue;
            }
            $seconds=max(0,$nowTs-(int)$entry['low_hash_since']);
            if($seconds<$minimumSeconds){
                $emit('instant_low_waiting',['instance_id'=>$instanceId,'hashrate_ths'=>$hash,'elapsed_seconds'=>$seconds]);
                $next[$key]=$entry;continue;
            }
            $observedHash=$hash;$elapsed=$seconds;$policy='instant_below_130';
        }
        // Si Salad rechaza la solicitud, conservar estado y limitar el reintento a 15 min.
        if($nowTs-(int)($entry['reallocation_failed_at']??0)<900){
            $emit('skip_failure_cooldown',['instance_id'=>$instanceId,'policy'=>$policy]);$next[$key]=$entry;continue;
        }
        try{
            $accepted=$reallocate($group);
        }catch(Throwable $e){
            $entry['reallocation_failed_at']=$nowTs;
            $emit('reallocate_failed',['instance_id'=>$instanceId,'policy'=>$policy,'reason'=>'salad_api_error']);
            error_log('[salad-auto-reallocate] request failed group='.$key.' (see private audit)');
            $next[$key]=$entry;continue;
        }
        if(!$accepted){
            $entry['reallocation_failed_at']=$nowTs;
            $emit('reallocate_failed',['instance_id'=>$instanceId,'policy'=>$policy,'reason'=>'api_not_accepted']);
            $next[$key]=$entry;continue;
        }
        $entry['reallocation_requested_instance_id']=$instanceId;
        $entry['last_reallocated_at']=gmdate(DATE_ATOM,$nowTs);
        $entry['reallocation_failed_at']=null;
        $entry['low_hash_since']=null;$entry['low_hash_instance_id']=null;$resetAverage($entry);
        $count++;
        $emit('reallocate_accepted',['instance_id'=>$instanceId,'policy'=>$policy,'observed_ths'=>$observedHash,'elapsed_seconds'=>$elapsed]);
        // La notificación no puede deshacer un reallocate que Salad ya aceptó.
        try{
            if(!$notify($group,$observedHash,$elapsed))$emit('notification_failed',['instance_id'=>$instanceId,'reason'=>'ntfy_not_accepted']);
        }catch(Throwable $e){
            $emit('notification_failed',['instance_id'=>$instanceId,'reason'=>'ntfy_error']);
            error_log('[salad-auto-reallocate] notification failed group='.$key);
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
        $display=(string)($group['display_name']??$name);$state=(string)($group['current_state']['status']??$group['status']??'unknown');$priority=strtolower((string)($group['priority']??''));
        try{
            $instances=hache_salad_monitor_http($config,'GET',$base.'/'.rawurlencode($name).'/instances');
            $logs=hache_salad_monitor_http($config,'POST','/organizations/'.rawurlencode($config['organization']).'/log-entries',['sort_order'=>'desc','start_time'=>$start->format('Y-m-d\\TH:i:s\\Z'),'end_time'=>$end->format('Y-m-d\\TH:i:s\\Z'),'page_size'=>100,'query'=>'resource.type = "container" and resource.labels.project_name = "'.$config['project'].'" and resource.labels.container_group_name = "'.$name.'"']);
            $metrics=hache_salad_monitor_parse(is_array($logs['items']??null)?$logs['items']:[]);$safeInstances=[];
            foreach(is_array($instances['instances']??null)?$instances['instances']:[] as $instance)if(is_array($instance))$safeInstances[]=['id'=>(string)($instance['id']??''),'machine_id'=>(string)($instance['machine_id']??''),'state'=>(string)($instance['state']??''),'ready'=>(bool)($instance['ready']??false),'started'=>(bool)($instance['started']??false),'update_time'=>(string)($instance['update_time']??''),'cpu_percent'=>isset($instance['cpu_percent'])?(float)$instance['cpu_percent']:null,'memory_usage_mb'=>isset($instance['memory_usage_mb'])?(float)$instance['memory_usage_mb']:null];
            $metrics['profitability']=hache_salad_monitor_profitability($name,$state,$metrics,$safeInstances);
            if($priority!=='low'){$metrics['profitability']['applicable']=false;$metrics['profitability']['level']='unknown';}
            $result[]=['group'=>$name,'display_name'=>$display,'priority'=>$priority,'state'=>$state,'instances'=>$safeInstances,'metrics'=>$metrics,'health'=>hache_salad_monitor_status($state,$metrics,$safeInstances),'stale'=>false,'observed_at'=>$end->format(DATE_ATOM)];
        }catch(Throwable $e){
            error_log('[salad-monitor] group '.$name.': '.$e->getMessage());
            if(isset($previousByGroup[$name])){
                $fallback=$previousByGroup[$name];$fallback['display_name']=$display;$fallback['priority']=$priority;$fallback['state']=$state;$fallback['stale']=true;$fallback['update_error']='No se pudo actualizar este grupo.';$result[]=$fallback;
            }else{
                $metrics=['gpu'=>null,'hashrate_ths'=>null,'hashrate_15m_ths'=>null,'hashrate_15m_at'=>null,'watts'=>null,'temperature_c'=>null,'fan_percent'=>null,'efficiency_th_per_w'=>null,'shares'=>['accepted'=>0,'rejected'=>0,'hardware_errors'=>0],'last_log_at'=>null];
                $result[]=['group'=>$name,'display_name'=>$display,'state'=>$state,'instances'=>[],'metrics'=>$metrics,'health'=>'red','stale'=>true,'update_error'=>'No se pudo actualizar este grupo.','observed_at'=>$end->format(DATE_ATOM)];
            }
        }
    }
    return $result;
}
