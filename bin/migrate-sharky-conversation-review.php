<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-activation.php';
require_once __DIR__.'/../config/sharky-conversation-review.php';

if(PHP_SAPI!=='cli'){fwrite(STDERR,"CLI only\n");exit(2);}

$root=dirname(__DIR__);$file=$root.'/database/migrations/20260912_sharky_conversation_review.sql';
try{
    /** @var PDO $pdo */
    $pdo=require $root.'/config/pdo.php';
    $lock=(int)$pdo->query("SELECT GET_LOCK('hache_sharky_conversation_review_migration',10)")->fetchColumn();
    if($lock!==1)throw new RuntimeException('Could not acquire conversation-review migration lock.');
    try{
        if(!is_readable($file))throw new RuntimeException('Conversation-review migration missing.');
        $sql=file_get_contents($file);if(!is_string($sql))throw new RuntimeException('Unable to read conversation-review migration.');
        $statements=hache_sharky_activation_split_sql($sql);
        foreach($statements as $index=>$statement){
            try{$pdo->exec($statement);}catch(Throwable $e){throw new RuntimeException('Conversation-review statement '.($index+1).' failed',0,$e);}
        }
        if(!hache_sharky_conversation_review_schema_ready($pdo))throw new RuntimeException('Conversation-review schema verification failed.');
        fwrite(STDOUT,"SHARKY_CONVERSATION_REVIEW_MIGRATION_OK\n");
    }finally{
        try{$pdo->query("SELECT RELEASE_LOCK('hache_sharky_conversation_review_migration')");}catch(Throwable $e){}
    }
}catch(Throwable $e){
    fwrite(STDERR,'Sharky conversation-review migration: '.$e->getMessage().PHP_EOL);
    if($e->getPrevious())fwrite(STDERR,'Cause: '.$e->getPrevious()->getMessage().PHP_EOL);
    exit(1);
}
