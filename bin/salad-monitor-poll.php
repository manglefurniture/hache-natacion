<?php
declare(strict_types=1);
require_once __DIR__.'/../config/salad-monitor.php';
$stateFile=getenv('SALAD_MONITOR_STATE_FILE')?:'/var/lib/hache-natacion/salad-monitor-alert-state.json';$dir=dirname($stateFile);if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('No se pudo crear el estado del monitor.');
$lock=fopen($stateFile.'.lock','c+');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){fwrite(STDERR,"SALAD_MONITOR_POLL_SKIPPED_LOCKED\n");exit(0);}try{$raw=is_file($stateFile)?file_get_contents($stateFile):'';$previous=is_string($raw)?json_decode($raw,true):[];if(!is_array($previous))$previous=[];$next=[];$sent=0;
    foreach(hache_salad_monitor_collect() as $group){$key=(string)$group['group'];$health=(string)$group['health'];$before=(string)($previous[$key]['health']??'unknown');if($health==='yellow'&&$before!=='yellow'){if(!hache_salad_monitor_send_yellow_notification($group))throw new RuntimeException('ntfy no confirmó la alerta para '.$key);$sent++;}$next[$key]=['health'=>$health,'updated_at'=>gmdate(DATE_ATOM)];}
    $tmp=tempnam($dir,'salad-state-');file_put_contents($tmp,json_encode($next,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));chmod($tmp,0600);rename($tmp,$stateFile);echo "SALAD_MONITOR_POLL_OK yellow_alerts=$sent\n";
}finally{flock($lock,LOCK_UN);fclose($lock);}
