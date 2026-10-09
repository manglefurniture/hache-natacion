<?php
declare(strict_types=1);
require_once __DIR__.'/../config/salad-monitor.php';
function expect(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
$sample=[['time'=>'2026-10-07T18:00:00Z','text_log'=>"\e[32m#0 RTX 4070 Ti SUPER 161.58 TH/s 284.8W 0.567 73% 69C\e[0m"],['time'=>'2026-10-07T17:59:00Z','text_log'=>'15 min 161.64 TH/s'],['time'=>'2026-10-07T17:58:00Z','text_log'=>'Shares accepted 16 rejected 0 HW errors 0']];
$m=hache_salad_monitor_parse($sample);expect($m['gpu']==='RTX 4070 Ti SUPER','GPU no extraída');expect($m['hashrate_ths']===161.58,'TH/s actual no extraído');expect($m['hashrate_15m_ths']===161.64,'TH/s 15 min no extraído');expect($m['watts']===284.8&&$m['temperature_c']===69&&$m['fan_percent']===73,'Métricas de GPU no extraídas');expect($m['shares']['accepted']===16,'Shares no extraídas');expect(hache_salad_monitor_status('running',$m)==='green','Estado sano incorrecto');expect(hache_salad_monitor_status('running',$m,[['state'=>'running','ready'=>true,'started'=>true]])==='green','Instancia sana marcada incorrectamente');expect(hache_salad_monitor_status('running',$m,[['state'=>'running','ready'=>false,'started'=>true]])==='red','Instancia no ready debe ser roja');$m['temperature_c']=84;expect(hache_salad_monitor_status('running',$m)==='yellow','Estado térmico incorrecto');expect(hache_salad_monitor_status('deploying',$m)==='red','Estado de grupo incorrecto');
$yellow=['group'=>'simulation-4070','health'=>'yellow','stale'=>false,'state'=>'running','instances'=>[['state'=>'running','ready'=>true,'started'=>true]],'metrics'=>$m];$notifications=0;$notify=static function(array $group)use(&$notifications):bool{$notifications++;return true;};$first=hache_salad_monitor_apply_alert_transitions([$yellow],[],$notify);expect($first['sent']===1&&$notifications===1,'La primera entrada en amarillo debe alertar');$second=hache_salad_monitor_apply_alert_transitions([$yellow],$first['state'],$notify);expect($second['sent']===0&&$notifications===1,'No debe duplicar alerta mientras siga amarillo');$red=$yellow;$red['health']='red';$red['instances']=[['state'=>'stopped','ready'=>false,'started'=>false]];$escalated=hache_salad_monitor_apply_alert_transitions([$red],$second['state'],$notify);expect($escalated['sent']===1&&$notifications===2,'La transición de amarillo a rojo debe alertar');$sameRed=hache_salad_monitor_apply_alert_transitions([$red],$escalated['state'],$notify);expect($sameRed['sent']===0&&$notifications===2,'No debe duplicar alerta mientras siga rojo');$staleRed=$red;$staleRed['stale']=true;$staleFromGreen=hache_salad_monitor_apply_alert_transitions([$staleRed],['simulation-4070'=>['health'=>'green']],$notify);expect($staleFromGreen['sent']===0&&$notifications===2,'Un fallback stale no debe generar alerta roja');$green=$yellow;$green['health']='green';$recovered=hache_salad_monitor_apply_alert_transitions([$green],$sameRed['state'],$notify);expect($recovered['sent']===0&&$notifications===2,'La recuperación no debe alertar');$again=hache_salad_monitor_apply_alert_transitions([$yellow],$recovered['state'],$notify);expect($again['sent']===1&&$notifications===3,'Debe alertar de nuevo al volver a amarillo');

$lowGroup=['group'=>'prl-medium-4070','display_name'=>'Low 4070','state'=>'running','stale'=>false,'instances'=>[['id'=>'instance-a','state'=>'running','ready'=>true,'started'=>true]],'metrics'=>['gpu'=>'RTX 4070 Ti SUPER','hashrate_ths'=>165.0,'hashrate_15m_ths'=>164.0,'watts'=>280.0,'temperature_c'=>68,'fan_percent'=>55,'shares'=>['accepted'=>1,'rejected'=>0,'hardware_errors'=>0]]];
$reallocations=0;$reallocate=static function(array $group)use(&$reallocations):bool{$reallocations++;return true;};$reallocationNotices=0;$reallocNotify=static function(array $group,float $hash,int $seconds)use(&$reallocationNotices):bool{$reallocationNotices++;return true;};
$armed=hache_salad_monitor_apply_low_hash_reallocations([$lowGroup],[],$reallocate,$reallocNotify,1000);expect(($armed['state']['prl-medium-4070']['hash_baseline_seen']??false)===true,'Debe armarse tras observar hash sano');expect($armed['reallocated']===0,'No debe reasignar con hash sano');
$lowGroup['metrics']['hashrate_ths']=125.0;$firstLow=hache_salad_monitor_apply_low_hash_reallocations([$lowGroup],$armed['state'],$reallocate,$reallocNotify,1100);expect($firstLow['reallocated']===0&&($firstLow['state']['prl-medium-4070']['low_hash_since']??null)===1100,'Primera lectura baja debe iniciar temporizador');
$tooSoon=hache_salad_monitor_apply_low_hash_reallocations([$lowGroup],$firstLow['state'],$reallocate,$reallocNotify,1399);expect($tooSoon['reallocated']===0&&$reallocations===0,'No debe reasignar antes de cinco minutos');
$triggered=hache_salad_monitor_apply_low_hash_reallocations([$lowGroup],$tooSoon['state'],$reallocate,$reallocNotify,1400);expect($triggered['reallocated']===1&&$reallocations===1&&$reallocationNotices===1,'Debe reasignar al cumplir cinco minutos bajo 130 TH/s');expect(($triggered['state']['prl-medium-4070']['reallocation_requested_instance_id']??'')==='instance-a','Debe recordar la instancia ya reasignada');
$duplicate=hache_salad_monitor_apply_low_hash_reallocations([$lowGroup],$triggered['state'],$reallocate,$reallocNotify,1800);expect($duplicate['reallocated']===0&&$reallocations===1,'No debe reasignar dos veces la misma instancia');
$newNode=$lowGroup;$newNode['instances'][0]['id']='instance-b';$newNode['metrics']['hashrate_ths']=160.0;$recoveredHash=hache_salad_monitor_apply_low_hash_reallocations([$newNode],$duplicate['state'],$reallocate,$reallocNotify,1810);expect(array_key_exists('reallocation_requested_instance_id',$recoveredHash['state']['prl-medium-4070'])&&$recoveredHash['state']['prl-medium-4070']['reallocation_requested_instance_id']===null,'Nodo nuevo sano debe limpiar la marca anterior');
$newNode['metrics']['hashrate_ths']=120.0;$newLow=hache_salad_monitor_apply_low_hash_reallocations([$newNode],$recoveredHash['state'],$reallocate,$reallocNotify,1900);$newTrigger=hache_salad_monitor_apply_low_hash_reallocations([$newNode],$newLow['state'],$reallocate,$reallocNotify,2200);expect($newTrigger['reallocated']===1&&$reallocations===2,'Una instancia nueva también debe poder reasignarse tras cinco minutos bajos');
$stale=$newNode;$stale['stale']=true;$staleLow=hache_salad_monitor_apply_low_hash_reallocations([$stale],$newTrigger['state'],$reallocate,$reallocNotify,2600);expect($staleLow['reallocated']===0&&$reallocations===2,'Datos stale nunca deben provocar auto-reallocate');


$avgGroup=['group'=>'prl-low-super','priority'=>'low','state'=>'running','stale'=>false,'instances'=>[['id'=>'id-a','state'=>'running','ready'=>true,'started'=>true]],'metrics'=>['gpu'=>'RTX 4070 Ti SUPER','hashrate_ths'=>151.0,'hashrate_15m_ths'=>120.0,'hashrate_15m_at'=>gmdate(DATE_ATOM,3000)]];
$observed=[];$audit=static function(array $e)use(&$observed):void{$observed[]=$e;};
$requests=0;$realloc=static function(array $g)use(&$requests):bool{$requests++;return true;};
$x=hache_salad_monitor_apply_low_hash_reallocations([$avgGroup],[],$realloc,$reallocNotify,3000,130,300,$audit);
expect($x['reallocated']===0,'Media baja necesita dos lecturas');
$sameLog=hache_salad_monitor_apply_low_hash_reallocations([$avgGroup],$x['state'],$realloc,$reallocNotify,3300,130,300,$audit);
expect($sameLog['reallocated']===0&&$sameLog['state']['prl-low-super']['low_avg_count']===1,'No contar dos veces el mismo log');
$notLow=$avgGroup;$notLow['priority']='medium';
$wrongPriority=hache_salad_monitor_apply_low_hash_reallocations([$notLow],[],$realloc,$reallocNotify,3300,130,300,$audit);
expect($wrongPriority['reallocated']===0&&in_array('skip_priority_not_low',array_column($observed,'decision'),true),'Slug low no equivale a prioridad Low');
$avgGroup['metrics']['hashrate_15m_at']=gmdate(DATE_ATOM,3300);
$y=hache_salad_monitor_apply_low_hash_reallocations([$avgGroup],$x['state'],$realloc,$reallocNotify,3300,130,300,$audit);
expect($y['reallocated']===1&&$requests===1,'Media de 120 debe reasignar aunque actual sea 151');
$z=hache_salad_monitor_apply_low_hash_reallocations([$avgGroup],$y['state'],$realloc,$reallocNotify,3600,130,300,$audit);
expect($z['reallocated']===0&&$requests===1,'No repetir solicitud misma instancia');
expect(in_array('reallocate_accepted',array_column($observed,'decision'),true),'Auditoría de aceptación');


$nextNode=$avgGroup;$nextNode['instances'][0]['id']='id-b';
$nextNode['metrics']['hashrate_15m_at']=gmdate(DATE_ATOM,3900);
$armedAgain=hache_salad_monitor_apply_low_hash_reallocations([$nextNode],$z['state'],$realloc,$reallocNotify,3900,130,300,$audit);
$nextNode['stale']=true;
$staleAverage=hache_salad_monitor_apply_low_hash_reallocations([$nextNode],$armedAgain['state'],$realloc,$reallocNotify,4200,130,300,$audit);
expect($staleAverage['reallocated']===0&&$staleAverage['state']['prl-low-super']['low_avg_count']===0,'Stale rompe consecutividad');
$nextNode['stale']=false;$nextNode['metrics']['hashrate_15m_ths']=125.1;
$restored=hache_salad_monitor_apply_low_hash_reallocations([$nextNode],$staleAverage['state'],$realloc,$reallocNotify,4500,130,300,$audit);
expect($restored['reallocated']===0,'Media igual a 125 no inicia reallocate');


$nextNode['metrics']['hashrate_15m_ths']=120.0;
$nextNode['metrics']['hashrate_15m_at']=gmdate(DATE_ATOM,4800);
$f1=hache_salad_monitor_apply_low_hash_reallocations([$nextNode],$restored['state'],$realloc,$reallocNotify,4800,130,300,$audit);
$notifyDown=static function(array $g,float $h,int $t):bool{throw new RuntimeException('ntfy down');};
$nextNode['metrics']['hashrate_15m_at']=gmdate(DATE_ATOM,5100);
$f2=hache_salad_monitor_apply_low_hash_reallocations([$nextNode],$f1['state'],$realloc,$notifyDown,5100,130,300,$audit);
expect($f2['reallocated']===1&&$f2['state']['prl-low-super']['reallocation_requested_instance_id']==='id-b','Aceptar reallocate aunque ntfy falle');
expect(in_array('notification_failed',array_column($observed,'decision'),true),'Notificación fallida auditada');
$f3=hache_salad_monitor_apply_low_hash_reallocations([$nextNode],$f2['state'],$realloc,$reallocNotify,5400,130,300,$audit);
expect($f3['reallocated']===0&&$requests===2,'Fallo ntfy no repite solicitud');


$boundary=$avgGroup;$boundary['group']='prl-low-boundary';$boundary['instances'][0]['id']='boundary-a';
$boundary['metrics']['hashrate_15m_ths']=125.0;$boundary['metrics']['hashrate_15m_at']=gmdate(DATE_ATOM,6000);
$boundaryStart=hache_salad_monitor_apply_low_hash_reallocations([$boundary],[],$realloc,$reallocNotify,6000,130,300,$audit);
expect($boundaryStart['reallocated']===0&&$boundaryStart['state']['prl-low-boundary']['low_avg_count']===1,'125 exacto arma primera lectura');
$boundary['metrics']['hashrate_15m_at']=gmdate(DATE_ATOM,6300);
$boundaryTrigger=hache_salad_monitor_apply_low_hash_reallocations([$boundary],$boundaryStart['state'],$realloc,$reallocNotify,6300,130,300,$audit);
expect($boundaryTrigger['reallocated']===1,'125 exacto confirmado debe reasignar');

// La clasificación de rentabilidad es informativa; no altera la regla 130 TH/s / 5 min.
$profitGroup=$lowGroup;
$profitGroup['group']='prl-low-profitable-01'; // No requiere "4070" en el nombre.
$profitGroup['metrics']['hashrate_15m_ths']=160.0;
$p=hache_salad_monitor_profitability($profitGroup['group'],'running',$profitGroup['metrics'],$profitGroup['instances']);
expect($p['applicable']&&$p['level']==='healthy','4070 Low sana debe ser viable');
$profitGroup['metrics']['hashrate_15m_ths']=139.5;
$p=hache_salad_monitor_profitability($profitGroup['group'],'running',$profitGroup['metrics'],$profitGroup['instances']);
expect($p['level']==='watch','139.5 TH/s debe estar en precaución');
$profitGroup['metrics']['profitability']=$p;
expect(hache_salad_monitor_status('running',$profitGroup['metrics'],$profitGroup['instances'])==='yellow','Margen reducido debe alertar en amarillo');
expect(str_contains(implode(' ',hache_salad_monitor_yellow_reasons($profitGroup['metrics'])),'140 TH/s'),'Debe explicar umbral preventivo');
$profitGroup['metrics']['hashrate_15m_ths']=125.0;
expect(hache_salad_monitor_profitability($profitGroup['group'],'running',$profitGroup['metrics'],$profitGroup['instances'])['level']==='below_break_even','125 TH/s exactos activan prevención');
$profitGroup['metrics']['hashrate_15m_ths']=124.9;
$p=hache_salad_monitor_profitability($profitGroup['group'],'running',$profitGroup['metrics'],$profitGroup['instances']);
expect($p['level']==='below_break_even','124.9 TH/s debe señalar posible pérdida');
$profitGroup['metrics']['profitability']=$p;
expect(str_contains(implode(' ',hache_salad_monitor_yellow_reasons($profitGroup['metrics'])),'125 TH/s'),'Debe explicar estimación de pérdida');
unset($profitGroup['metrics']['hashrate_15m_ths']);
expect(hache_salad_monitor_profitability($profitGroup['group'],'running',$profitGroup['metrics'],$profitGroup['instances'])['level']==='unknown','Sin media de 15 min no inferir pérdidas');
$profitGroup['metrics']['hashrate_15m_ths']=110.0;
$profitGroup['instances'][]=['id'=>'instance-b','state'=>'running','ready'=>true,'started'=>true];
expect(hache_salad_monitor_profitability($profitGroup['group'],'running',$profitGroup['metrics'],$profitGroup['instances'])['level']==='unknown','Varias instancias no permiten atribuir el hash a una sola GPU');
$profitGroup['instances']=array_slice($profitGroup['instances'],0,1);
expect(hache_salad_monitor_profitability($profitGroup['group'],'allocating',$profitGroup['metrics'],$profitGroup['instances'])['level']==='unknown','Allocating no debe generar falso aviso económico');
expect(!hache_salad_monitor_profitability('prl-medium-4070','running',$profitGroup['metrics'],$profitGroup['instances'])['applicable'],'No aplicar precio Low a Medium');
$profitGroup['metrics']['gpu']='RTX 3090';
expect(!hache_salad_monitor_profitability($profitGroup['group'],'running',$profitGroup['metrics'],$profitGroup['instances'])['applicable'],'No aplicar umbrales 4070 a otras GPUs');

$tmp=sys_get_temp_dir().'/hache-salad-monitor-'.bin2hex(random_bytes(6)).'.json';putenv('SALAD_MONITOR_SNAPSHOT_FILE='.$tmp);
$snapshotGroups=[['group'=>'g1','display_name'=>'G1','state'=>'running','instances'=>[],'metrics'=>['gpu'=>'RTX 4070 Ti SUPER','hashrate_ths'=>167.0,'shares'=>['accepted'=>3,'rejected'=>0,'hardware_errors'=>0]],'health'=>'green','stale'=>false,'observed_at'=>gmdate(DATE_ATOM)]];
hache_salad_monitor_write_snapshot($snapshotGroups);$snapshot=hache_salad_monitor_read_snapshot();expect($snapshot!==null,'Snapshot no leído');expect(($snapshot['groups'][0]['group']??'')==='g1','Snapshot perdió grupos');$age=hache_salad_monitor_snapshot_age_seconds((string)$snapshot['observed_at']);expect($age!==null&&$age<5,'Edad del snapshot incorrecta');@unlink($tmp);putenv('SALAD_MONITOR_SNAPSHOT_FILE');
$dashboard=file_get_contents(__DIR__.'/../public/salad-monitor.php');
expect(is_string($dashboard)&&substr_count($dashboard,'≤125 TH/s')===2,'La vista ADMIN debe mostrar el límite inclusivo de 125 TH/s');
// Official SaladCloud API sends instance_id, not necessarily id. The legacy
// monitor previously silently dropped it, so no GPU could be reallocated.
$official=['instance_id'=>'5ebfa363-6e0b-4db1-b9be-70ed4995d0b1',
    'machine_id'=>'673ac947-11e7-48e4-9a04-994ace400abc',
    'state'=>'running','ready'=>true,'started'=>true,
    'update_time'=>'2026-10-09T19:00:00Z'];
$normalized=hache_salad_monitor_normalize_instance($official);
expect($normalized['id']===$official['instance_id'],'Salad official instance_id is required for reallocation');
expect($normalized['state']==='running'&&$normalized['ready']===true&&$normalized['started']===true,'Official API flags must be preserved');
expect(hache_salad_monitor_normalize_instance(['id'=>'legacy-id','state'=>'running','ready'=>true,'started'=>true])['id']==='legacy-id','Historic id fallback lost');
expect(hache_salad_monitor_normalize_instance(['state'=>'running','ready'=>true,'started'=>true])['id']==='','Missing identifier cannot become reallocation eligible');
expect(hache_salad_monitor_normalize_instance(['instance_id'=>'id','state'=>['status'=>'running'],'ready'=>true,'started'=>true])['state']==='running','Nested state compatibility broken');
// No real Salad API calls: callback only increments a local counter.
$simulated=['group'=>'other-gpu','state'=>'running','priority'=>'low','stale'=>false,
    'instances'=>[$normalized],
    'metrics'=>['gpu'=>'RTX 3080 Ti','hashrate_ths'=>160.0]];
$localCalls=0;$localReallocate=static function(array $g)use(&$localCalls):bool{$localCalls++;return true;};
$localNotify=static fn(array $g,float $h,int $s):bool=>true;
$one=hache_salad_monitor_apply_low_hash_reallocations([$simulated],[],$localReallocate,$localNotify,1000);
$simulated['metrics']['hashrate_ths']=50.0;
$two=hache_salad_monitor_apply_low_hash_reallocations([$simulated],$one['state'],$localReallocate,$localNotify,1100);
$three=hache_salad_monitor_apply_low_hash_reallocations([$simulated],$two['state'],$localReallocate,$localNotify,1400);
expect($three['reallocated']===1&&$localCalls===1,'Healthy then 50 TH/s for 5min must reallocate a properly identified node');
echo "SALAD_MONITOR_REGRESSION_OK\n";
