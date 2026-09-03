<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-activation.php';

if(PHP_SAPI!=='cli'){fwrite(STDERR,"CLI only\n");exit(2);}

$root=dirname(__DIR__);
$migrations=[
    $root.'/database/migrations/20260902_sharky_orchestrator.sql',
    $root.'/database/migrations/20260903_sharky_orchestrator_hardening.sql',
];

try{
    $flag=hache_sharky_orchestrator_secret('SHARKY_ORCHESTRATOR_LAB_ENABLED');
    if($flag==='1')throw new RuntimeException('Refusing migration while SHARKY_ORCHESTRATOR_LAB_ENABLED=1. Set it to 0 first.');
    /** @var PDO $pdo */
    $pdo=require $root.'/config/pdo.php';
    $lock=(int)$pdo->query("SELECT GET_LOCK('hache_sharky_orchestrator_migration',10)")->fetchColumn();
    if($lock!==1)throw new RuntimeException('Could not acquire Sharky migration lock.');
    try{
        foreach($migrations as $file){
            if(!is_readable($file))throw new RuntimeException('Missing migration: '.basename($file));
            $sql=file_get_contents($file);if(!is_string($sql))throw new RuntimeException('Unable to read migration: '.basename($file));
            $statements=hache_sharky_activation_split_sql($sql);
            fwrite(STDOUT,'Applying '.basename($file).' ('.count($statements).' statements)'.PHP_EOL);
            foreach($statements as $index=>$statement){
                try{$pdo->exec($statement);}
                catch(Throwable $e){throw new RuntimeException(basename($file).' statement '.($index+1).' failed',0,$e);}
            }
        }
        $schema=hache_sharky_activation_schema_report($pdo);
        if(($schema['ok']??false)!==true){
            fwrite(STDERR,'Schema verification failed: '.json_encode($schema,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL);
            exit(1);
        }
        fwrite(STDOUT,"SHARKY_MIGRATION_OK\n");
    }finally{
        try{$pdo->query("SELECT RELEASE_LOCK('hache_sharky_orchestrator_migration')");}catch(Throwable $e){}
    }
}catch(Throwable $e){
    fwrite(STDERR,'Sharky migration: '.$e->getMessage().PHP_EOL);
    if($e->getPrevious())fwrite(STDERR,'Cause: '.$e->getPrevious()->getMessage().PHP_EOL);
    exit(1);
}
