<?php
declare(strict_types=1);
require_once __DIR__.'/../config/salad-monitor.php';
$simulation=$argv[1]??'';if(!in_array($simulation,['','--simulate=yellow','--simulate=green'],true))throw new RuntimeException('Uso: salad-monitor-poll.php [--simulate=yellow|--simulate=green]');
$stateFile=$simulation!==''?'/var/lib/hache-natacion/salad-monitor-alert-simulation-state.json':(getenv('SALAD_MONITOR_STATE_FILE')?:'/var/lib/hache-natacion/salad-monitor-alert-state.json');$dir=dirname($stateFile);if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('No se pudo crear el estado del monitor.');
$lock=fopen($stateFile.'.lock','c+');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){fwrite(STDERR,"SALAD_MONITOR_POLL_SKIPPED_LOCKED\n");exit(0);}
try{
    $raw=is_file($stateFile)?file_get_contents($stateFile):'';$previous=is_string($raw)?json_decode($raw,true):[];if(!is_array($previous))$previous=[];
    $groups=$simulation===''?hache_salad_monitor_collect():[['group'=>'salad-ntfy-simulation','display_name'=>'SIMULACIÓN ntfy Salad','health'=>$simulation==='--simulate=yellow'?'yellow':'green','metrics'=>['gpu'=>'GPU de prueba (sin Salad)','hashrate_ths'=>164.6,'hashrate_15m_ths'=>163.9,'watts'=>285.0,'temperature_c'=>85,'fan_percent'=>100,'shares'=>['accepted'=>0,'rejected'=>0,'hardware_errors'=>0]]]];
    if($simulation==='')hache_salad_monitor_write_snapshot($groups);
    $transition=hache_salad_monitor_apply_alert_transitions($groups,$previous,'hache_salad_monitor_send_notification');
    $automation=$simulation===''?hache_salad_monitor_apply_low_hash_reallocations($groups,$transition['state'],'hache_salad_monitor_reallocate_instance','hache_salad_monitor_send_reallocation_notification',null,130.0,300,'hache_salad_monitor_audit_reallocation'):['state'=>$transition['state'],'reallocated'=>0];
    $tmp=tempnam($dir,'salad-state-');file_put_contents($tmp,json_encode($automation['state'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));chmod($tmp,0600);rename($tmp,$stateFile);
    echo "SALAD_MONITOR_POLL_OK groups=".count($groups)." alerts={$transition['sent']} reallocations={$automation['reallocated']}\n";
}finally{flock($lock,LOCK_UN);fclose($lock);}
