<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
$config=require __DIR__.'/../config/database.php';require_once __DIR__.'/../config/auth.php';auth_require(['ADMIN']);require_once __DIR__.'/../config/salad-monitor.php';
function salad_out(array $data,int $status=200): never {http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
try{
    $snapshot=hache_salad_monitor_read_snapshot();
    if($snapshot===null)salad_out(['ok'=>false,'error'=>'El monitor todavía no tiene un snapshot disponible.'],503);
    $age=hache_salad_monitor_snapshot_age_seconds($snapshot['observed_at']);
    salad_out(['ok'=>true,'source'=>'snapshot','observed_at'=>$snapshot['observed_at'],'age_seconds'=>$age,'stale'=>$age===null||$age>660,'groups'=>$snapshot['groups']]);
}catch(Throwable $e){
    error_log('[salad-monitor] '.$e->getMessage());
    salad_out(['ok'=>false,'error'=>'No se pudo consultar el monitor de Salad.'],503);
}
