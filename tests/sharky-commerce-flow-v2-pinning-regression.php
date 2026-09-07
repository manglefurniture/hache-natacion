<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-commerce-flow-v2.php';

function flowv2_expect(bool $ok,string $message): void
{
    if(!$ok){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}
}

$specs=hache_sharky_commerce_flow_v2_specs();
flowv2_expect(count($specs)===4,'v2 must define all four commerce resources.');
foreach($specs as $key=>$spec){
    $pin=(string)($spec['pin_env']??'');
    $runtime=(string)($spec['runtime_env']??'');
    flowv2_expect($pin!==''&&$runtime!=='','v2 spec must separate pin/runtime env for '.$key.'.');
    flowv2_expect($pin!==$runtime,'v2 pin must never reuse legacy v1 env for '.$key.'.');
    flowv2_expect(str_ends_with($pin,'_V2_ID'),'v2 pin must be explicitly versioned for '.$key.'.');
}

// Simulate a production deployment where only legacy v1 IDs are pinned. A fresh
// v2 bootstrap must not consider them ready and must mask them for current-process
// outbound resolution until a real v2 pin/cache exists.
$legacy=[
    'WHATSAPP_ENROLLMENT_FLOW_ID'=>'111111',
    'WHATSAPP_PAYMENT_METHOD_FLOW_ID'=>'222222',
    'WHATSAPP_PAYMENT_TRANSFER_FLOW_ID'=>'333333',
    'WHATSAPP_PAYMENT_CARD_FLOW_ID'=>'444444',
];
foreach($legacy as $name=>$value)putenv($name.'='.$value);
foreach($specs as $spec)putenv((string)$spec['pin_env'].'=');
foreach(array_keys($specs) as $key){
    $cache=hache_sharky_commerce_flow_v2_cache_path((string)$key);
    if($cache!==''&&is_file($cache))@unlink($cache);
}

$ready=hache_sharky_commerce_flow_v2_bootstrap();
flowv2_expect($ready===[],'legacy v1 pins must not make any corrected v2 Flow ready.');
foreach($specs as $key=>$spec){
    $runtime=(string)$spec['runtime_env'];
    $numeric=preg_replace('/\D+/','',hache_sharky_whatsapp_flow_secret($runtime))?:'';
    flowv2_expect($numeric==='','legacy v1 runtime ID must be masked while v2 is unavailable for '.$key.'.');
}

$env=(string)file_get_contents(__DIR__.'/../.env.example');
foreach($specs as $key=>$spec){
    flowv2_expect(str_contains($env,(string)$spec['pin_env'].'='),'env example must declare distinct v2 pin for '.$key.'.');
}

fwrite(STDOUT,"SHARKY_COMMERCE_FLOW_V2_PINNING_OK\n");
