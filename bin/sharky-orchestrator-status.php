<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-activation.php';

if(PHP_SAPI!=='cli'){fwrite(STDERR,"CLI only\n");exit(2);}

try{
    /** @var PDO $pdo */
    $pdo=require __DIR__.'/../config/pdo.php';
    $schema=hache_sharky_activation_schema_report($pdo);
    $data=($schema['ok']??false)?hache_sharky_activation_data_report($pdo):[];
    $rawFlag=hache_sharky_orchestrator_secret('SHARKY_ORCHESTRATOR_LAB_ENABLED');
    $flag=in_array($rawFlag,['0','1'],true)?$rawFlag:($rawFlag===''?'MISSING':'INVALID');
    $report=[
        'ok'=>($schema['ok']??false)===true&&in_array($rawFlag,['0','1'],true),
        'feature_flag'=>$flag,
        'schema'=>$schema,
        'queues'=>$data,
    ];
    fwrite(STDOUT,json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit($report['ok']?0:1);
}catch(Throwable $e){
    fwrite(STDERR,'Sharky status: '.$e->getMessage().PHP_EOL);exit(1);
}
