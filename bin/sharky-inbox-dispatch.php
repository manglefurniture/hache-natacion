<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-runtime.php';
require_once __DIR__.'/../config/sharky-inbox.php';
require_once __DIR__.'/../config/sharky-lab-worker.php';

if(PHP_SAPI!=='cli'){fwrite(STDERR,"CLI only\n");exit(2);}

try{
    if(hache_sharky_orchestrator_secret('SHARKY_ORCHESTRATOR_LAB_ENABLED')!=='1'){
        fwrite(STDOUT,"{\"disabled\":true,\"worker\":\"inbox\"}\n");exit(0);
    }
    if(strlen(hache_sharky_orchestrator_secret('SHARKY_CONTACT_HASH_KEY'))<32)throw new RuntimeException('SHARKY_CONTACT_HASH_KEY missing');
    if(strlen(hache_sharky_orchestrator_secret('SHARKY_STATE_ENCRYPTION_KEY'))<32)throw new RuntimeException('SHARKY_STATE_ENCRYPTION_KEY missing');
    $pdo=hache_sharky_pdo();if(!$pdo instanceof PDO)throw new RuntimeException('Database unavailable');
    if(!hache_sharky_orchestrator_store_ready($pdo))throw new RuntimeException('Sharky 2.0 migration incomplete');
    $business=hache_sharky_business_values($pdo);
    $minAge=hache_sharky_config_int($business,'sharky_edad_minima',12,1,99);
    $threshold=hache_sharky_config_int($business,'sharky_escalado_intentos',2,1,5);
    $processor=static fn(array $event):bool=>hache_sharky_lab_process_event($pdo,$event,$business,$minAge,$threshold);
    $stats=hache_sharky_inbox_dispatch($pdo,$processor,50);
    hache_sharky_outbox_dispatch($pdo,'hache_sharky_lab_send',50);
    fwrite(STDOUT,json_encode($stats,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit($stats['dead']>0?1:0);
}catch(Throwable $e){fwrite(STDERR,'Sharky inbox: '.$e->getMessage().PHP_EOL);exit(1);}
