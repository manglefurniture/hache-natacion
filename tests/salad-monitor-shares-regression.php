<?php
declare(strict_types=1);
require_once __DIR__.'/../config/salad-monitor-history.php';
require_once __DIR__.'/../config/salad-monitor-shares.php';
$dir=sys_get_temp_dir().'/salad-shares-'.bin2hex(random_bytes(5));
putenv('SALAD_MONITOR_HISTORY_DIR='.$dir);
$start=new DateTimeImmutable('2026-10-11T12:00:00Z');
$g=['group'=>'gpu-test','priority'=>'low','state'=>'running','stale'=>false,
'instances'=>[['id'=>'node1','update_time'=>'2026-10-11T11:55:00Z','state'=>'running','ready'=>true,'started'=>true]],
'metrics'=>['gpu'=>'RTX test','hashrate_ths'=>140,'hashrate_at'=>$start->format(DATE_ATOM),'shares'=>['accepted'=>10]]];
$append=function($minute,$accepted,$id='node1',$stale=false)use(&$g,$start){
 $t=$start->modify("+$minute minutes");
 $g['instances'][0]['id']=$id;
 $g['instances'][0]['update_time']=$id==='node1'?'2026-10-11T11:55:00Z':$t->format(DATE_ATOM);
 $g['metrics']['hashrate_at']=$t->format(DATE_ATOM);
 $g['metrics']['shares']['accepted']=$accepted;$g['stale']=$stale;
 hache_salad_history_append([$g],$t);
};
$append(0,10);$append(5,14);$append(10,18);
$r=hache_salad_shares_report(1,$start->modify('+11 minutes'));
$a=$r['groups'][0]??[];
if(($a['accepted_observed']??null)!==8 || ($a['measured_intervals']??null)!==2 || ($a['last_5m']??null)!==4)throw new RuntimeException('Delta incorrecta');
$append(15,2,'node2');$append(20,5,'node2');
$r=hache_salad_shares_report(1,$start->modify('+21 minutes'));
if($r['groups'][0]['accepted_observed']!==11)throw new RuntimeException('Se contó reinicio como shares');
$append(25,8,'node2',true);$append(30,12,'node2');
$r=hache_salad_shares_report(1,$start->modify('+31 minutes'));
if($r['groups'][0]['accepted_observed']!==11)throw new RuntimeException('Se incluyó lectura stale');
foreach(glob($dir.'/*.jsonl') as $file)unlink($file);
rmdir($dir);putenv('SALAD_MONITOR_HISTORY_DIR');
echo "SALAD_SHARES_HISTORY_OK\n";
