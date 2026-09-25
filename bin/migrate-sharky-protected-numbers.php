<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
require_once __DIR__.'/../config/sharky-protected-numbers.php';
try{
    $pdo=require __DIR__.'/../config/pdo.php';
    $sql=file_get_contents(__DIR__.'/../database/migrations/20260925_sharky_protected_numbers.sql');
    if(!is_string($sql))throw new RuntimeException('Migration missing');
    $pdo->exec($sql);
    if(!hache_sharky_protected_schema_ready($pdo))throw new RuntimeException('Table verification failed');
    echo "SHARKY_PROTECTED_NUMBERS_MIGRATION_OK\n";
}catch(Throwable $e){fwrite(STDERR,'SHARKY_PROTECTED_NUMBERS_MIGRATION_FAILED: '.$e->getMessage().PHP_EOL);exit(1);}
