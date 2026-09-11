<?php

declare(strict_types=1);

require_once __DIR__.'/intensive-partial-payment-triggers.php';
require_once __DIR__.'/fix-monteverde-intensive-schedule-catalog.php';
$config=require __DIR__.'/../config/database.php';
$pdo=new PDO("mysql:host={$config['host']};port=".((int)($config['port']??3306)).";dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
if(hache_intensive_partial_payment_triggers_ready($pdo)){
    fwrite(STDOUT,"INTENSIVE_PARTIAL_PAYMENTS_ALREADY_READY\n");
}else{
    hache_intensive_partial_payment_apply($pdo);
    fwrite(STDOUT,"INTENSIVE_PARTIAL_PAYMENTS_MIGRATION_OK\n");
}
$catalogResult=hache_fix_monteverde_intensive_schedule_catalog($pdo);
fwrite(STDOUT,"MONTEVERDE_INTENSIVE_SCHEDULE_CATALOG_{$catalogResult}\n");
