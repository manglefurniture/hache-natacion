<?php
declare(strict_types=1);
require_once __DIR__.'/../config/salad-monitor-history.php';
function check_history(bool $yes, string $message): void {
    if (!$yes) throw new RuntimeException($message);
}
$dir = sys_get_temp_dir().'/salad-history-'.bin2hex(random_bytes(5));
putenv('SALAD_MONITOR_HISTORY_DIR='.$dir);
$time = new DateTimeImmutable('2026-10-10T17:00:00+00:00');
$group = [
    'group'=>'prl-lowest-laptop', 'priority'=>'lowest', 'state'=>'running', 'stale'=>false,
    'instances'=>[['id'=>'gpu-a','machine_id'=>'machine-a','update_time'=>'2026-10-10T16:59:00Z','ready'=>true,'started'=>true,'state'=>'running']],
    'metrics'=>['gpu'=>'NVIDIA RTX 4070 Laptop GPU','hashrate_ths'=>80.0,
        'last_log_at'=>$time->format(DATE_ATOM),'hashrate_at'=>$time->format(DATE_ATOM),'shares'=>['accepted'=>9]]
];
$rows=hache_salad_history_rows([$group],$time);
check_history(count($rows)===1 && $rows[0]['priority']==='lowest', 'Prioridad Lowest no guardada');
check_history($rows[0]['hashrate_ths']===80.0 && $rows[0]['instance_id']==='gpu-a', 'GPU pequeña descartada');
hache_salad_history_append([$group],$time);
$next=$time->modify('+5 minutes');
$group['metrics']['last_log_at']=$next->format(DATE_ATOM);
$group['metrics']['hashrate_at']=$next->format(DATE_ATOM);
hache_salad_history_append([$group],$next);
$next2=$time->modify('+10 minutes');
$group['metrics']['last_log_at']=$next2->format(DATE_ATOM);
$group['metrics']['hashrate_at']=$next2->format(DATE_ATOM);
hache_salad_history_append([$group],$next2);
$report=hache_salad_history_report(7,$time->modify('+30 minutes'));
$r=$report['rows'][0]??[];
check_history($r['samples']===3 && $r['max_active']===1,'Recuento histórico incorrecto');
check_history($r['monitored_minutes']===10.0 && $r['average_ths']===80.0, 'Media o tiempo incorrecto');
check_history(abs($r['capacity_ths_hours']-13.33)<0.01,'Capacidad estimada incorrecta');

// Cambio de nodo y pérdida de datos: no integrar por encima de las discontinuidades.
$group['instances'][0]['id']='gpu-b';
$group['instances'][0]['update_time']=$time->modify('+15 minutes')->format(DATE_ATOM);
$four=$time->modify('+15 minutes');
$group['metrics']['last_log_at']=$four->format(DATE_ATOM);
$group['metrics']['hashrate_at']=$four->format(DATE_ATOM);
hache_salad_history_append([$group],$four);
$stale=$group;
$stale['stale']=true;
$five=$time->modify('+20 minutes');
$stale['metrics']['last_log_at']=$five->format(DATE_ATOM);
$stale['metrics']['hashrate_at']=$five->format(DATE_ATOM);
hache_salad_history_append([$stale],$five);
$six=$time->modify('+25 minutes');
$group['metrics']['last_log_at']=$six->format(DATE_ATOM);
$group['metrics']['hashrate_at']=$six->format(DATE_ATOM);
hache_salad_history_append([$group],$six);
$report=hache_salad_history_report(7,$time->modify('+30 minutes'));
check_history($report['rows'][0]['monitored_minutes']===10.0, 'Se integró una brecha de GPU o datos stale');

// Grupos multirréplica: se registra el evento, NO se duplica un hashrate grupal.
$multi=$group;
$multi['group']='prl-lowest-dual';
$multi['instances'][]=['id'=>'gpu-c','ready'=>true,'started'=>true,'state'=>'running'];
$rows=hache_salad_history_rows([$multi],$six);
check_history($rows[0]['active']===2 && $rows[0]['hashrate_ths']===null, 'Hashrate de varias GPU atribuido sin base');
check_history($rows[0]['reported_ths_unattributed']===80.0, 'No se conservó la lectura ambigua');
hache_salad_history_append([$multi],$six);
$report=hache_salad_history_report(7,$time->modify('+30 minutes'));
$dual=array_values(array_filter($report['rows'],static fn(array $x)=>$x['group']==='prl-lowest-dual'));
check_history(count($dual)===1 && $dual[0]['ambiguous_samples']===1,'Grupo con 2 réplicas invisible');


// P1: dos sondeos con el mismo log no deben sumar dos periodos.
$repeated=$group;
$repeated['group']='prl-lowest-duplicate';
$repeated['instances'][0]['update_time']=$time->modify('-1 minute')->format(DATE_ATOM);
$repeated['metrics']['hashrate_at']=$time->format(DATE_ATOM);
$repeated['metrics']['last_log_at']=$time->format(DATE_ATOM);
hache_salad_history_append([$repeated],$time);
$repeated['metrics']['last_log_at']=$time->modify('+5 minutes')->format(DATE_ATOM);
// Fuente GPU no cambió; la línea más nueva pertenece a otro log.
hache_salad_history_append([$repeated],$time->modify('+5 minutes'));
$repeated['metrics']['hashrate_at']=$time->modify('+10 minutes')->format(DATE_ATOM);
$repeated['metrics']['last_log_at']=$repeated['metrics']['hashrate_at'];
hache_salad_history_append([$repeated],$time->modify('+10 minutes'));
$dup=hache_salad_history_report(7,$time->modify('+30 minutes'));
$dr=array_values(array_filter($dup['rows'],static fn(array $x)=>$x['group']==='prl-lowest-duplicate'));
check_history(count($dr)===1 && $dr[0]['monitored_minutes']===10.0, 'Log repetido infló o eliminó cobertura');
// P2: no atribuir lectura anterior a la fecha del nuevo nodo.
$oldNode=$group;
$oldNode['instances'][0]['update_time']=$time->modify('+30 minutes')->format(DATE_ATOM);
$oldNode['metrics']['hashrate_at']=$time->modify('+25 minutes')->format(DATE_ATOM);
check_history(hache_salad_history_rows([$oldNode],$time->modify('+31 minutes'))[0]['hashrate_ths']===null, 'Hash anterior se atribuyó a nodo recién iniciado');

// Un log viejo no equivale a una medición nueva y no debe contar tasa.
$old=$group;
$old['metrics']['last_log_at']=$time->modify('-2 hours')->format(DATE_ATOM);
check_history(hache_salad_history_rows([$old],$six)[0]['hashrate_ths']===null, 'Hashrate antiguo tomado como nuevo');
$medium=$group;
$medium['group']='prl-medium-5060ti';
$medium['priority']='medium';
check_history(hache_salad_history_rows([$medium],$six)[0]['priority']==='medium', 'Medium excluida');

// Los archivos deben ser privados.
$files=glob($dir.'/*.jsonl');
check_history(count($files)===1, 'Registro diario no escrito');
check_history((fileperms($files[0]) & 0777)===0600, 'Histórico sin permisos privados');
foreach ($files as $file) unlink($file);
rmdir($dir);
putenv('SALAD_MONITOR_HISTORY_DIR');
echo "SALAD_MONITOR_HISTORY_REGRESSION_OK\n";
