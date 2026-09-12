<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-runtime.php';
require_once __DIR__.'/../config/sharky-learning.php';

if(PHP_SAPI!=='cli'){fwrite(STDERR,"CLI only\n");exit(2);}

try{
    $pdo=hache_sharky_pdo();
    if(!$pdo instanceof PDO)throw new RuntimeException('Database unavailable');
    if(!hache_sharky_learning_schema_ready($pdo))throw new RuntimeException('Learning schema missing');
    $mode=$argv[1]??'--pending';
    if($mode==='--pending'){
        $limit=isset($argv[2])?(int)$argv[2]:10;
        echo json_encode(['ok'=>true,'cases'=>hache_sharky_learning_export($pdo,$limit)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
        exit(0);
    }
    if($mode==='--summary'){
        echo json_encode(['ok'=>true,'summary'=>hache_sharky_learning_summary($pdo)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
        exit(0);
    }
    if($mode==='--verdict'){
        $raw=stream_get_contents(STDIN);
        $input=json_decode((string)$raw,true);
        if(!is_array($input))throw new InvalidArgumentException('Verdict JSON required on stdin');
        echo json_encode(hache_sharky_learning_apply_verdict($pdo,$input),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
        exit(0);
    }
    throw new InvalidArgumentException('Unknown mode');
}catch(Throwable $e){
    fwrite(STDERR,'Sharky learning: '.$e->getMessage().PHP_EOL);exit(1);
}
