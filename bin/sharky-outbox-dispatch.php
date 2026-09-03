<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-runtime.php';
require_once __DIR__.'/../config/sharky-outbox.php';

if(PHP_SAPI!=='cli'){fwrite(STDERR,"CLI only\n");exit(2);}

function hache_sharky_outbox_meta_send(array $payload): bool
{
    $token=hache_sharky_orchestrator_secret('WHATSAPP_ACCESS_TOKEN');
    $phoneId=hache_sharky_orchestrator_secret('WHATSAPP_PHONE_NUMBER_ID');
    $version=hache_sharky_orchestrator_secret('WHATSAPP_GRAPH_VERSION');
    if(!preg_match('/^v\d+\.\d+$/',$version))$version='v26.0';
    if($token===''||$phoneId==='')return false;
    $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($json===false)return false;
    $ch=curl_init('https://graph.facebook.com/'.rawurlencode($version).'/'.rawurlencode($phoneId).'/messages');
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$token],CURLOPT_POSTFIELDS=>$json]);
    $response=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);
    return $response!==false&&$error===''&&$status>=200&&$status<300;
}

try{
    if(strlen(hache_sharky_orchestrator_secret('SHARKY_CONTACT_HASH_KEY'))<32)throw new RuntimeException('SHARKY_CONTACT_HASH_KEY missing');
    $pdo=hache_sharky_pdo();if(!$pdo instanceof PDO)throw new RuntimeException('Database unavailable');
    if(!hache_sharky_orchestrator_store_ready($pdo))throw new RuntimeException('Sharky 2.0 migration incomplete');
    $stats=hache_sharky_outbox_dispatch($pdo,'hache_sharky_outbox_meta_send',50);
    fwrite(STDOUT,json_encode($stats,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit($stats['dead']>0?1:0);
}catch(Throwable $e){fwrite(STDERR,'Sharky outbox: '.$e->getMessage().PHP_EOL);exit(1);}
