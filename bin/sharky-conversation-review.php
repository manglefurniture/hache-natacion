<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-conversation-review.php';

if(PHP_SAPI!=='cli'){fwrite(STDERR,"CLI only\n");exit(2);}

try{
    /** @var PDO $pdo */
    $pdo=require __DIR__.'/../config/pdo.php';
    $force=in_array('--force',$argv,true);
    $result=hache_sharky_conversation_review_maybe_run($pdo,$force);
    fwrite(STDOUT,json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(($result['status']??'')==='error'?1:0);
}catch(Throwable $e){
    fwrite(STDERR,'Sharky conversation review: '.$e->getMessage().PHP_EOL);exit(1);
}
