<?php
declare(strict_types=1);
require_once __DIR__.'/../config/salad-mining-pool.php';
function check_prl(bool $ok, string $m): void { if(!$ok) throw new RuntimeException($m); }
$now=new DateTimeImmutable('2026-10-10T20:00:00Z');
$balance=['confirmed'=>3.34907381,'unconfirmed'=>2.44355281,'total'=>5.79262662,'last_active'=>1791666378000];
$rewards=[
 ['timestamp'=>1791504000000,'reward'=>11.2],
 ['timestamp'=>1791590400000,'reward'=>22.3],
 ['timestamp'=>1791676800000,'reward'=>0.0],
];
$stats=['reward'=>['week'=>45.5,'month'=>45.5],'paid'=>75.05,'unpaid'=>3.34];
$s=hache_prl_pool_normalize($balance,$rewards,$stats,$now);
check_prl($s['confirmed_prl']===3.34907381 && $s['unconfirmed_prl']===2.44355281, 'Saldo confirmado o pendiente incorrecto');
check_prl($s['paid_prl']===75.05 && $s['reward_week_prl']===45.5, 'Pagos o recompensa semanal incorrectos');
check_prl(count($s['rewards_daily_utc'])===3 && $s['rewards_daily_utc'][1]['prl']===22.3,'Histórico real incorrecto');
check_prl($s['wallet_suffix']===substr(hache_prl_pool_wallet(),-6),'No revelar dirección completa en API');
check_prl(!array_key_exists('wallet',$s), 'La dirección completa no debe viajar al navegador');
check_prl(str_contains($s['source'],'PRL'), 'Unidades de pool ambiguas');
$bad=$balance;$bad['confirmed']=-1;$failed=false;
try { hache_prl_pool_normalize($bad,$rewards,$stats,$now); } catch(RuntimeException) { $failed=true; }
check_prl($failed,'Se aceptó una recompensa negativa');
$tmp=sys_get_temp_dir().'/prl-pool-'.bin2hex(random_bytes(6)).'.json';
putenv('SALAD_PRL_POOL_SNAPSHOT_FILE='.$tmp);
check_prl(hache_prl_pool_read()===null, 'No debe inventar snapshot inexistente');
file_put_contents($tmp,json_encode($s,JSON_THROW_ON_ERROR));
check_prl(hache_prl_pool_read()['confirmed_prl']===3.34907381,'Snapshot no disponible');
unlink($tmp);putenv('SALAD_PRL_POOL_SNAPSHOT_FILE');
echo "SALAD_PRL_POOL_REGRESSION_OK\n";
